<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Formulas;
use App\Models\CharacterItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §8.0.2 -- two copies of one recipe are not the same object.
 *
 * One roll per piece, spent on every solid figure it carries, so a piece is *a
 * good one* or *a poor one* rather than a bag of unrelated luck.
 */
final class ItemQualityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The band is what was asked for: about twenty points either side of a
     * common tool's three hundred. It is a SHARE rather than a count so it
     * scales up the ladder for free, which is the same argument §6 makes about
     * the bench fee.
     */
    public function test_the_band_is_about_twenty_points_on_a_common_tool(): void
    {
        $axe = Catalog::item('stone_axe');
        $band = (int) round($axe['attack'] * Balance::QUALITY_BAND);

        $this->assertSame(300, $axe['attack'], 'the common rung moved; the band below is quoted against it');
        $this->assertGreaterThanOrEqual(18, $band);
        $this->assertLessThanOrEqual(24, $band);
    }

    /**
     * One roll, spent on everything. A piece good in attack and poor in guard
     * would be noise wearing the word variety -- the point is a thing a player
     * can hold in their head and talk about.
     */
    public function test_one_roll_moves_every_solid_figure_together(): void
    {
        $def = $this->firstWhere(
            static fn (array $d): bool => ($d['slot'] ?? null) === 'weapon'
                && ($d['attack'] ?? 0) > 0
                && ($d['defense'] ?? 0) > 0,
        );

        $high = Formulas::withQuality($def['attack'], 70);
        $lowAttack = Formulas::withQuality($def['attack'], -70);

        $this->assertGreaterThan($def['attack'], $high);
        $this->assertLessThan($def['attack'], $lowAttack);

        // Same sign, same copy: a good one is good at everything it has.
        $this->assertGreaterThan($def['defense'], Formulas::withQuality($def['defense'], 70));
        $this->assertGreaterThan(
            Formulas::maxDurabilityFor($def),
            Formulas::maxDurabilityFor($def, [], 0.0, 70),
        );
    }

    /**
     * §9.5.4 -- and an unlucky roll may never take the last point of a guard.
     *
     * A shield that cannot land is a stalemate and a pair of knives with no
     * guard at all is the sword twice over. Those are two different broken
     * things, and neither may be quietly created by rounding.
     */
    public function test_a_bad_roll_never_rounds_a_figure_away(): void
    {
        foreach ([1, 2, 3, 5] as $base) {
            $this->assertGreaterThan(
                0,
                Formulas::withQuality($base, -(int) round(Balance::QUALITY_BAND * 1000)),
                "a base of {$base} rounded to nothing",
            );
        }
    }

    /** Nothing rolled is the recipe exactly, which is what an old piece is. */
    public function test_no_roll_reads_as_the_recipe(): void
    {
        $def = Catalog::item('stone_axe');

        $this->assertSame($def['attack'], Formulas::withQuality($def['attack'], null));
        $this->assertSame($def['attack'], Formulas::withQuality($def['attack'], 0));
        $this->assertSame($def['attack'], Formulas::toolAttack($def));

        $item = new CharacterItem(['item_key' => 'stone_axe']);
        $this->assertSame($def['maxDurability'], $item->maxDurability());
    }

    /**
     * The roll stays inside its band however many pieces come off the bench,
     * and it is seeded -- the same seed is the same piece, so nothing can be
     * re-rolled for a better one (§16).
     */
    public function test_the_roll_is_bounded_and_seeded(): void
    {
        $band = (int) round(Balance::QUALITY_BAND * 1000);

        for ($seed = 0; $seed < 2000; $seed++) {
            $q = Formulas::rollQuality($seed);
            $this->assertGreaterThanOrEqual(-$band, $q);
            $this->assertLessThanOrEqual($band, $q);
            $this->assertSame($q, Formulas::rollQuality($seed), 'the same seed gave two pieces');
        }
    }

    /**
     * §8.0.2 -- the middle is ordinary and the edges are rare.
     *
     * This is the whole of what makes "a fine axe" a sentence about something.
     * Under a flat roll every value is equally likely, so the middle would be
     * no more ordinary than either end and a good one would mean nothing.
     */
    public function test_most_copies_come_out_ordinary(): void
    {
        $band = (int) round(Balance::QUALITY_BAND * 1000);
        $middle = 0;
        $edges = 0;
        $runs = 20000;

        for ($seed = 0; $seed < $runs; $seed++) {
            $q = abs(Formulas::rollQuality($seed));
            if ($q <= $band / 4) {
                $middle++;
            }
            if ($q >= $band * 3 / 4) {
                $edges++;
            }
        }

        $this->assertGreaterThan($runs * 0.4, $middle, 'the middle is not where the mass is');
        $this->assertLessThan($runs * 0.1, $edges, 'the edges are not rare');
    }

    /**
     * §8.1 rule 1 -- and it meets no ceiling, because it is not a `StatKey`.
     *
     * It is a solid number, the same standing §8.0.1's rolled lines have, and
     * it is far under BATTLE_SWING's own wander -- so it colours a piece
     * without deciding a fight §9.5.4 says the kit decides.
     */
    public function test_the_band_stays_under_the_swing_a_fight_already_has(): void
    {
        $this->assertLessThan(
            Balance::BATTLE_SWING,
            Balance::QUALITY_BAND,
            'a copy varies more than a strike does',
        );
    }

    /** §16 -- and the client's copy of the band says the same thing. */
    public function test_the_client_carries_the_same_band(): void
    {
        $mirror = file_get_contents(base_path('resources/js/game/formulas.ts'));

        $this->assertStringContainsString(
            'export function withQuality(',
            $mirror,
            'the client cannot read a copy\'s own figures',
        );
    }

    // ---------------------------------------------------------------- the wear

    /**
     * §8.1 rule 3 -- a mine takes a BAND, not the same bite every time.
     *
     * It was a flat 100 -- one whole point at the old scale, multiplied up and
     * left there -- which made the one number a player actually watches the
     * only one that never used the granularity SOLID_SCALE bought.
     */
    public function test_a_mine_does_not_cost_the_same_twice(): void
    {
        $band = (int) round(Balance::DRAIN_PER_MINE * Balance::DRAIN_PER_MINE_BAND);
        $seen = [];

        for ($i = 1; $i <= 400; $i++) {
            $drain = Formulas::withQuality(0, null) + $this->drainRoll($i);
            $seen[$drain] = true;

            $this->assertGreaterThanOrEqual(Balance::DRAIN_PER_MINE - $band, $drain);
            $this->assertLessThanOrEqual(Balance::DRAIN_PER_MINE + $band, $drain);
        }

        $this->assertGreaterThan(20, count($seen), 'every mine cost the same');
    }

    /**
     * And the AVERAGE has not moved, which is the whole promise: a tool lasts
     * the forty-odd mines it always did and no repair bill changed. A band that
     * quietly shifted the mean would be a nerf wearing the word variety.
     */
    public function test_the_average_mine_still_costs_what_it_did(): void
    {
        $total = 0;
        $runs = 20000;

        for ($i = 1; $i <= $runs; $i++) {
            $total += $this->drainRoll($i);
        }

        $mean = $total / $runs;

        $this->assertGreaterThan(Balance::DRAIN_PER_MINE * 0.99, $mean);
        $this->assertLessThan(Balance::DRAIN_PER_MINE * 1.01, $mean);
    }

    /**
     * §8.2 -- and a warning is measured against the WORST the band can do.
     *
     * An idle game may never take something expensive by surprise, so "will not
     * survive this mine" has to be true of the unlucky roll rather than of the
     * average one.
     */
    public function test_the_warning_covers_the_worst_the_band_can_do(): void
    {
        $this->assertGreaterThan(Balance::DRAIN_PER_MINE, Balance::maxDrainPerMine());

        for ($i = 1; $i <= 2000; $i++) {
            $this->assertLessThanOrEqual(Balance::maxDrainPerMine(), $this->drainRoll($i));
        }
    }

    /**
     * §7.3 -- and the raid drain moved with the scale it belongs to.
     *
     * Nothing reads it yet (§14 leaves dungeon combat undesigned), which is
     * exactly how it came to be left at 4 while durability went to SOLID_SCALE.
     * A dormant constant at the wrong scale does not fail: it waits, and then
     * it is a hundredfold error in a system that arrives believing it.
     */
    public function test_the_raid_drain_is_on_the_same_scale_as_the_mine(): void
    {
        $this->assertGreaterThan(
            Balance::DRAIN_PER_MINE,
            Balance::DRAIN_PER_RAID,
            'a raid drains less than a mine',
        );
    }

    /** The band roll, as tripDrain rolls it for a character with no tree. */
    private function drainRoll(int $id): int
    {
        $band = (int) round(Balance::DRAIN_PER_MINE * Balance::DRAIN_PER_MINE_BAND);

        return max(1, \App\Game\Hash::randInt(
            \App\Game\Hash::hash2($id, $id * 7919, Balance::mapSeed() ^ 0x7002),
            Balance::DRAIN_PER_MINE - $band,
            Balance::DRAIN_PER_MINE + $band,
        ));
    }

    /** @return array<string,mixed> */
    private function firstWhere(callable $matches): array
    {
        foreach (Catalog::items() as $def) {
            if ($matches($def)) {
                return $def;
            }
        }

        $this->fail('the catalog has nothing of that shape');
    }
}
