<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Dungeons;
use App\Game\HexGeometry;
use App\Game\Monsters;
use Tests\TestCase;

/**
 * §9.6.2 -- what stands on a dungeon floor, and the two promises about it.
 *
 * The secret is the first: a floor is unguessable without it, which is the whole
 * of what keeps the creatures the fog. The kill gate is the second, and it is the
 * one that has to be measured rather than reasoned about -- six have to be MET on
 * the way, on every seed, or the gate sends somebody combing twenty-five hundred
 * hexes at sight one to three.
 */
final class DungeonFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Dungeons::forget();
    }

    private function floorSeedFor(int $i, int $floor = 1): int
    {
        return Dungeons::floorSeed('secret-'.$i, 'CODE'.$i, 1_700_000_000 + $i, $i + 1, $floor);
    }

    /** Same session, same floor, same everything. */
    public function test_a_floor_is_the_same_floor_every_time(): void
    {
        $a = Dungeons::floorSeed('s', 'ABCDEF', 1_700_000_000, 7, 3);
        $b = Dungeons::floorSeed('s', 'ABCDEF', 1_700_000_000, 7, 3);

        $this->assertSame($a, $b);
        $this->assertSame(Dungeons::entrance($a), Dungeons::entrance($b));
        $this->assertSame(Dungeons::stair($a), Dungeons::stair($b));
    }

    /**
     * §9.6.2 -- the secret is the only ingredient the player is not holding.
     *
     * Change any of the four public ones and you get a different floor, which is
     * what makes each floor its own draw. Change the secret alone and you also
     * get a different floor -- which is the half that matters, because it is the
     * one a client cannot do.
     */
    public function test_the_secret_is_what_makes_a_floor_unguessable(): void
    {
        $base = Dungeons::floorSeed('secret-a', 'ABCDEF', 1_700_000_000, 7, 3);

        $this->assertNotSame($base, Dungeons::floorSeed('secret-b', 'ABCDEF', 1_700_000_000, 7, 3), 'the secret must move the floor');
        $this->assertNotSame($base, Dungeons::floorSeed('secret-a', 'ZZZZZZ', 1_700_000_000, 7, 3), 'the code must move the floor');
        $this->assertNotSame($base, Dungeons::floorSeed('secret-a', 'ABCDEF', 1_700_000_001, 7, 3), 'the timestamp must move the floor');
        $this->assertNotSame($base, Dungeons::floorSeed('secret-a', 'ABCDEF', 1_700_000_000, 8, 3), 'the first player must move the floor');
        $this->assertNotSame($base, Dungeons::floorSeed('secret-a', 'ABCDEF', 1_700_000_000, 7, 4), 'the floor number must move the floor');
    }

    /** Generated, never derived: a secret computed from public things is not one. */
    public function test_a_secret_is_fresh_every_time(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $s = Dungeons::newSecret();
            $this->assertSame(Balance::DUNGEON_SECRET_BYTES * 2, strlen($s));
            $this->assertArrayNotHasKey($s, $seen, 'a secret repeated inside two hundred draws');
            $seen[$s] = true;
        }
    }

    /**
     * §9.6.2 -- THE test. Six met on the way, on every seed, never hunted.
     *
     * Against the seed rather than the average, because that is the failure this
     * guards: at 12% density a radius-eight disc holds seven monsters on average
     * and the average is not the promise. Measured before the seeded cohort
     * existed, one seed in three hundred came up short of six here and one in
     * three came up short inside radius four -- a floor that strands somebody,
     * looking for all the world like a broken gate rather than a thin roll.
     */
    public function test_six_are_always_within_a_short_walk_of_the_landing(): void
    {
        $radius = 9;
        $dungeons = array_column(Catalog::DUNGEONS, 'key');
        $worst = PHP_INT_MAX;

        for ($i = 0; $i < 400; $i++) {
            $floor = ($i % Balance::DUNGEON_FLOORS) + 1;
            $dungeon = $dungeons[$i % count($dungeons)];
            $difficulty = $i % 2 === 0 ? 'easy' : 'hard';
            $seed = $this->floorSeedFor($i, $floor);

            [$ec, $er] = Dungeons::entrance($seed);
            $found = 0;

            for ($col = $ec - $radius; $col <= $ec + $radius; $col++) {
                for ($row = $er - $radius; $row <= $er + $radius; $row++) {
                    if (! Dungeons::inBounds($col, $row)) {
                        continue;
                    }

                    if (HexGeometry::distance($ec, $er, $col, $row) > $radius) {
                        continue;
                    }

                    if (Dungeons::monsterAt($seed, $dungeon, $difficulty, $floor, $col, $row) !== null) {
                        $found++;
                    }
                }
            }

            $worst = min($worst, $found);

            $this->assertGreaterThanOrEqual(
                Balance::DUNGEON_FLOOR_KILLS,
                $found,
                "seed {$i} ({$dungeon}, floor {$floor}) offers only {$found} inside radius {$radius}"
            );
        }

        $this->assertGreaterThanOrEqual(Balance::DUNGEON_FLOOR_KILLS, $worst);
    }

    /** The cohort is six, distinct, and bounded — that is what makes it a guarantee. */
    public function test_the_seeded_cohort_is_always_six_and_always_close(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $seed = $this->floorSeedFor($i);
            $hexes = Dungeons::seededHexes($seed);
            [$ec, $er] = Dungeons::entrance($seed);

            $this->assertCount(Balance::DUNGEON_FLOOR_KILLS, $hexes, "cohort short on seed {$i}");
            $this->assertCount(count($hexes), array_unique(array_map('serialize', $hexes)), 'two of the cohort on one hex');

            foreach ($hexes as [$col, $row]) {
                $this->assertTrue(Dungeons::inBounds($col, $row), 'cohort placed off the floor');
                $this->assertLessThanOrEqual(9, HexGeometry::distance($ec, $er, $col, $row));
                $this->assertNotSame([$ec, $er], [$col, $row], 'cohort standing on the landing');
            }
        }
    }

    /** You do not land in a fight, and the stair carries the guardian rather than a monster. */
    public function test_the_landing_and_the_stair_hold_no_ordinary_monster(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $seed = $this->floorSeedFor($i);
            [$ec, $er] = Dungeons::entrance($seed);
            [$sc, $sr] = Dungeons::stair($seed);

            $this->assertNull(Dungeons::monsterAt($seed, 'rootvault', 'easy', 1, $ec, $er));
            $this->assertNull(Dungeons::monsterAt($seed, 'rootvault', 'easy', 1, $sc, $sr));
        }
    }

    /** §9.6.2 -- the stair is a walk away, not a step. */
    public function test_the_stair_is_far_from_the_landing(): void
    {
        $want = (int) floor(Balance::DUNGEON_FLOOR_SIZE * 0.6);

        for ($i = 0; $i < 300; $i++) {
            $seed = $this->floorSeedFor($i);
            [$ec, $er] = Dungeons::entrance($seed);
            [$sc, $sr] = Dungeons::stair($seed);

            $this->assertGreaterThanOrEqual($want, HexGeometry::distance($ec, $er, $sc, $sr), "stair too close on seed {$i}");
        }
    }

    /**
     * §9.6.2 -- a country's five stand in that country's dungeon and nowhere else.
     *
     * Beastwarren is the exception and pools all four, because it belongs to no
     * country — and not the hunt roster, which §5.5 deliberately gives no pair at
     * all.
     */
    public function test_a_dungeon_fields_its_own_country(): void
    {
        foreach (Catalog::DUNGEONS as $site) {
            for ($tier = 1; $tier <= 4; $tier++) {
                $pool = Dungeons::poolFor($site['key'], $tier);
                $this->assertNotEmpty($pool, "{$site['key']} fields nothing at tier {$tier}");

                foreach ($pool as $key) {
                    $monster = Monsters::ROSTER[$key];
                    $this->assertSame($tier, $monster['tier']);

                    if ($site['biome'] !== null) {
                        $this->assertSame($site['biome'], $monster['biome'], "{$key} is not of {$site['key']}'s country");
                    }
                }
            }
        }

        $this->assertGreaterThan(
            count(Dungeons::poolFor('rootvault', 1)),
            count(Dungeons::poolFor('beastwarren', 1)),
            'the beast dungeon pools every country'
        );
    }

    /** §9.6.2 -- depth climbs the roster, and hard shifts the whole ladder up one. */
    public function test_depth_climbs_the_roster_and_hard_shifts_it(): void
    {
        $this->assertSame(1, Dungeons::tierFor(1, 'easy'));
        $this->assertSame(4, Dungeons::tierFor(Balance::DUNGEON_FLOORS, 'easy'));

        for ($floor = 1; $floor <= Balance::DUNGEON_FLOORS; $floor++) {
            $easy = Dungeons::tierFor($floor, 'easy');
            $hard = Dungeons::tierFor($floor, 'hard');

            $this->assertGreaterThanOrEqual($easy, $hard, "hard is softer than easy on floor {$floor}");
            $this->assertLessThanOrEqual(4, $hard, 'there is no tier five to promote into');

            if ($floor > 1) {
                $this->assertGreaterThanOrEqual(Dungeons::tierFor($floor - 1, 'easy'), $easy, 'the ladder went backwards');
            }
        }
    }

    /**
     * §9.6.4 -- the pool scales with the roster and the attack does not.
     *
     * That is the rule that keeps a party's bill flat per head: the guardian
     * answers once per living member rather than hitting N times as hard, so
     * scaling its attack as well would charge a party twice for being one.
     */
    public function test_a_guardian_grows_with_the_party_only_in_its_pool(): void
    {
        $solo = Dungeons::guardian('rootvault', 'easy', 10, 1);
        $six = Dungeons::guardian('rootvault', 'easy', 10, Balance::DUNGEON_PARTY_MAX);

        $this->assertSame($solo['attack'], $six['attack'], 'the guardian hit harder for the party being bigger');
        $this->assertSame($solo['defense'], $six['defense']);
        $this->assertSame($solo['hp'] * Balance::DUNGEON_PARTY_MAX, $six['hp']);
    }

    /** And it grows with depth, all five of them, on both contracts. */
    public function test_a_guardian_grows_with_depth(): void
    {
        foreach (array_keys(Dungeons::GUARDIANS) as $dungeon) {
            foreach (Dungeons::DIFFICULTIES as $difficulty) {
                $previous = 0;

                for ($floor = 1; $floor <= Balance::DUNGEON_FLOORS; $floor++) {
                    $guardian = Dungeons::guardian($dungeon, $difficulty, $floor);

                    $this->assertGreaterThan($previous, $guardian['hp'], "{$dungeon} {$difficulty} went backwards at floor {$floor}");
                    $this->assertSame(5, $guardian['tier'], 'a guardian is the tier above the roster');
                    $previous = $guardian['hp'];
                }

                $this->assertGreaterThan(
                    Dungeons::guardian($dungeon, 'easy', 10)['hp'],
                    Dungeons::guardian($dungeon, 'hard', 10)['hp'],
                    "{$dungeon} hard is no harder than easy"
                );
            }
        }
    }

    /** Every dungeon on the map has a guardian to put on its stair. */
    public function test_every_dungeon_has_a_guardian(): void
    {
        foreach (Catalog::DUNGEONS as $site) {
            $this->assertArrayHasKey($site['key'], Dungeons::GUARDIANS, "{$site['key']} has nothing on its stair");
        }

        $this->assertCount(count(Catalog::DUNGEONS), Dungeons::GUARDIANS, 'a guardian with no dungeon');
    }

    /** §9.6.2 -- six opens the floor, and the guardian rouses at five. */
    public function test_the_gate_opens_at_six_and_the_guardian_rouses_at_five(): void
    {
        $six = Balance::DUNGEON_FLOOR_KILLS;

        $this->assertFalse(Dungeons::floorOpen($six - 1));
        $this->assertTrue(Dungeons::floorOpen($six));
        $this->assertTrue(Dungeons::floorOpen($six + 4));

        $this->assertFalse(Dungeons::guardianRoused($six - 2));
        $this->assertTrue(Dungeons::guardianRoused($six - 1), 'five have fallen and it still will not rouse');

        // The guardian is the sixth: rousing at five means killing it opens the
        // floor exactly, with no errands left over.
        $this->assertTrue(Dungeons::floorOpen(($six - 1) + 1));
    }
}
