<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §2/§7 -- proving a wallet is controlled, one login at a time.
 *
 * The interesting tests here are not the happy path. They are the four ways the
 * scheme could be defeated if it were built the obvious way: quoting somebody
 * else's payment, quoting your own twice, quoting a payment made before anybody
 * asked for one, and paying the wrong amount to the wrong place.
 */
final class WaxLoginTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = 'prospector1';

    private const OTHER = 'rival.wam';

    protected function setUp(): void
    {
        parent::setUp();

        // The array store has the atomics this leans on (add/pull) and no
        // server. config/wax.php says Redis; nothing in the code cares which.
        config()->set('wax.store', 'array');
        config()->set('wax.account', 'shaf.wiz');
        config()->set('wax.fee', '0.00010000 WAX');
        config()->set('wax.window', 600);

        Cache::store('array')->clear();

        // Two things a browser does for free and the test client does not.
        //
        // Sanctum only starts a session for a request that came from the app's
        // own origin, so with no Referer every route here answers 401. And
        // postJson() sends no cookies at all unless credentials are asked for,
        // which would give each request in a test its own session -- exactly
        // the thing this flow is built to tell apart.
        $this->withHeader('Referer', config('app.url'));
        $this->withCredentials();
        $this->asBrowser();
    }

    private string $session = '';

    /** A fresh session cookie: a different browser, or the same one cleared. */
    private function asBrowser(): void
    {
        $this->session = Str::random(40);
        $this->withCookie(config('session.cookie'), $this->session);
    }

    public function test_a_paid_challenge_connects_the_wallet(): void
    {
        $challenge = $this->challenge(self::WALLET);

        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo']));

        $response = $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ]);

        $response->assertOk()->assertJsonPath('wallet', self::WALLET);
        $this->assertDatabaseHas('players', ['wallet' => self::WALLET]);
    }

    /**
     * The attack the memo exists to stop.
     *
     * Transfers are public, so an attacker can read a real login payment off
     * the chain -- sender, amount, transaction id, and the memo with it. What
     * they cannot have is the session that memo was issued to.
     */
    public function test_a_stranger_cannot_redeem_a_payment_they_watched(): void
    {
        $challenge = $this->challenge(self::WALLET);
        $transaction = $this->transfer(self::WALLET, $challenge['memo']);

        // A different browser entirely: everything about the payment is known
        // to it, and it claims the wallet that made it.
        $this->asBrowser();
        $this->fakeChain($transaction);

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'challenge_not_yours');

        $this->assertDatabaseMissing('players', ['wallet' => self::WALLET]);
    }

    /** A payment buys one login. The second time it is quoted, it is spent. */
    public function test_a_payment_cannot_be_used_twice(): void
    {
        $challenge = $this->challenge(self::WALLET);
        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo']));

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertOk();

        // Same session, same wallet, same payment -- and a fresh challenge, so
        // the only thing standing in the way is the spent transaction id.
        $this->challenge(self::WALLET);

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'transaction_spent');
    }

    /**
     * A transfer made before anybody asked for one carries no memo this server
     * issued, so it proves nothing -- which is what keeps ordinary traffic to
     * the fee account from being a pile of free logins.
     */
    public function test_a_payment_without_a_challenge_memo_is_refused(): void
    {
        $this->challenge(self::WALLET);
        $this->fakeChain($this->transfer(self::WALLET, 'thanks!'));

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'unknown_challenge');
    }

    /** The challenge names a wallet, and another wallet cannot pay it. */
    public function test_the_payment_must_come_from_the_wallet_being_claimed(): void
    {
        $challenge = $this->challenge(self::WALLET);
        $this->fakeChain($this->transfer(self::OTHER, $challenge['memo']));

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'wallet_mismatch');
    }

    /** Exact amount, and the fee account. Neither is a floor. */
    public function test_the_wrong_amount_or_the_wrong_account_is_not_a_login(): void
    {
        $challenge = $this->challenge(self::WALLET);

        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo'], quantity: '0.00001000 WAX'));
        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'not_a_login_payment');

        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo'], to: 'someone.wam'));
        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('b', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'not_a_login_payment');
    }

    /** Old payments do not accumulate into a stock of logins. */
    public function test_a_payment_older_than_the_window_is_refused(): void
    {
        $challenge = $this->challenge(self::WALLET);

        $this->fakeChain($this->transfer(
            self::WALLET,
            $challenge['memo'],
            at: time() - config('wax.window') - 60,
        ));

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'transaction_stale');
    }

    /**
     * A rejected attempt must burn neither the payment nor the challenge --
     * otherwise a typo, or a node blinking, would cost a fee to find out about.
     *
     * The same transfer is quoted twice here, which is the honest version of a
     * retry: a transaction id names one transaction, and re-reading it cannot
     * turn up different contents. What changes between the two attempts is the
     * wallet the caller claims.
     */
    public function test_a_refused_attempt_leaves_the_payment_spendable(): void
    {
        $challenge = $this->challenge(self::WALLET);
        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo']));

        $this->postJson('/api/auth/wax', [
            'wallet' => self::OTHER,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'wallet_mismatch');

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertOk();
    }

    /**
     * The griefing case, and the reason a challenge is spent only on success.
     *
     * An attacker who reads the memo off the chain loses -- but they must lose
     * QUIETLY. If their attempt consumed the challenge on the way out, anybody
     * could destroy a stranger's login by racing it, and the stranger would
     * have paid for the privilege.
     */
    public function test_a_stranger_losing_the_race_does_not_burn_the_challenge(): void
    {
        $payer = $this->session;
        $challenge = $this->challenge(self::WALLET);
        $transaction = $this->transfer(self::WALLET, $challenge['memo']);

        $this->asBrowser();
        $this->fakeChain($transaction);
        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'challenge_not_yours');

        // The payer, arriving second, is unaffected.
        $this->withCookie(config('session.cookie'), $payer);
        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertOk()->assertJsonPath('wallet', self::WALLET);
    }

    /** Logging in takes the wallet off whatever session was holding it. */
    public function test_connecting_moves_the_wallet_to_this_session(): void
    {
        Player::create(['wallet' => self::WALLET, 'session_id' => 'somewhere-else']);

        $challenge = $this->challenge(self::WALLET);
        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo']));

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertOk();

        $this->assertNotSame('somewhere-else', Player::where('wallet', self::WALLET)->value('session_id'));
        $this->assertSame(1, Player::where('wallet', self::WALLET)->count());
    }

    /**
     * Disconnecting ends the session and nothing else.
     *
     * The character is soulbound to the WALLET (§7), so it has to still be
     * there when the wallet comes back -- what is let go of here is the browser
     * holding it, which is why the row survives and only its session does not.
     */
    public function test_disconnecting_releases_the_session_but_keeps_the_character(): void
    {
        $challenge = $this->challenge(self::WALLET);
        $this->fakeChain($this->transfer(self::WALLET, $challenge['memo']));

        $login = $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ]);

        $login->assertOk();

        // Follow the new session cookie, which is what a browser does and what
        // the test client does not: logging in regenerates the session id (so a
        // cookie planted before the login is not logged in after it), and the
        // id this request carries has to be the one the player was bound to.
        $this->withCookie(
            config('session.cookie'),
            $login->getCookie(config('session.cookie'))->getValue(),
        );

        $this->deleteJson('/api/auth/wax')->assertOk();

        $this->assertDatabaseHas('players', ['wallet' => self::WALLET]);
        $this->assertNull(Player::where('wallet', self::WALLET)->value('session_id'));
        $this->getJson('/api/auth/wax')->assertOk()->assertJsonPath('wallet', null);
    }

    /** A node nobody can reach is "not yet", never "no". */
    public function test_an_unreachable_chain_does_not_deny_the_payment(): void
    {
        $this->challenge(self::WALLET);
        Http::fake(['*' => Http::response('', 502)]);

        $this->postJson('/api/auth/wax', [
            'wallet' => self::WALLET,
            'transaction_id' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('code', 'transaction_not_found');
    }

    /** @return array{nonce:string,memo:string} */
    private function challenge(string $wallet): array
    {
        $response = $this->postJson('/api/auth/wax/challenge', ['wallet' => $wallet]);

        $response->assertOk()
            ->assertJsonPath('account', 'shaf.wiz')
            ->assertJsonPath('fee', '0.00010000 WAX');

        return $response->json();
    }

    /** The Hyperion shape, which is what the configured endpoints speak. */
    private function transfer(
        string $from,
        string $memo,
        string $quantity = '0.00010000 WAX',
        string $to = 'shaf.wiz',
        ?int $at = null,
    ): array {
        return [
            'executed' => true,
            'trx_id' => str_repeat('a', 64),
            'lib' => 1000,
            'actions' => [[
                'block_num' => 999,
                '@timestamp' => gmdate('Y-m-d\TH:i:s.v', $at ?? time()),
                'act' => [
                    'account' => 'eosio.token',
                    'name' => 'transfer',
                    'data' => ['from' => $from, 'to' => $to, 'quantity' => $quantity, 'memo' => $memo],
                ],
            ]],
        ];
    }

    private function fakeChain(array $body): void
    {
        Http::fake(['*' => Http::response($body)]);
    }
}
