<?php

declare(strict_types=1);

namespace App\Game;

use App\Models\Character;
use App\Models\DungeonClear;
use App\Models\DungeonMember;
use App\Models\DungeonSession;
use Illuminate\Support\Facades\DB;

/**
 * §9.6 -- opening a dungeon, walking one, and getting out of it.
 *
 * The floor itself is `Dungeons`, which is pure arithmetic over a seed. This is
 * everything that has to be written down: who is in, where they are standing,
 * what has fallen, and the three gates -- the mouth, the roster lock, and the
 * six kills a floor costs.
 *
 * **Nothing here rebuilds a fighter.** The kit, the pair, the pool and the armed
 * skills all come off `GameService::combatProfile()`, and the wear comes off the
 * same two methods a road fight uses. A dungeon with its own combat arithmetic
 * would be a second opinion about what a character is worth.
 */
final class DungeonService
{
    /** Unambiguous in a shouted or typed code: no O/0, no I/1. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private GameService $game) {}

    // -------------------------------------------------------------- opening

    /**
     * §9.6.1 -- open a session at a mouth, and be its first member.
     *
     * Standing at the mouth is the whole of the authority here: §10.0.4's rule
     * that the verbs happen at the place, and the reason a shareable code is
     * safe to shout -- it lets somebody in, it does not carry them there.
     */
    public function open(Character $character, string $dungeon, string $category, string $difficulty): DungeonSession
    {
        if (! in_array($category, Dungeons::CATEGORIES, true)) {
            throw new GameException('No such contract.');
        }

        if (! in_array($difficulty, Dungeons::DIFFICULTIES, true)) {
            throw new GameException('No such contract.');
        }

        return DB::transaction(function () use ($character, $dungeon, $category, $difficulty) {
            $now = $this->game->now();

            $this->requireMouth($character, $dungeon);
            $this->requireFree($character, $now);
            $this->requireWeeklyAllowance($character, $now);

            $session = DungeonSession::create([
                'code' => $this->freshCode(),
                'secret' => Dungeons::newSecret(),
                'dungeon' => $dungeon,
                'category' => $category,
                'difficulty' => $difficulty,
                'owner_character_id' => $character->id,
                'created_at_ms' => $now,
                'expires_at_ms' => $now + Balance::scaled(Balance::DUNGEON_SESSION_MS),
            ]);

            DungeonMember::create([
                'dungeon_session_id' => $session->id,
                'character_id' => $character->id,
            ]);

            return $session;
        });
    }

    /**
     * §9.6.1 -- walk in on somebody else's code.
     *
     * Two gates and they are different questions: the code says you were
     * invited, and standing at the mouth says you made the walk. Neither
     * substitutes for the other.
     */
    public function join(Character $character, string $code): DungeonSession
    {
        return DB::transaction(function () use ($character, $code) {
            $now = $this->game->now();

            $session = DungeonSession::where('code', strtoupper(trim($code)))->lockForUpdate()->first();
            if ($session === null) {
                throw new GameException('No session with that code.');
            }

            if (! $session->isLive($now)) {
                throw new GameException('That session has closed.');
            }

            $this->requireMouth($character, $session->dungeon);
            $this->requireFree($character, $now);
            $this->requireWeeklyAllowance($character, $now);

            if ($session->locked_at_ms !== null) {
                // §9.6.1 -- the carry. Clear to floor nine, let a fresh wallet in
                // on the code, and it collects the floor-ten roll having fought
                // nothing. The roster is what it is once anybody has descended.
                throw new GameException('They have already gone down. The roster is closed.');
            }

            if ($session->members()->count() >= Balance::DUNGEON_PARTY_MAX) {
                throw new GameException('That session is full.');
            }

            DungeonMember::create([
                'dungeon_session_id' => $session->id,
                'character_id' => $character->id,
            ]);

            return $session;
        });
    }

    // --------------------------------------------------------- the descent

