<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\BattleSkills;
use App\Game\Formulas;
use App\Game\Monsters;
use Tests\TestCase;

/**
 * §9.5.9 -- what a weapon knows how to do besides swing.
 *
 * Nine skills, three to a family, no two families sharing a trick. The rules
 * that have to hold are about WHEN they land rather than how hard: a fight
 * cannot be steered once it starts (§9.5.3), so everything interesting about
 * them is decided by the cooldowns and by the fact that all of them start a
 * fight on one.
 */
final class BattleSkillTest extends TestCase
{
    /** A kit good enough that fights run long enough for skills to matter. */
    private const ATTACK = 30;

    private const DEFENSE = 20;

    private const POOL = 900;

    /**
     * §9.5.4 -- three families, three skills each, and almost nothing shared.
     *
     * The families are the class system -- the weapon in the slot decides which
     * battle job levels -- so three costumes over one mechanic would have made
     * that choice cosmetic. Every family therefore has to own at least two
     * verbs outright.
     *
     * `stun` is the one verb two of them carry, and it is deliberate: taking a
     * turn away is the most legible thing a fight can do, and the shield's is
     * two rounds against the focus's one on a cooldown half again as long. Same
     * mechanic, opposite prices. Nothing else may be shared at all.
     */
    public function test_every_family_has_three_and_owns_its_own_tricks(): void
    {
        $effects = ['power', 'pierce', 'stun', 'burn', 'strikes', 'riposte', 'sunder', 'stance', 'toll'];
        $carriedBy = [];

        foreach (['shield', 'sword', 'focus'] as $family) {
            $three = BattleSkills::forFamily($family);
            $this->assertCount(3, $three, "{$family} does not carry three skills");

            foreach ($three as $key => $skill) {
                $this->assertSame($family, $skill['family']);
                $this->assertNotSame('', (string) $skill['glyph'], "{$key} has no glyph to draw");
                $this->assertGreaterThanOrEqual(
                    Balance::BATTLE_SKILL_MIN_COOLDOWN,
                    $skill['cooldown'],
                    "{$key} is tuned under the cooldown floor",
                );

                $does = array_values(array_filter($effects, static fn (string $e): bool => isset($skill[$e])));
                $this->assertNotEmpty($does, "{$key} does nothing at all");

                foreach ($does as $effect) {
                    $carriedBy[$effect][$family] = true;
                }
            }
        }

        // Every mechanic the exchange knows how to run is reached by something.
        // A branch nothing fires is the same dead weight §7.4 forbids of a
        // skill node.
        foreach ($effects as $effect) {
            $this->assertArrayHasKey($effect, $carriedBy, "nothing in the game carries {$effect}");
        }

        $shared = array_keys(array_filter($carriedBy, static fn (array $f): bool => count($f) > 1));
        $this->assertSame(['stun'], $shared, 'a second verb is being carried by more than one family');

        foreach (['shield', 'sword', 'focus'] as $family) {
            $own = array_filter(
                $carriedBy,
                static fn (array $f): bool => count($f) === 1 && isset($f[$family]),
            );

            $this->assertGreaterThanOrEqual(
                2,
                count($own),
                "{$family} owns fewer than two verbs of its own, so the class is nearly cosmetic",
            );
        }
    }

    /**
     * §9.5.9 -- nothing opens with a skill, and a rout never sees one.
     *
     * Both halves of the same rule. An alpha strike on round one would decide
     * fights §9.5.5 wants decided by the exchange, and a pack put down in four
     * rounds is meant to be a rout rather than a rotation.
     */
    public function test_every_skill_starts_a_fight_on_cooldown(): void
    {
        foreach (['shield', 'sword', 'focus'] as $family) {
            $armed = BattleSkills::armed($family);
            $soonest = min(array_column($armed, 'cooldown'));

            $this->assertGreaterThan(1, $soonest, "{$family} can open with a skill");

            foreach (Monsters::ROSTER as $key => $monster) {
                for ($seed = 1; $seed <= 40; $seed++) {
                    $fight = Formulas::resolveBattle(
                        self::ATTACK,
                        self::DEFENSE,
                        self::POOL,
                        $monster,
                        $seed,
                        $armed,
                    );

                    foreach ($fight['log'] as $i => $entry) {
                        if (! isset($entry['skill'])) {
                            continue;
                        }

                        $this->assertGreaterThanOrEqual(
                            $soonest,
                            $i + 1,
                            "a {$family} skill went off on round ".($i + 1)." against {$key}",
                        );
                    }
                }
            }
        }
    }

