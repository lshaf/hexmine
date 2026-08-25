<?php

declare(strict_types=1);

namespace App\Wax;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Proof that a wallet is controlled, bought one login at a time.
 *
 * The shape is two steps, and the second cannot be reached without the first:
 *
 *   1. CHALLENGE. The caller names the wallet it intends to prove. The server
 *      mints a nonce, remembers which SESSION it was handed to, and says what
 *      to pay and what to write in the memo.
 *   2. REDEEM. The caller pays and hands back a transaction id. The server
 *      reads that transaction off the chain and checks it is the transfer the
 *      challenge asked for -- right token, right account, right amount, right
 *      memo, young enough, and from the wallet being claimed.
 *
 * THE NONCE IS THE WHOLE SECURITY ARGUMENT, and it is worth saying why, because
 * without it the scheme reads as though it works and does not.
 *
 * Transfers are public. Anybody can watch payments arrive at the fee account
 * and read the sender straight off the chain. A verifier that checked only
 * "this transaction is a 0.0001 WAX transfer from the wallet you claim" would
 * therefore accept a transaction it had nothing to do with: an attacker names
 * somebody else's wallet, quotes somebody else's transaction, and is logged in
 * as them. The payment proves a wallet signed SOMETHING, never that the person
 * asking is the one who signed it.
 *
 * The memo closes that, because it is a secret at the moment of signing: only
 * whoever was handed the challenge knows what to write, and the challenge is
 * bound to the session that asked for it. A watcher who sees the memo appear
 * on chain sees it too late -- it is already spent, and it was never theirs to
 * spend, since redeeming it requires the session it was issued to.
 *
 * Everything here lives in Redis and expires (§ config/wax.php). Nothing about
 * a login is worth keeping ten minutes after it happened.
 */
class WaxLogin
{
    public function __construct(private readonly Chain $chain) {}

    /**
     * @return array{nonce:string,memo:string,account:string,fee:string,contract:string,expires_in:int}
     */
    public function challenge(string $wallet, string $session): array
    {
        $nonce = Str::lower(Str::random(16));

        $this->store()->put($this->challengeKey($nonce), [
            'wallet' => $wallet,
            'session' => $session,
        ], $this->window());

        return [
            'nonce' => $nonce,
            'memo' => $this->memo($nonce),
            'account' => (string) config('wax.account'),
            'fee' => (string) config('wax.fee'),
            'contract' => (string) config('wax.token_contract'),
            'expires_in' => $this->window(),
        ];
    }

    /**
     * @return array{ok:true,wallet:string}|array{ok:false,code:string,message:string}
     */
    public function redeem(string $wallet, string $transactionId, string $session, int $now): array
    {
        $transactionId = Str::lower(trim($transactionId));

        if (! preg_match('/^[0-9a-f]{64}$/', $transactionId)) {
            return $this->no('bad_transaction_id', 'That is not a WAX transaction id.');
        }

        // The claim goes in BEFORE the chain is read, and that order is the
        // point: two requests quoting one transaction race here, where the
        // store settles it atomically, rather than at the end where both would
        // already have passed every check.
        //
        // A failed verification releases it again -- a fat-fingered id must not
        // burn a payment that was never examined.
        if (! $this->claim($transactionId)) {
            return $this->no('transaction_spent', 'That payment has already been used to log in.');
        }

        $verified = $this->verify($wallet, $transactionId, $session, $now);

        if ($verified['ok'] === false) {
            $this->release($transactionId);
        }

        return $verified;
    }

