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
     * §5.6 -- walk to a hex, the way the overworld walks to one.
     *
     * A JOURNEY rather than a press per hex. It was one step at a time with the
     * client pacing itself, and that is why the marker jumped from tile to tile
     * while the overworld's slid along a road: the two were different kinds of
     * movement. §5.6 already has the shape -- a departure, a destination, a
     * clock, and a position derived along the line -- so a floor uses it.
     *
     * **The road ends at the first thing standing on it** (§9.5.3), decided
     * here rather than by the walker noticing. A pack ahead stops the journey on
     * its hex, which is exactly what a pack does out in the world.
     */
    public function walk(Character $character, int $col, int $row): DungeonMember
    {
        return DB::transaction(function () use ($character, $col, $row) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);
            $this->requireInside($member);
            $this->settleWalk($member, $now);
            $this->requireIdle($member, $now);
            $this->requirePinFree($member, $now);

            if (! Dungeons::inBounds($col, $row)) {
                throw new GameException('That is not on this floor.');
            }

            if ($col === $member->col && $row === $member->row) {
                return $member;
            }

            $session = $member->session;
            $path = HexGeometry::line($member->col, $member->row, $col, $row);
            array_shift($path);

            // §9.5.3 -- the road ends where something is standing on it.
            $stop = null;
            foreach ($path as $i => $hex) {
                if (! Dungeons::inBounds($hex['col'], $hex['row'])) {
                    break;
                }

                $stop = $i;

                if ($this->standingOn($session, $member->floor, $hex['col'], $hex['row']) !== null) {
                    break;
                }
            }

            if ($stop === null) {
                throw new GameException('There is no way through.');
            }

            $end = $path[$stop];
            $hexes = $stop + 1;
            $perHex = Balance::scaled(Balance::TRAVEL_MS_PER_HEX);

            $member->fill([
                'walk_to_col' => $end['col'],
                'walk_to_row' => $end['row'],
                'walk_started_ms' => $now,
                'walk_ends_ms' => $now + $hexes * $perHex,
            ])->save();

            return $member;
        });
    }

    /**
     * Land a finished walk, and derive where a running one has got to.
     *
     * §5.6's own rule: the position is `path[floor(elapsed / perHex)]`, and both
     * sides run the same arithmetic so the client's answer and the server's
     * cannot disagree. Called at the top of every verb, so a walk that ended
     * while nobody was looking is already landed by the time anything asks.
     */
    private function settleWalk(DungeonMember $member, int $now): void
    {
        if ($member->walk_ends_ms === null) {
            return;
        }

        if ($now >= $member->walk_ends_ms) {
            $member->fill([
                'col' => $member->walk_to_col,
                'row' => $member->walk_to_row,
                'walk_to_col' => null,
                'walk_to_row' => null,
                'walk_started_ms' => null,
                'walk_ends_ms' => null,
            ])->save();
        }
    }

    /** §5.6 -- stop where you are, which is a hex rather than a fraction of one. */
    public function stopWalk(Character $character): DungeonMember
    {
        return DB::transaction(function () use ($character) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);
            $this->settleWalk($member, $now);

            if ($member->walk_ends_ms === null) {
                return $member;
            }

            [$col, $row] = $this->walkingAt($member, $now);

            $member->fill([
                'col' => $col,
                'row' => $row,
                'walk_to_col' => null,
                'walk_to_row' => null,
                'walk_started_ms' => null,
                'walk_ends_ms' => null,
            ])->save();

            return $member;
        });
    }

    /**
     * §5.6 -- where a walker IS right now, which is not where they set off.
     *
     * The same derivation the client draws the marker with, so the two agree by
     * construction rather than by being kept in step.
     *
     * @return array{0:int,1:int}
     */
    public function walkingAt(DungeonMember $member, int $now): array
    {
        if ($member->walk_ends_ms === null) {
            return [$member->col, $member->row];
        }

        $path = HexGeometry::line($member->col, $member->row, $member->walk_to_col, $member->walk_to_row);
        $perHex = max(1, Balance::scaled(Balance::TRAVEL_MS_PER_HEX));
        $step = (int) floor(max(0, $now - (int) $member->walk_started_ms) / $perHex);
        $at = $path[min($step, count($path) - 1)];

        return [$at['col'], $at['row']];
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
            // §5.6 -- a walk that finished while nobody was looking is landed
            // here, so every verb reads a hex rather than a road.
            $this->settleWalk($member, $now);
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
    /**
     * §9.6.4 -- settle whatever is standing on the hex, with everybody standing on it.
     *
     * **The roster on the hex is the party**, and nobody else: §9.6.4 says a
     * fight is joined by whoever is there when it opens, which is what makes
     * converging on a guardian a thing a party DOES rather than a lobby it
     * fills in. Somebody two hexes away is not in this fight and does not pay
     * for it.
     *
     * A member mid-step is left out rather than waited for. The alternative is a
     * fight that blocks on somebody who wandered off, and §9.6.4 already makes
     * the party's advantage composition rather than attendance.
     *
     * @return array<string,mixed>
     */
    public function fight(Character $character): array
    {
        return DB::transaction(function () use ($character) {
            $now = $this->game->now();
            $member = $this->memberOf($character, $now);
            $this->requireInside($member);
            // §5.6 -- a walk that finished while nobody was looking is landed
            // here, so every verb reads a hex rather than a road.
            $this->settleWalk($member, $now);
            $this->requireIdle($member, $now);

            $session = $member->session;
            $monster = $this->standingOn($session, $member->floor, $member->col, $member->row);

            if ($monster === null) {
                throw new GameException('Nothing is standing here.');
            }

            // Everybody on this hex, in a stable order so the seed means the
            // same thing however the rows come back. The caller is always first
            // -- it is their press, and the log reads better for it.
            $onHex = $session->members()
                ->whereNotNull('entered_at_ms')
                ->where('floor', $member->floor)
                ->where('col', $member->col)
                ->where('row', $member->row)
                ->lockForUpdate()
                ->get()
                ->reject(fn (DungeonMember $m) => $m->id !== $member->id && $m->isBusy($now))
                ->sortBy(fn (DungeonMember $m) => $m->id === $member->id ? 0 : $m->id)
                ->values();

            $fighters = [];
            foreach ($onHex as $row) {
                $who = $row->character;
                if ($who === null) {
                    continue;
                }

                $profile = $this->game->combatProfile($who);

                // §9.6.4 -- a BYSTANDER with an empty pool does not join. They
                // would be a body drawing answers away from the people actually
                // fighting, which is a party's worst bug: dead weight that makes
                // the fight easier for everyone else.
                //
                // **The caller is in it regardless**, and that exception is
                // §9.5.3 rather than a kindness. A pinned hex has exactly two
                // exits and one of them is fighting -- so a prospector whose
                // gear is gone must still be able to close, lose, and walk away.
                // Excluding them for having nothing left would be the one thing
                // that section forbids outright: a dead end with no way out.
                if ($profile['pool'] <= 0 && $row->id !== $member->id) {
                    continue;
                }

                $fighters[] = ['member' => $row, 'character' => $who, 'profile' => $profile];
            }

            if ($fighters === []) {
                throw new GameException('There is nothing left to fight it with.');
            }

            // §16 -- seeded server-side, and the session's secret is in it: two
            // members closing on two copies of one monster must not share a roll,
            // and nobody may precompute the fight they are about to take.
            $seed = Hash::hash2(
                $member->col * 61 + $member->floor,
                $member->row * 43 + (int) $character->id,
                (int) hexdec(substr(hash('sha256', $session->secret.'|fight'), 0, 7)),
            );

            // §9.6.4 -- the guardian's pool is scaled by the roster that is
            // actually swinging, not by the roster on the books. Six people who
            // signed up and one who walked to the stair is one person's fight.
            if ($monster['guardian'] ?? false) {
                $monster = Dungeons::guardian(
                    $session->dungeon,
                    $session->difficulty,
                    $member->floor,
                    count($fighters),
                );
            }

            $fight = Formulas::resolvePartyBattle(
                array_map(static fn (array $f): array => [
                    'attack' => $f['profile']['attack'],
                    'defense' => $f['profile']['defense'],
                    'pool' => $f['profile']['pool'],
                    'skills' => $f['profile']['skills'],
                ], $fighters),
                $monster,
                $seed,
            );

            $won = (bool) $fight['won'];
            $isGuardian = (bool) ($monster['guardian'] ?? false);

            if ($won) {
                // §9.6.2 -- the row IS the state, and it is written ONCE however
                // many people swung: a hex is cleared or it is not, and the
                // unique index is what makes that true rather than a rule.
                DungeonClear::create([
                    'dungeon_session_id' => $session->id,
                    'floor' => $member->floor,
                    'col' => $member->col,
                    'row' => $member->row,
                    'character_id' => $character->id,
                    'guardian' => $isGuardian,
                    'killed_at_ms' => $now,
                ]);
            }

            // §9.6.4 -- NOTHING SPLITS. Every member rolls the table for
            // themselves, which is what lets §9.6.8 quote a rate a player can
            // read: a legendary is 2.5% for you whoever else was standing there.
            // What a party divides is the fighting, never the paying.
            $shares = [];

            foreach ($fighters as $i => $f) {
                $who = $f['character'];
                $row = $f['member'];
                $mine = $fight['members'][$i];
                $taken = (int) $mine['damageTaken'];

                // §8.5 -- a battle draft was armed for exactly this, and the
                // roll above already carries it: `combatProfile()` reads the
                // `battle` bonuses into the pair, so the charge has been spent
                // in fact and has to be spent on the row.
                //
                // **Win or lose**, and per FIGHTER rather than per fight, the
                // same as a road pack. Each of them had their own bonuses read
                // into their own attack and defense, so each of them used one.
                //
                // Leaving this out is what made a battle draft permanent
                // underground: the bonus applied to every fight on every floor
                // and was never consumed. §8.5 forbids that in as many words --
                // nothing here may ever be permanent, because a permanent
                // effect only accumulates -- and §11.1 counts the spending as
                // the sink, so an uncollected charge is a sink that never
                // collects.
                $this->game->spendBuffs($who, 'battle');

                // §9.5.6 -- each pays their own bill off their own damage, which
                // is the whole reason the answers are individual.
                $wear = [];
                $share = $this->game->battleWear(
                    $f['profile']['items'],
                    $monster,
                    $taken,
                    (float) ($f['profile']['tree']['wear'] ?? 0.0),
                    (float) ($f['profile']['tree']['weaponWear'] ?? 0.0),
                );

                $byId = [];
                foreach ($who->items as $item) {
                    $byId[$item->id] = $item;
                }
                foreach ($share as $id => $amount) {
                    if (isset($byId[$id])) {
                        $wear[] = $this->game->wearInFight($byId[$id], $amount);
                    }
                }

                $gold = 0;
                $rewards = [];
                $treasure = [];
                $prize = null;

                if ($won) {
                    $gold = Hash::randInt(
                        Hash::hash2($seed + $i * 977, 0x60D, 0x9061),
                        (int) $monster['gold'][0],
                        (int) $monster['gold'][1],
                    );
                    $who->gold += $gold;
                    $who->save();

                    $rewards = $this->game->awardBattleRewards(
                        $who,
                        $monster,
                        $seed + $i * 977,
                        $f['profile']['job']['job'] ?? null,
                        (float) ($f['profile']['tree']['loot'] ?? 0.0),
                    );

                    if ($isGuardian) {
                        $drop = DungeonDrops::guardian(
                            $session->dungeon,
                            $session->category,
                            $session->difficulty,
                            $member->floor,
                            $seed + $i * 977,
                        );

                        foreach ($drop['materials'] as $key => $quantity) {
                            $granted = $this->game->addMaterial($who, $key, $quantity);
                            if ($granted > 0) {
                                $treasure[$key] = $granted;
                            }
                        }

                        if ($drop['item'] !== null) {
                            $prize = $this->game->grantRolledItem(
                                $who,
                                $drop['item'],
                                $seed + $i * 977,
                                intdiv($member->floor, 4),
                            );
                        }
                    }
                }

                // §9.6.6 -- going DOWN is what sends you back, not the party
                // losing. A member who survived a lost fight is still standing
                // where they were, which is the honest reading of a rout: the
                // ones who fell wake at the landing and the rest are still here.
                if ($mine['down']) {
                    $this->sendToEntrance($row, $session);
                }

                $row->busy_until_ms = $now + Formulas::battleDurationMs((int) $fight['rounds']);
                $row->save();

                $shares[] = [
                    'character' => (int) $who->id,
                    'name' => $who->name,
                    'damageTaken' => $taken,
                    'left' => (int) $mine['left'],
                    'down' => (bool) $mine['down'],
                    'wear' => $wear,
                    'gold' => $gold,
                    'spoils' => $rewards['spoils'] ?? [],
                    'looted' => $rewards['looted'] ?? null,
                    'leftBehind' => $rewards['leftBehind'] ?? null,
                    'jobXp' => $rewards['jobXp'] ?? 0,
                    'characterXp' => $rewards['characterXp'] ?? 0,
                    'levels' => $rewards['levels'] ?? 0,
                    'treasure' => $treasure,
                    'prize' => $prize,
                ];
            }

            $kills = $session->killsOn($member->floor);
            $me = $shares[0];

            return [
                'won' => $won,
                'monster' => $monster,
                'guardian' => $isGuardian,
                'party' => count($fighters),
                'rounds' => (int) $fight['rounds'],
                'damageDealt' => (int) $fight['damageDealt'],
                'log' => $fight['log'],
                /*
                 * §9.5.9 -- THE SAME REPLAY A ROAD FIGHT GETS.
                 *
                 * "One band, one cooldown rail, one skill row, drawn everywhere
                 * a fight is." A dungeon fight that reported itself as a list of
                 * numbers would be a second way of showing an exchange, and the
                 * first thing a second opinion does is drift -- so this is
                 * shaped as the battle JOB the replay already knows how to draw,
                 * and the client hands it to the same component.
                 *
                 * Not a real row in `jobs_queue`: the fight is over, and a job
                 * exists to be collected. What the replay needs is the rounds
                 * and the two pools, and those are facts about a fight that has
                 * already happened.
                 */
                'replay' => [
                    'id' => 'dungeon-'.$session->id.'-'.$member->floor.'-'.$member->col.'-'.$member->row,
                    'kind' => 'battle',
                    'status' => 'active',
                    'col' => $member->col,
                    'row' => $member->row,
                    'slot' => null,
                    'quantity' => 1,
                    'startedAt' => $now,
                    'endsAt' => $now + Formulas::battleDurationMs((int) $fight['rounds']),
                    'skill' => $profile['job']['job'] ?? 'swordhand',
                    'monster' => $monster['key'] ?? null,
                    'pool' => (int) $fight['pool'],
                    'monsterHp' => (int) $monster['hp'],
                    'roundMs' => Balance::BATTLE_ROUND_MS,
                    'log' => $fight['log'],
                    'skills' => array_map(static fn (array $skill): array => [
                        'key' => $skill['key'],
                        'name' => $skill['name'],
                        'glyph' => $skill['glyph'],
                        'cooldown' => $skill['cooldown'],
                        'description' => $skill['description'],
                        ...BattleSkills::summary($skill),
                    ], $profile['skills']),
                ],
                // The party's share of it, and then the caller's own at the top
                // level -- because a solo run is the common case and asking it
                // to dig its own row out of a list of one is noise.
                'members' => $shares,
                'damageTaken' => $me['damageTaken'],
                'wear' => $me['wear'],
                'gold' => $me['gold'],
                'spoils' => $me['spoils'],
                'looted' => $me['looted'],
                'leftBehind' => $me['leftBehind'],
                'jobXp' => $me['jobXp'],
                'characterXp' => $me['characterXp'],
                'levels' => $me['levels'],
                'treasure' => $me['treasure'],
                'prize' => $me['prize'],
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