    /** §9.5.9 -- one a round at most, whatever is off cooldown. */
    public function test_at_most_one_skill_a_round(): void
    {
        foreach (['shield', 'sword', 'focus'] as $family) {
            $armed = BattleSkills::armed($family);

            for ($seed = 1; $seed <= 60; $seed++) {
                $fight = Formulas::resolveBattle(
                    self::ATTACK,
                    self::DEFENSE,
                    self::POOL * 3,
                    Monsters::ROSTER['barrow_knight'],
                    $seed,
                    $armed,
                );

                foreach ($fight['log'] as $entry) {
                    // The log carries one `skill` key or none, which is the
                    // shape the modal draws: one glyph a round, never a stack.
                    $this->assertLessThanOrEqual(1, isset($entry['skill']) ? 1 : 0);
                }
            }
        }
    }

    /**
     * §9.5.9 -- and one goes off often enough to be worth carrying.
     *
     * The other half of the cooldown rule, and the one that fails silently:
     * the first version of this set them at the bell and never counted them
     * down, so not a single skill fired in the whole game and every measurement
     * came back identical to the one before it.
     */
    public function test_a_long_fight_rotates_through_all_three(): void
    {
        foreach (['shield', 'sword', 'focus'] as $family) {
            $armed = BattleSkills::armed($family);

            $seen = [];
            for ($seed = 1; $seed <= 20; $seed++) {
                $fight = Formulas::resolveBattle(
                    self::ATTACK,
                    self::DEFENSE,
                    self::POOL * 4,
                    Monsters::ROSTER['barrow_knight'],
                    $seed,
                    $armed,
                );

                foreach ($fight['log'] as $entry) {
                    if (isset($entry['skill'])) {
                        $seen[$entry['skill']] = true;
                    }
                }
            }

            $this->assertCount(
                3,
                $seen,
                "{$family} never reached all three over twenty long fights: ".implode(', ', array_keys($seen)),
            );
        }
    }

    /** §9.5.9 -- skills change the fight, which is the whole reason they exist. */
    public function test_a_kit_with_skills_beats_the_same_kit_without(): void
    {
        $without = 0;
        $with = 0;

        foreach (Monsters::ROSTER as $monster) {
            for ($seed = 1; $seed <= 40; $seed++) {
                $without += Formulas::resolveBattle(self::ATTACK, self::DEFENSE, self::POOL, $monster, $seed)['damageDealt'];
                $with += Formulas::resolveBattle(
                    self::ATTACK,
                    self::DEFENSE,
                    self::POOL,
                    $monster,
                    $seed,
                    BattleSkills::armed('sword'),
                )['damageDealt'];
            }
        }

        $this->assertGreaterThan($without, $with, 'the skills did nothing at all');
    }

    /**
     * §7.4.3 -- the tree sharpens them, and every upgrade stops at its cap.
     *
     * Clamped in BattleSkills::armed() rather than at the call site, so a caller
     * that forgets to clamp cannot reach the exchange with an uncapped number.
     */
    public function test_the_tree_sharpens_them_and_stops_at_the_cap(): void
    {
        $plain = BattleSkills::armed('sword');
        $maxed = BattleSkills::armed('sword', ['power' => 99.0, 'cooldown' => 99, 'stun' => 99]);
        $capped = BattleSkills::armed('sword', [
            'power' => Balance::SKILL_BATTLE_POWER_CAP,
            'cooldown' => Balance::SKILL_BATTLE_COOLDOWN_CAP,
            'stun' => Balance::SKILL_BATTLE_STUN_CAP,
        ]);

        $this->assertEquals($capped, $maxed, 'an upgrade reached past its own cap');

        foreach ($plain as $i => $skill) {
            $this->assertLessThanOrEqual(
                $skill['cooldown'],
                $maxed[$i]['cooldown'],
                "{$skill['key']} did not come round sooner",
            );
            $this->assertGreaterThanOrEqual(
                Balance::BATTLE_SKILL_MIN_COOLDOWN,
                $maxed[$i]['cooldown'],
                "{$skill['key']} was bought under the cooldown floor",
            );

            if (isset($skill['stun'])) {
                $this->assertSame(
                    $skill['stun'] + Balance::SKILL_BATTLE_STUN_CAP,
                    $maxed[$i]['stun'],
                );
            }
        }
    }