    /**
     * §9.6.1 -- go down. The first one through locks the roster and fixes the seed.
     */
    public function enter(Character $character): DungeonMember
    {
        return DB::transaction(function () use ($character) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);

            if ($member->isInside()) {
                throw new GameException('You are already inside.');
            }

            $session = $member->session()->lockForUpdate()->first();

            if ($session->first_character_id === null) {
                // §9.6.2 -- whoever walked in first is part of the floor seed, so
                // it is fixed here and never again: a seed that moved after a
                // floor was drawn would redraw the floor underneath somebody
                // standing on it.
                $session->first_character_id = $character->id;
                $session->locked_at_ms = $now;
                $session->save();
            }

            [$col, $row] = Dungeons::entrance($session->floorSeed(1));

            $member->fill([
                'floor' => 1,
                'col' => $col,
                'row' => $row,
                'entered_at_ms' => $now,
                'busy_until_ms' => null,
            ])->save();

            return $member;
        });
    }

    /**
     * §5.6 -- one hex, and it costs what a hex costs out in the world.
     *
     * A step onto a live monster is allowed and is how a fight starts: §9.5.3
     * pins you there, and §9.6.4 needs everybody who is fighting to be standing
     * on one hex. Refusing the step would make a party fight impossible to
     * arrange.
     */
    public function step(Character $character, int $col, int $row): DungeonMember
    {
        return DB::transaction(function () use ($character, $col, $row) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);
            $this->requireInside($member);
            $this->requireIdle($member, $now);
            $this->requirePinFree($member, $now);

            if (! Dungeons::inBounds($col, $row)) {
                throw new GameException('That is not on this floor.');
            }

            if (HexGeometry::distance($member->col, $member->row, $col, $row) !== 1) {
                throw new GameException('One hex at a time.');
            }

            $member->fill([
                'col' => $col,
                'row' => $row,
                'busy_until_ms' => $now + Balance::scaled(Balance::TRAVEL_MS_PER_HEX),
            ])->save();

            return $member;
        });
    }

    /**
     * §9.6.2 -- go down the stair.
     *
     * Two gates, and both have to be met: the guardian is dead (it stands ON the
     * stair, so the stair hex being cleared IS that) and six of the floor's
     * monsters have fallen. The guardian is the sixth of them, so a party that
     * killed five and then the guardian meets both at once.
     */
    public function descend(Character $character): DungeonMember
    {
        return DB::transaction(function () use ($character) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);
            $this->requireInside($member);
            $this->requireIdle($member, $now);
            $this->requirePinFree($member, $now);

            $session = $member->session;
            $seed = $session->floorSeed($member->floor);
            [$sc, $sr] = Dungeons::stair($seed);

            if ($member->col !== $sc || $member->row !== $sr) {
                throw new GameException('The stair is elsewhere on this floor.');
            }

            if (! $this->guardianDown($session, $member->floor, $sc, $sr)) {
                throw new GameException('It is standing on the stair.');
            }

            $kills = $session->killsOn($member->floor);
            if (! Dungeons::floorOpen($kills)) {
                $short = Balance::DUNGEON_FLOOR_KILLS - $kills;
                throw new GameException("The floor is not done with you: {$short} more to clear.");
            }

            if ($member->floor >= Balance::DUNGEON_FLOORS) {
                throw new GameException('There is nothing below this.');
            }

            $floor = $member->floor + 1;
            [$col, $row] = Dungeons::entrance($session->floorSeed($floor));

            $member->fill([
                'floor' => $floor,
                'col' => $col,
                'row' => $row,
                'busy_until_ms' => null,
            ])->save();

            return $member;
        });
    }

    // ------------------------------------------------------------ the fight

    /**
     * §9.6.4 -- settle whatever is standing on the hex under your feet.
     *
     * Solo for now: §9.6.9 phases the roster-summed exchange as step three, and
     * it wants the fog exemption and a push channel that do not exist yet.
     * Everything this DOES do is the shared machinery -- one profile, one
     * resolver, one wear bill.
     *
     * @return array<string,mixed>
     */
    public function fight(Character $character): array
    {
        return DB::transaction(function () use ($character) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);
            $this->requireInside($member);
            $this->requireIdle($member, $now);

            $session = $member->session;
            $monster = $this->standingOn($session, $member->floor, $member->col, $member->row);

            if ($monster === null) {
                throw new GameException('Nothing is standing here.');
            }

            $profile = $this->game->combatProfile($character);

            // §16 -- seeded server-side, and the session's secret is in it: two
            // members closing on two copies of one monster must not share a roll,
            // and nobody may precompute the fight they are about to take.
            $seed = Hash::hash2(
                $member->col * 61 + $member->floor,
                $member->row * 43 + (int) $character->id,
                (int) hexdec(substr(hash('sha256', $session->secret.'|fight'), 0, 7)),
            );

            $fight = Formulas::resolveBattle(
                $profile['attack'],
                $profile['defense'],
                $profile['pool'],
                $monster,
                $seed,
                $profile['skills'],
            );

            // §9.5.6 -- the same bill a road fight pays, off the same two calls.
            $wear = [];
            $share = $this->game->battleWear(
                $profile['items'],
                $monster,
                (int) $fight['damageTaken'],
                (float) ($profile['tree']['wear'] ?? 0.0),
                (float) ($profile['tree']['weaponWear'] ?? 0.0),
            );

            $byId = [];
            foreach ($character->items as $item) {
                $byId[$item->id] = $item;
            }

            foreach ($share as $id => $amount) {
                if (isset($byId[$id])) {
                    $wear[] = $this->game->wearInFight($byId[$id], $amount);
                }
            }

            $gold = 0;
            $rewards = [];
            $prize = null;
            $treasure = [];
            $isGuardian = (bool) ($monster['guardian'] ?? false);

            if ($fight['won']) {
                // §9.6.2 -- the row IS the state. Monsters never respawn, and the
                // unique index is what makes that true rather than a rule in
                // code: two members closing on one hex at once is exactly the
                // race a check loses.
                DungeonClear::create([
                    'dungeon_session_id' => $session->id,
                    'floor' => $member->floor,
                    'col' => $member->col,
                    'row' => $member->row,
                    'character_id' => $character->id,
                    'guardian' => $isGuardian,
                    'killed_at_ms' => $now,
                ]);

                $gold = Hash::randInt(
                    Hash::hash2($seed, 0x60D, 0x9061),
                    (int) $monster['gold'][0],
                    (int) $monster['gold'][1],
                );
                $character->gold += $gold;
                $character->save();

                // §9.5.8 -- a monster on a floor pays exactly what it pays on a
                // road, through the road's own call. A dungeon paying its own
                // way would be a second drop table for one rule, and the first
                // thing two tables do is disagree about a creature they share.
                $rewards = $this->game->awardBattleRewards(
                    $character,
                    $monster,
                    $seed,
                    $profile['job']['job'] ?? null,
                    (float) ($profile['tree']['loot'] ?? 0.0),
                );

                // §9.6.8 -- and the guardian pays the dungeon's own table on top.
                // Only the guardian: that is what keeps the two faucets
                // separable, so a floor of Moss Hounds cannot be farmed for
                // anything a dungeon is the gate on.
                if ($isGuardian) {
                    $drop = DungeonDrops::guardian(
                        $session->dungeon,
                        $session->category,
                        $session->difficulty,
                        $member->floor,
                        $seed,
                    );

                    foreach ($drop['materials'] as $key => $quantity) {
                        $granted = $this->game->addMaterial($character, $key, $quantity);
                        if ($granted > 0) {
                            $treasure[$key] = $granted;
                        }
                    }

                    if ($drop['item'] !== null) {
                        // Harder packs roll better options rather than better
                        // rarity (§9.5.8), and a guardian is the hardest thing
                        // on the floor -- so the depth buys lines, never a rung.
                        $prize = $this->game->grantRolledItem(
                            $character,
                            $drop['item'],
                            $seed,
                            intdiv($member->floor, 4),
                        );
                    }
                }
            } else {
                // §9.6.6 -- a loss puts you at the landing of the first floor with
                // everything you were carrying. It takes nothing from the bag;
                // what it costs is the pool, and the only thing that fills that
                // is the repair stock you chose to bring down.
                $this->sendToEntrance($member, $session);
            }

            $member->busy_until_ms = $now + Formulas::battleDurationMs((int) $fight['rounds']);
            $member->save();

            $kills = $session->killsOn($member->floor);

            return [
                'won' => (bool) $fight['won'],
                'monster' => $monster,
                'rounds' => (int) $fight['rounds'],
                'damageTaken' => (int) $fight['damageTaken'],
                'damageDealt' => (int) $fight['damageDealt'],
                'log' => $fight['log'],
                'wear' => $wear,
                'gold' => $gold,
                'guardian' => $isGuardian,
                // §9.5.8's own, and then §9.6.8's on top of it. Kept apart in
                // the payload for the same reason they are kept apart in the
                // code: one is what the creature pays anywhere, and the other
                // is what the dungeon pays for reaching it.
                'spoils' => $rewards['spoils'] ?? [],
                'looted' => $rewards['looted'] ?? null,
                'leftBehind' => $rewards['leftBehind'] ?? null,
                'jobXp' => $rewards['jobXp'] ?? 0,
                'characterXp' => $rewards['characterXp'] ?? 0,
                'levels' => $rewards['levels'] ?? 0,
                'treasure' => $treasure,
                'prize' => $prize,
                'kills' => $kills,
                'floorOpen' => Dungeons::floorOpen($kills),
                'guardianRoused' => Dungeons::guardianRoused($kills),
                'floor' => $member->floor,
                'col' => $member->col,
                'row' => $member->row,
            ];
        });
    }

    // ------------------------------------------------------------- leaving

    /** Walk out. The session goes on without you; your seat does not come back. */
    public function leave(Character $character): void
    {
        DB::transaction(function () use ($character) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);

            $member->fill([
                'entered_at_ms' => null,
                'busy_until_ms' => null,
            ])->save();
        });
    }

    // ------------------------------------------------------------- reading

    /**
     * §9.6.2 -- what is on this hex, guardian included.
     *
     * The stair's monster is the guardian and it is scaled by the roster, which
     * is why this needs the session rather than just the seed.
     */
    public function standingOn(DungeonSession $session, int $floor, int $col, int $row): ?array
    {
        if ($this->cleared($session, $floor, $col, $row)) {
            return null;
        }

        $seed = $session->floorSeed($floor);
        [$sc, $sr] = Dungeons::stair($seed);

        if ($col === $sc && $row === $sr) {
            // §9.6.2 -- it will not rouse until five have fallen, so that the
            // requirement names itself where a player would bump into it rather
            // than after the climax of the floor.
            if (! Dungeons::guardianRoused($session->killsOn($floor))) {
                return null;
            }

            return Dungeons::guardian(
                $session->dungeon,
                $session->difficulty,
                $floor,
                max(1, $session->members()->whereNotNull('entered_at_ms')->count()),
            );
        }

        $key = Dungeons::monsterAt($seed, $session->dungeon, $session->difficulty, $floor, $col, $row);

        return $key === null ? null : ['key' => $key] + Monsters::ROSTER[$key];
    }

    public function memberFor(Character $character, int $now): ?DungeonMember
    {
        return DungeonMember::where('character_id', $character->id)
            ->whereHas('session', fn ($q) => $q->where('expires_at_ms', '>', $now))
            ->first();
    }

    // -------------------------------------------------------------- guards

    private function memberOf(Character $character, int $now): DungeonMember
    {
        $member = $this->memberFor($character, $now);

        if ($member === null) {
            throw new GameException('You are not in a dungeon session.');
        }

        return $member;
    }

    private function requireInside(DungeonMember $member): void
    {
        if (! $member->isInside()) {
            throw new GameException('You are at the mouth, not on a floor.');
        }
    }

    private function requireIdle(DungeonMember $member, int $now): void
    {
        if ($member->isBusy($now)) {
            throw new GameException('Not yet.');
        }
    }

    /** §9.5.3 -- while something is standing on your hex, it owns the hex. */
    private function requirePinFree(DungeonMember $member, int $now): void
    {
        if ($this->standingOn($member->session, $member->floor, $member->col, $member->row) !== null) {
            throw new GameException('It is on you. Fight it, or it is not going anywhere.');
        }
    }

    private function requireMouth(Character $character, string $dungeon): void
    {
        $at = WorldGen::dungeonAt((int) $character->col, (int) $character->row);

        if ($at === null || $at['key'] !== $dungeon) {
            throw new GameException('You are not standing at that mouth.');
        }
    }

    private function requireFree(Character $character, int $now): void
    {
        if ($this->memberFor($character, $now) !== null) {
            throw new GameException('You are already in a session.');
        }
    }

    /**
     * §9.6.8 -- the second of the three things holding §2 shut.
     *
     * A dropped legendary is mintable, so this is the first grind-to-external
     * path the game has: the cap is a RATE, not a total (§12.2), and the
     * faucet's lifetime yield is therefore wallets x weeks -- both of which §2
     * has already priced.
     */
    private function requireWeeklyAllowance(Character $character, int $now): void
    {
        $since = $now - 7 * 24 * 60 * 60 * 1000;

        $runs = DungeonMember::where('character_id', $character->id)
            ->whereHas('session', fn ($q) => $q->where('created_at_ms', '>', $since))
            ->count();

        if ($runs >= Balance::DUNGEON_SESSIONS_PER_WEEK) {
            throw new GameException('You have run your dungeons for the week.');
        }
    }

    // -------------------------------------------------------------- helpers

    private function cleared(DungeonSession $session, int $floor, int $col, int $row): bool
    {
        return $session->clears()
            ->where('floor', $floor)
            ->where('col', $col)
            ->where('row', $row)
            ->exists();
    }

    private function guardianDown(DungeonSession $session, int $floor, int $col, int $row): bool
    {
        return $this->cleared($session, $floor, $col, $row);
    }

    private function sendToEntrance(DungeonMember $member, DungeonSession $session): void
    {
        [$col, $row] = Dungeons::entrance($session->floorSeed(1));

        $member->fill(['floor' => 1, 'col' => $col, 'row' => $row]);
    }

    private function freshCode(): string
    {
        for ($try = 0; $try < 40; $try++) {
            $code = '';
            for ($i = 0; $i < Balance::DUNGEON_CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            if (! DungeonSession::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new GameException('Could not open a session. Try again.');
    }
}