    /**
     * @return array{ok:true,wallet:string}|array{ok:false,code:string,message:string}
     */
    private function verify(string $wallet, string $transactionId, string $session, int $now): array
    {
        // The chain read is cached for the window, keyed by the transaction
        // rather than by the caller: what a transaction contains is a fact
        // about the chain, so two people asking cannot get two answers. It also
        // means a retry after a lost response costs no round trip.
        $transaction = $this->store()->remember(
            $this->transactionKey($transactionId),
            $this->window(),
            fn () => $this->chain->transaction($transactionId),
        );

        if ($transaction === null) {
            // Forget it: "no node answered" and "the transfer has not been
            // indexed yet" look identical from here, and both are states that
            // fix themselves in seconds. Caching a null would make a login fail
            // for ten minutes because a node blinked.
            $this->store()->forget($this->transactionKey($transactionId));

            return $this->no('transaction_not_found', 'That payment is not on chain yet. Give it a moment and try again.');
        }

        if (! $transaction['executed']) {
            return $this->no('transaction_failed', 'That transaction did not execute.');
        }

        if (config('wax.require_irreversible') && ! $transaction['irreversible']) {
            return $this->no('transaction_pending', 'That payment is not irreversible yet. Give it a few minutes.');
        }

        if ($transaction['block_time'] === null || $now - $transaction['block_time'] > $this->window()) {
            return $this->no('transaction_stale', 'That payment is too old to log in with. Pay again.');
        }

        $transfer = $this->transfer($transaction['actions']);

        if ($transfer === null) {
            return $this->no('not_a_login_payment', 'That transaction is not a login payment.');
        }

        $nonce = $this->nonce((string) ($transfer['memo'] ?? ''));
        $challenge = $nonce === null ? null : $this->store()->get($this->challengeKey($nonce));

        if (! is_array($challenge)) {
            return $this->no('unknown_challenge', 'That payment does not carry a login memo this server issued.');
        }

        // Both halves, and neither is redundant. The session check is what stops
        // a watcher redeeming a memo they read off the chain; the wallet check
        // is what stops a challenge for one wallet being paid by another.
        if (! hash_equals((string) $challenge['session'], $session)) {
            return $this->no('challenge_not_yours', 'That login memo was issued to a different session.');
        }

        if ((string) $transfer['from'] !== $challenge['wallet'] || $challenge['wallet'] !== $wallet) {
            return $this->no('wallet_mismatch', 'That payment came from a different wallet than the one being connected.');
        }

        // Spent on the way out, and ONLY on the way out. A challenge burned by a
        // failed attempt would be a griefing tool: an attacker who watched the
        // memo appear on chain could destroy the payer's challenge just by
        // losing a race against it, and the payer would be out a fee for a
        // transfer that was never anything but valid.
        //
        // Nothing is lost by leaving a failed one standing. The transaction is
        // spendable once whatever happens (see claim()), and the challenge
        // expires on its own inside the window.
        $this->store()->forget($this->challengeKey($nonce));

        return ['ok' => true, 'wallet' => $wallet];
    }

    /**
     * The one action that counts, and it is matched on all four fields at once.
     *
     * A transaction may carry several actions -- a wallet is free to bundle the
     * fee with anything else it likes -- so this looks for the transfer rather
     * than assuming the transaction is one.
     *
     * @param  list<array{account:string,name:string,data:array<string,mixed>}>  $actions
     * @return array<string,mixed>|null
     */
    private function transfer(array $actions): ?array
    {
        foreach ($actions as $action) {
            if ($action['account'] !== config('wax.token_contract') || $action['name'] !== 'transfer') {
                continue;
            }

            $data = $action['data'];

            if ((string) ($data['to'] ?? '') !== config('wax.account')) {
                continue;
            }

            // Exact, not a floor. §config -- a floor would let one big transfer
            // stand in for every login it is large enough to cover.
            if (trim((string) ($data['quantity'] ?? '')) !== config('wax.fee')) {
                continue;
            }

            return $data;
        }

        return null;
    }

    /**
     * The memo the wallet is asked to sign, and the reading of it.
     *
     * Prefixed so a transfer that happens to carry a bare hex string is not
     * mistaken for a login, and so a human looking at their wallet history can
     * see what the payment was for.
     */
    private function memo(string $nonce): string
    {
        return 'hexmine login '.$nonce;
    }

    private function nonce(string $memo): ?string
    {
        return preg_match('/^hexmine login ([0-9a-z]{16})$/', trim($memo), $m) === 1 ? $m[1] : null;
    }

    /**
     * The lock outlives the acceptance window by the configured skew, never the
     * other way round: a transaction that came back up for reuse while still
     * young enough to be accepted would be exactly the replay this prevents.
     */
    private function claim(string $transactionId): bool
    {
        return $this->store()->add($this->spentKey($transactionId), true, $this->window() + (int) config('wax.skew'));
    }

    private function release(string $transactionId): void
    {
        $this->store()->forget($this->spentKey($transactionId));
    }

    private function window(): int
    {
        return (int) config('wax.window');
    }

    private function store(): Repository
    {
        return Cache::store(config('wax.store'));
    }

    private function challengeKey(string $nonce): string
    {
        return 'wax:challenge:'.$nonce;
    }

    private function spentKey(string $transactionId): string
    {
        return 'wax:spent:'.$transactionId;
    }

    private function transactionKey(string $transactionId): string
    {
        return 'wax:trx:'.$transactionId;
    }

    /** @return array{ok:false,code:string,message:string} */
    private function no(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
