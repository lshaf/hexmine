<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Catalog;
use App\Game\Drops;
use App\Game\Hunts;
use App\Game\WorldGen;
use Tests\TestCase;

/**
 * §5.5 -- what a kill pays, and what the GRADE pays on top of it.
 *
 * The two here came off plains ground as that country's §9.5.8 stock and had
 * nowhere to go when the country was folded into a line. Folding a biome away
 * must not cost the game a material, so they moved onto the animal -- which is
 * where the sentence on the sinew was always pointing anyway.
 */
final class HuntDropsTest extends TestCase
{
    /** One tile per grade, taken off the real map. */
    private function tileForEachGrade(): array
    {
        // Contiguous rather than strided, and reaching from the centre out:
        // the top two rungs are inner-ring only (§2 keeps Beastfang Hide off
        // the safe rim), so a stride that never lands near the middle simply
        // never sees them.
        $out = [];
        for ($col = -60; $col <= 60 && count($out) < 4; $col++) {
            for ($row = -60; $row <= 60; $row++) {
                $tile = WorldGen::generateTile($col, $row, 0);
                $hunt = $tile['hunt'] ?? null;
                if ($hunt !== null && ! isset($out[$hunt['grade']])) {
                    $out[$hunt['grade']] = $tile;
                }
            }
        }

        return $out;
    }

    /**
     * §5.5 -- a Roe Deer never carries the graded part and everything above it
     * does. That is what makes the ladder pay in a KIND of drop as well as in a
     * rung of hide, and it is the reason the eight almanac entries differ by
     * something a player can act on.
     */
    public function test_the_graded_part_is_off_the_common_rung_and_on_every_rung_above(): void
    {
        $tiles = $this->tileForEachGrade();
        $this->assertCount(4, $tiles, 'the sample missed a grade');

        foreach ($tiles as $grade => $tile) {
            $material = Hunts::ROSTER[$tile['hunt']['key']]['material'];
            $table = Drops::tableFor(Drops::HUNTING, $tile, $material);

            $this->assertSame(
                $grade !== 'common',
                array_key_exists(Hunts::GRADED_PART, $table),
                "the graded part is wrong on the {$grade} rung",
            );
        }
    }

    /** §9.5.8 -- and the leaving turns up whatever the rung, bow or no bow. */
    public function test_every_kill_can_leave_the_ground_it_happened_on(): void
    {
        foreach ($this->tileForEachGrade() as $grade => $tile) {
            $material = Hunts::ROSTER[$tile['hunt']['key']]['material'];

            foreach ([$material, Catalog::HUNT_SCRAP] as $primary) {
                $this->assertArrayHasKey(
                    Hunts::LEAVING,
                    Drops::tableFor(Drops::HUNTING, $tile, $primary),
                    "no leaving off a {$grade} animal taken for {$primary}",
                );
            }
        }
    }

    /**
     * §4.0 -- bare hands still get nothing off a bench. The parts and the
     * graded part alike need a bow; the hide comes back as scrap and the
     * rubbish comes back regardless.
     */
    public function test_bare_hands_bring_home_no_bench_stock(): void
    {
        $tiles = $this->tileForEachGrade();
        $tile = $tiles['contested'] ?? reset($tiles);

        $table = Drops::tableFor(Drops::HUNTING, $tile, Catalog::HUNT_SCRAP);

        foreach ([...Hunts::PARTS, Hunts::GRADED_PART] as $key) {
            $this->assertArrayNotHasKey($key, $table, "{$key} came home bare-handed");
        }
    }

    /** Both are in the catalog, on the hunt, and belong to no country. */
    public function test_the_two_restored_materials_are_hunt_sourced(): void
    {
        $materials = Catalog::materials();

        foreach ([Hunts::GRADED_PART, Hunts::LEAVING] as $key) {
            $this->assertArrayHasKey($key, $materials, "{$key} is not in the catalog");
            $this->assertSame('hunt', $materials[$key]['source'] ?? null);
            $this->assertArrayNotHasKey('biome', $materials[$key], "{$key} claims a country");
        }
    }
}
