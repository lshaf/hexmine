<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\DungeonDrops;
use App\Game\Dungeons;
use Tests\TestCase;

/**
 * §9.6.8 -- what a guardian pays, and the guards on it.
 *
 * The §2 tests here are the load-bearing ones. A dropped legendary is mintable,
 * so this table is the first grind-to-external-value path the game has had --
 * and the thing that makes it safe is a rate that ramps with depth. That is one
 * float, and a float is exactly the kind of guard that gets "simplified" flat by
 * somebody who does not know what it is holding.
 */
final class DungeonDropTest extends TestCase
{
    /** Every contract can hand over both rungs, or a category is a dead end. */
    public function test_every_contract_has_both_rungs_to_offer(): void
    {
        foreach (DungeonDrops::SLOTS as $category => $slots) {
            foreach (['legendary', 'unique'] as $rarity) {
                $pool = DungeonDrops::pool($category, $rarity);

                $this->assertNotEmpty($pool, "the {$category} contract can never drop a {$rarity}");

                foreach ($pool as $key) {
                    $def = Catalog::item($key);
                    $this->assertNotNull($def, "{$key} is in a drop pool and not in the catalog");
                    $this->assertSame($rarity, $def['rarity']);
                    $this->assertContains($def['slot'], $slots, "{$key} is not a {$category} piece");
                }
            }
        }
    }

    /** And the three categories never offer each other's pieces. */
    public function test_the_contracts_do_not_overlap(): void
    {
        $seen = [];

        foreach (array_keys(DungeonDrops::SLOTS) as $category) {
            foreach (['legendary', 'unique'] as $rarity) {
                foreach (DungeonDrops::pool($category, $rarity) as $key) {
                    $this->assertArrayNotHasKey($key, $seen, "{$key} is offered by two contracts");
                    $seen[$key] = $category;
                }
            }
        }
    }

    /**
     * §9.6.8 -- THE guard. The rate ramps with depth and the headline number is
     * the deepest guardian's.
     *
     * Flat across ten guardians a hard session makes about 2.4 legendaries;
     * ramped it is roughly half that. Nothing about a flat table fails a test
     * that only checks the number is "right", which is why this checks the
     * SHAPE: floor one is a tenth of floor ten, and every floor between climbs.
     */
    public function test_the_legendary_rate_ramps_with_depth(): void
    {
        foreach (Dungeons::DIFFICULTIES as $difficulty) {
            $previous = 0.0;

            for ($floor = 1; $floor <= Balance::DUNGEON_FLOORS; $floor++) {
                $rate = DungeonDrops::legendaryChance($difficulty, $floor);

                $this->assertGreaterThan($previous, $rate, "{$difficulty} floor {$floor} did not climb");
                $previous = $rate;
            }

            $deepest = DungeonDrops::legendaryChance($difficulty, Balance::DUNGEON_FLOORS);
            $shallowest = DungeonDrops::legendaryChance($difficulty, 1);

            $this->assertEqualsWithDelta($deepest / Balance::DUNGEON_FLOORS, $shallowest, 1e-9);
        }

        // The headline number is quoted where it matters, and hard doubles it.
        $this->assertEqualsWithDelta(
            Balance::DUNGEON_LEGENDARY_CHANCE,
            DungeonDrops::legendaryChance('easy', Balance::DUNGEON_FLOORS),
            1e-9,
        );
        $this->assertEqualsWithDelta(
            Balance::DUNGEON_LEGENDARY_CHANCE * Balance::DUNGEON_HARD_MULTIPLIER,
            DungeonDrops::legendaryChance('hard', Balance::DUNGEON_FLOORS),
            1e-9,
        );
    }

    /**
     * §2 -- the whole-session yield, which is the number that actually matters.
     *
     * A per-guardian rate is not a faucet; ten of them are. This pins what one
     * full clear is worth so that a change to the ramp cannot quietly double the
     * thing the threat model is holding shut.
     */
    public function test_a_full_clear_stays_under_a_quarter(): void
    {
        foreach (Dungeons::DIFFICULTIES as $difficulty) {
            $miss = 1.0;

            for ($floor = 1; $floor <= Balance::DUNGEON_FLOORS; $floor++) {
                $miss *= 1 - DungeonDrops::legendaryChance($difficulty, $floor);
            }

            $atLeastOne = 1 - $miss;

            $this->assertLessThan(
                0.25,
                $atLeastOne,
                "a {$difficulty} clear yields a legendary ".round($atLeastOne * 100).'% of the time'
            );
        }
    }

