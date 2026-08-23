<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §10 -- guilds: founding one, finding one, and running one.
 *
 * Every mutating route answers with the full player state like the rest of the
 * API, because guild membership changes what a character may do (§8.0's
 * legendary bench) and a partial patch would let the two disagree.
 */
class GuildController extends GameController
{
    /**
     * §10.0.1 -- who is recruiting.
     *
     * Closed guilds are not listed at all rather than listed and refused: a
     * roster you can see and cannot join is a queue with extra steps.
     */
    public function index(Request $request): JsonResponse
    {
        $character = $this->character($request);

        return response()->json([
            'guilds' => $this->game->recruitingGuilds(),
            'mine' => $this->game->guildPayload($this->game->guildOf($character), true, true),
            // §10.0.1 -- what this character is already waiting on, so the
            // browse list can say "asked" rather than offering to ask again.
            'applied' => $this->game->pendingApplicationsOf($character),
            'cost' => Balance::GUILD_FOUNDING_COST,
            'flagSize' => Balance::GUILD_FLAG_SIZE,
        ]);
    }

    /** §10.0 -- found one. A city or a capital, and twenty thousand gold. */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.Balance::GUILD_NAME_MAX],
            'code' => ['required', 'string', 'max:'.Balance::GUILD_CODE_MAX],
            'description' => ['nullable', 'string', 'max:'.Balance::GUILD_DESCRIPTION_MAX],
            // Length-checked rather than parsed: a flag is exactly 1024 colors
            // and the service refuses anything that is not (§10.0.3).
            'flag' => ['nullable', 'string', 'max:8192'],
        ]);

        $guild = $this->game->foundGuild($character, $validated);

        return $this->respond(
            $character,
            $this->game->guildPayload($guild, true, true),
            "{$guild->name} has a hall.",
        );
    }

    /** §10.0.1 -- walk in. No application and no approval. */
    public function join(Request $request, string $guild): JsonResponse
    {
        $character = $this->character($request);
        ['guild' => $joined, 'applied' => $applied] = $this->game->joinGuild($character, (int) $guild);

        return $this->respond(
            $character,
            $this->game->guildPayload($applied ? null : $joined, true, true),
            $applied
                ? "{$joined->name} has your name down."
                : "You are one of {$joined->name} now.",
        );
    }

    /** §10.0.1 -- take your own name back off a list. */
    public function withdraw(Request $request, string $guild): JsonResponse
    {
        $character = $this->character($request);
        $this->game->withdrawApplication($character, (int) $guild);

        return $this->respond($character, null, 'Name withdrawn.');
    }

    /** §10.0.1 -- answer somebody. Owners and officers. */
    public function decide(Request $request, string $member): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate(['admit' => ['required', 'boolean']]);
        $this->game->decideApplication($character, (int) $member, (bool) $validated['admit']);

        return $this->respond(
            $character,
            $this->game->guildPayload($this->game->guildOf($character), true, true),
            $validated['admit'] ? 'They are in.' : 'Turned away.',
        );
    }

    /** §10.0.2 -- leave. The last owner hands over or disbands. */
    public function leave(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $this->game->leaveGuild($character);

        return $this->respond($character, null, 'You are on your own again.');
    }

    /** §10.0.3 -- description, flag, and whether the door is open. */
    public function update(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:'.Balance::GUILD_DESCRIPTION_MAX],
            'flag' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'recruitment' => ['sometimes', 'string', 'in:closed,open,approval'],
        ]);

        $guild = $this->game->updateGuild($character, $validated);

        return $this->respond($character, $this->game->guildPayload($guild, true, true));
    }

    /** §10.0.2 -- remove somebody. Owners and officers. */
    public function removeMember(Request $request, string $member): JsonResponse
    {
        $character = $this->character($request);
        $this->game->removeMember($character, (int) $member);

        return $this->respond(
            $character,
            $this->game->guildPayload($this->game->guildOf($character), true, true),
            'They are out.',
        );
    }

    /**
     * §10.5 -- put gold in the treasury. Anybody in the guild, no rank on it,
     * and it does not come back out.
     */
    public function donate(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'gold' => ['required', 'integer', 'min:1'],
        ]);

        $guild = $this->game->donateToGuild($character, (int) $validated['gold']);

        return $this->respond(
            $character,
            $this->game->guildPayload($guild, true, true),
            "{$validated['gold']}g to {$guild->name}.",
        );
    }

    /** §10.5 -- spend it on a facility level. Owner only. */
    public function upgrade(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'facility' => ['required', 'string', 'in:hall,bench'],
        ]);

        $guild = $this->game->upgradeGuildFacility($character, $validated['facility']);

        $said = $validated['facility'] === 'bench'
            ? "The bench reaches {$this->game->guildBenchReach($guild)}."
            : "The hall seats {$this->game->guildRosterCap($guild)}.";

        return $this->respond(
            $character,
            $this->game->guildPayload($guild, true, true),
            $said,
        );
    }

    /** §10.0.2 -- promote, demote, or hand the whole thing over. Owner only. */
    public function setRole(Request $request, string $member): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:owner,officer,member'],
        ]);

        $this->game->setMemberRole($character, (int) $member, $validated['role']);

        return $this->respond(
            $character,
            $this->game->guildPayload($this->game->guildOf($character), true, true),
        );
    }
}
