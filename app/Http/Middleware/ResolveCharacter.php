<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Game\GameService;
use App\Models\Character;
use App\Models\Player;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the acting character on the request.
 *
 * The design is wallet-bound (§7): one soulbound character per wallet, and §2
 * requires a wallet to have held a minimum balance for 7 continuous days before
 * it can act. Neither exists yet -- there is no wallet connect flow -- so while
 * `game.auto_provision` is on, the session stands in for the wallet and a
 * character is minted on first contact.
 *
 * That flag MUST be off in production: with it on, anyone can mint unlimited
 * characters by clearing cookies, which is precisely the sybil vector §2 exists
 * to close. The seam is here and in Player::isEligible() -- when wallets land,
 * replace the session lookup and nothing downstream changes.
 */
class ResolveCharacter
{
    public function __construct(private readonly GameService $game) {}

    public function handle(Request $request, Closure $next): Response
    {
        $player = $this->resolvePlayer($request);

        if ($player === null) {
            return response()->json([
                'message' => $request->hasSession()
                    ? 'No character for this session. Connect a wallet first.'
                    : 'No session. This API is cookie-authenticated and must be called from the app origin.',
                'code' => $request->hasSession() ? 'no_character' : 'no_session',
            ], 401);
        }

        if (! $player->isEligible($this->game->now())) {
            return response()->json([
                'message' => 'This wallet is not eligible yet. It must hold a minimum balance for 7 continuous days.',
                'code' => 'wallet_not_eligible',
            ], 403);
        }

        $character = $player->character ?? $this->game->createCharacter($player);

        $request->attributes->set('character', $character);

        return $next($request);
    }

    private function resolvePlayer(Request $request): ?Player
    {
        // Sanctum only starts a session for requests from a stateful domain, so
        // a caller with no Origin/Referer (curl, a misconfigured host) arrives
        // here session-less. Say so plainly instead of throwing.
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        $player = Player::where('session_id', $sessionId)->first();
        if ($player !== null) {
            return $player;
        }

        if (! config('game.auto_provision')) {
            return null;
        }

        // Development stand-in for a wallet address. Derived from the session so
        // a reload keeps the same character.
        return Player::create([
            'wallet' => '0x'.substr(hash('sha256', $sessionId), 0, 40),
            'session_id' => $sessionId,
            'eligible_since' => null,
        ]);
    }
}
