<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Wax\WaxLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Wallet login, §2/§7.
 *
 * Outside ResolveCharacter, necessarily: this is what a caller does when they
 * have no character yet, and requiring one to reach it would be a door locked
 * from the inside.
 *
 * A session holds at most one wallet and a wallet at most one character (§7,
 * soulbound), so logging in is a rebinding rather than an accumulation -- the
 * session that was somebody else's is no longer, and the wallet that was on
 * another session comes over to this one.
 */
class AuthController extends Controller
{
    public function __construct(private readonly WaxLogin $login) {}

    /** Who this session is, if anybody. The client asks at boot. */
    public function show(Request $request): JsonResponse
    {
        $player = $this->sessionPlayer($request);

        return response()->json([
            'wallet' => $player?->wallet,
            // Derived, never its own setting. While the API mints a character
            // for any session that asks (game.auto_provision, the development
            // affordance §2 forbids in production), a door demanding a wallet
            // would be theatre -- the caller can walk in through the API and
            // get a character anyway. Two flags could disagree about that;
            // one cannot.
            'required' => ! config('game.auto_provision'),
            'account' => config('wax.account'),
            'fee' => config('wax.fee'),
            'contract' => config('wax.token_contract'),
            // The signing wallet needs these before it can ask anything else.
            'chain_id' => config('wax.chain_id'),
            'endpoint' => config('wax.client_endpoint'),
        ]);
    }

    /**
     * Step one: what to pay, and what to write on it.
     *
     * The nonce is bound to this session, which is the whole reason the flow
     * has two steps rather than one (see WaxLogin). It takes no input at all:
     * who is about to pay is not this server's question, because the payment
     * answers it.
     */
    public function challenge(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return $this->noSession();
        }

        // No wallet in, and none asked for. The transfer names its own signer.
        return response()->json($this->login->challenge($request->session()->getId()));
    }

    /** Step two: the payment, and the session it buys. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);

        if (! $request->hasSession()) {
            return $this->noSession();
        }

        $result = $this->login->redeem(
            $data['transaction_id'],
            $request->session()->getId(),
            time(),
        );

        if ($result['ok'] === false) {
            return response()->json([
                'message' => $result['message'],
                'code' => $result['code'],
            ], 422);
        }

        // Regenerate BEFORE binding. The proof was made against the old session
        // id and has just been spent; the id the player is bound to has to be
        // the one they leave here with, or a fixed session cookie planted before
        // login would still be logged in after it.
        $request->session()->regenerate();

        $player = $this->bind($result['wallet'], $request->session()->getId());

        return response()->json([
            'wallet' => $player->wallet,
            'message' => 'Wallet connected.',
        ]);
    }

    /** Letting go of the wallet, not of the character it owns. */
    public function destroy(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            Player::where('session_id', $request->session()->getId())->update(['session_id' => null]);
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Wallet disconnected.']);
    }

    /**
     * One session, one wallet; one wallet, one session.
     *
     * Both directions are cleared before the binding is written, so signing in
     * on a second device does not leave the first one holding the same
     * character, and a session that had been standing in for another wallet
     * (§ ResolveCharacter's dev path) lets go of it here.
     */
    private function bind(string $wallet, string $session): Player
    {
        return DB::transaction(function () use ($wallet, $session) {
            Player::where('session_id', $session)->update(['session_id' => null]);

            $player = Player::firstOrNew(['wallet' => $wallet]);
            $player->session_id = $session;
            $player->save();

            return $player;
        });
    }

    private function sessionPlayer(Request $request): ?Player
    {
        if (! $request->hasSession()) {
            return null;
        }

        return Player::where('session_id', $request->session()->getId())->first();
    }

    private function noSession(): JsonResponse
    {
        return response()->json([
            'message' => 'No session. This API is cookie-authenticated and must be called from the app origin.',
            'code' => 'no_session',
        ], 401);
    }
}