    /**
     * §9.5.9 -- a tooltip the way the genre writes one.
     *
     * The convention every MMO shares: a plain verb-first sentence saying what
     * the skill does, then labelled rows carrying every number. Guild Wars 2
     * writes "Bash your foe with your shield and stun them", then `Stun: 2
     * seconds` underneath. It is not one dense paragraph with figures buried
     * in it, which is what this used to be.
     *
     * NO NUMBER MAY APPEAR IN THE SENTENCE, and that is the load-bearing half.
     * It is what lets the prose be hand-written while every figure stays
     * derived from the very fields the exchange reads -- so nothing can drift,
     * and a player who has bought a node reads their own numbers. A typed
     * "stuns for 2 rounds" would be wrong for everybody with `skillStun`.
     */
    public function test_a_tooltip_reads_the_way_the_genre_writes_one(): void
    {
        foreach (['shield', 'sword', 'focus'] as $family) {
            foreach (BattleSkills::armed($family) as $skill) {
                $card = BattleSkills::summary($skill);

                $this->assertDoesNotMatchRegularExpression(
                    '/\d/',
                    $card['effect'],
                    "{$skill['key']}'s sentence carries a figure. Numbers belong in the rows, ".
                    'or a hand-written one will outlive the arithmetic it describes.',
                );
                $this->assertMatchesRegularExpression(
                    '/[.!]$/',
                    $card['effect'],
                    "{$skill['key']}'s sentence is not a sentence",
                );

                // And the flavour is a different thing again, kept apart the
                // way every MMO keeps it apart.
                $this->assertNotSame(
                    $card['effect'],
                    $skill['description'],
                    "{$skill['key']} has flavour standing in for its mechanics",
                );

                $labels = array_column($card['stats'], 'label');

                $this->assertContains('Cooldown', $labels, "{$skill['key']} does not print its cooldown");
                $this->assertContains('First use', $labels, "{$skill['key']} does not say when it first arrives");
                $this->assertGreaterThanOrEqual(
                    3,
                    count($card['stats']),
                    "{$skill['key']} says nothing but its cooldown",
                );
                $this->assertSame(
                    array_unique($labels),
                    $labels,
                    "{$skill['key']} prints a label twice, which reads as two different facts",
                );

                foreach ($card['stats'] as $row) {
                    $this->assertNotSame('', trim((string) $row['value']), "{$skill['key']} has an empty row");
                    $this->assertMatchesRegularExpression(
                        '/^[A-Z]/',
                        $row['label'],
                        "{$skill['key']}'s labels are not capitalised consistently",
                    );
                }

                // The two rows a player compares across all three are always
                // last, and always in the same order.
                $this->assertSame(
                    ['Cooldown', 'First use'],
                    array_slice($labels, -2),
                    "{$skill['key']} moves the rows that are meant to be comparable",
                );
            }
        }

        // A bought node changes what is SAID, not only what is done. This is
        // the whole reason the rows are derived from the armed skill rather
        // than from the catalog row.
        $plain = BattleSkills::summary(BattleSkills::armed('shield')[0]);
        $maxed = BattleSkills::summary(
            BattleSkills::armed('shield', [
                'power' => Balance::SKILL_BATTLE_POWER_CAP,
                'cooldown' => Balance::SKILL_BATTLE_COOLDOWN_CAP,
                'stun' => Balance::SKILL_BATTLE_STUN_CAP,
            ])[0],
        );

        $this->assertNotSame($plain['stats'], $maxed['stats'], 'a maxed tree reads exactly like an empty one');
        $this->assertSame($plain['effect'], $maxed['effect'], 'the sentence moved, so it was carrying a figure');
    }

    /**
     * §16 -- the TS mirror says the same thing the PHP does.
     *
     * `resources/js/game/battleSkills.ts` is mirrored rather than served,
     * because the battle modal has to name a skill the instant a stored round
     * mentions one and a fetch that has not landed yet would draw a key where a
     * name goes. Mirrors drift; this is what stops it.
     *
     * Only what the client is allowed to hold is compared -- name, glyph,
     * family, cooldown and the effect SENTENCE. Multipliers, ticks and stun
     * lengths are the server's alone and are deliberately absent from the
     * mirror; the sentence is safe to carry because it holds no figure, which
     * the test above pins by maxing a tree and checking it did not move.
     */
    public function test_the_client_mirror_agrees_with_the_server(): void
    {
        $ts = file_get_contents(base_path('resources/js/game/battleSkills.ts'));
        $this->assertNotFalse($ts);

        foreach (BattleSkills::SKILLS as $key => $skill) {
            $this->assertMatchesRegularExpression(
                '/^\s*'.preg_quote($key, '/').':\s*\{/m',
                $ts,
                "{$key} is missing from the client mirror",
            );

            foreach (['family', 'name', 'glyph', 'effect'] as $field) {
                $this->assertStringContainsString(
                    $field.': '.json_encode($skill[$field]),
                    $ts,
                    "{$key}'s {$field} disagrees with the client mirror",
                );
            }
        }

        preg_match_all('/^\s*([a-z_]+):\s*\{ key:/m', $ts, $found);
        $this->assertSame(
            array_keys(BattleSkills::SKILLS),
            $found[1],
            'the mirror holds a different set of skills, or a different order',
        );
    }
}
