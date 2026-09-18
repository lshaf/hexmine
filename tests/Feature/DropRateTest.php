<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Drops;
use App\Game\Monsters;
use Tests\TestCase;

/**
 * §9.5.8 -- the shape of what a win pays, rather than the figures in it.
 *
 * Every number here is tuning and may move. The ORDER is not: a piece of
 * equipment is worth more than any single material on the table and costs a
 * whole strap (§7.6), so it has to be the rarest thing a fight gives up. It was
 * not -- at 0.18 it turned up nearly twice as often as the rare spoils, which
 * made the best drop the commonest scarce one.
 */
final class DropRateTest extends TestCase
{
    /** @return array<string,float> rate per win, by drop key */
    private function rates(string $monsterKey, int $runs = 8000): array
    {
        $monster = Monsters::ROSTER[$monsterKey];
        $hits = [];

        for ($seed = 0; $seed < $runs; $seed++) {
            foreach (array_keys(Drops::battleSpoils($monster, $seed, 0.0, [])) as $key) {
                $hits[$key] = ($hits[$key] ?? 0) + 1;
            }

            if (Drops::lootedGear($monster, $seed) !== null) {
                $hits['gear'] = ($hits['gear'] ?? 0) + 1;
            }
        }

        return array_map(static fn (int $n): float => $n / $runs, $hits);
    }

    /** Gear is the rarest thing a fight pays, on every tier. */
    public function test_equipment_is_the_rarest_drop(): void
    {
        foreach (['moss_hound', 'thornback', 'rootbound_elder', 'pale_stalker'] as $key) {
            $rates = $this->rates($key);

            $this->assertArrayHasKey('gear', $rates, "{$key} never dropped its kit at all");

            $gear = $rates['gear'];
            unset($rates['gear']);

            foreach ($rates as $drop => $rate) {
                $this->assertLessThan(
                    $rate,
                    $gear,
                    "{$key}: gear ({$gear}) is commoner than {$drop} ({$rate})",
                );
            }
        }
    }

    /** And it is still a drop: rare is not the same as never. */
    public function test_equipment_still_drops_often_enough_to_be_a_drop(): void
    {
        $gear = $this->rates('moss_hound')['gear'];

        $this->assertGreaterThan(0.02, $gear, 'gear is so rare it may as well not exist');
        $this->assertLessThan(0.10, $gear, 'gear has crept back up the table');
    }
}