    /** Unique is soulbound, so it is not a faucet -- but it must stay the rarer. */
    public function test_unique_is_rarer_than_legendary_everywhere(): void
    {
        foreach (Dungeons::DIFFICULTIES as $difficulty) {
            for ($floor = 1; $floor <= Balance::DUNGEON_FLOORS; $floor++) {
                $this->assertLessThan(
                    DungeonDrops::legendaryChance($difficulty, $floor),
                    DungeonDrops::uniqueChance($difficulty, $floor),
                );
            }
        }

        foreach (['legendary', 'unique'] as $rarity) {
            foreach (array_keys(DungeonDrops::SLOTS) as $category) {
                foreach (DungeonDrops::pool($category, $rarity) as $key) {
                    $def = Catalog::item($key);
                    $this->assertSame(
                        $rarity !== 'unique',
                        (bool) ($def['tradeable'] ?? false),
                        "{$key} is {$rarity} and its tradeability contradicts §8.0",
                    );
                }
            }
        }
    }

    /** §9.6.8 -- the material ladder, by depth. */
    public function test_the_material_table_gates_on_depth(): void
    {
        $easy1 = DungeonDrops::guardian('rootvault', 'tools', 'easy', 1, 1234)['materials'];
        $easy10 = DungeonDrops::guardian('rootvault', 'tools', 'easy', 10, 1234)['materials'];

        // Essence off every guardian, from the first floor.
        $this->assertArrayHasKey('essence', $easy1);
        $this->assertArrayNotHasKey('shard_verdant', $easy1, 'a Shard on floor one');
        $this->assertArrayNotHasKey('relic', $easy1);
        $this->assertArrayNotHasKey('core', $easy1);

        // And the floor-ten guardian always pays the Core that gates the top.
        $this->assertArrayHasKey('core', $easy10);
        $this->assertArrayHasKey('relic', $easy10);
        $this->assertArrayHasKey('shard_verdant', $easy10, "the dungeon's own Shard");

        // Hard reaches each rung earlier.
        $hard2 = DungeonDrops::guardian('rootvault', 'tools', 'hard', 2, 1234)['materials'];
        $this->assertArrayHasKey('shard_verdant', $hard2);
    }

    /** The Shard is the site's own, which is what makes §4's cross-map pressure real. */
    public function test_each_dungeon_pays_its_own_shard(): void
    {
        foreach (Catalog::DUNGEONS as $site) {
            $materials = DungeonDrops::guardian($site['key'], 'tools', 'easy', 10, 99)['materials'];

            $this->assertArrayHasKey($site['drop'], $materials, "{$site['key']} did not pay its own Shard");

            foreach (Catalog::DUNGEONS as $other) {
                if ($other['key'] !== $site['key']) {
                    $this->assertArrayNotHasKey($other['drop'], $materials, 'paid another dungeon\'s Shard');
                }
            }
        }
    }

    /**
     * Seeded like everything else: the same guardian is the same drop, and the
     * seed genuinely moves it.
     *
     * The second half is asserted over a SWEEP rather than against one other
     * seed, and that distinction cost a red test to learn. A guardian's ordinary
     * outcome space is small -- essence one to three, a Shard one to two, a Relic
     * that is always there -- so two adjacent seeds landing on the same result is
     * the common case rather than a broken hash. Pinning two arbitrary seeds as
     * different is a coin flip dressed as a property.
     */
    public function test_a_guardian_drop_is_seeded(): void
    {
        $a = DungeonDrops::guardian('ashpit', 'weapons', 'hard', 7, 777);

        $this->assertSame($a, DungeonDrops::guardian('ashpit', 'weapons', 'hard', 7, 777));

        $distinct = [];
        for ($seed = 0; $seed < 200; $seed++) {
            $distinct[json_encode(DungeonDrops::guardian('ashpit', 'weapons', 'hard', 7, $seed))] = true;
        }

        $this->assertGreaterThan(5, count($distinct), 'the seed barely moves the drop');
    }

    /**
     * A piece that lands is the contract's own, and never both rungs at once.
     *
     * Swept rather than sampled: the roll is a float against a seeded hash, and
     * the case that matters is the rare one.
     */
    public function test_a_drop_is_one_piece_of_the_right_contract(): void
    {
        foreach (array_keys(DungeonDrops::SLOTS) as $category) {
            $dropped = 0;

            for ($seed = 0; $seed < 4000; $seed++) {
                $drop = DungeonDrops::guardian('windhollow', $category, 'hard', 10, $seed);

                if ($drop['item'] === null) {
                    $this->assertNull($drop['rarity']);

                    continue;
                }

                $dropped++;
                $def = Catalog::item($drop['item']);

                $this->assertSame($drop['rarity'], $def['rarity']);
                $this->assertContains($def['slot'], DungeonDrops::SLOTS[$category]);
            }

            $this->assertGreaterThan(0, $dropped, "{$category} never dropped anything in four thousand rolls");
        }
    }
}
