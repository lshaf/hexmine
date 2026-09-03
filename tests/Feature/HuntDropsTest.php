<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Drops;
use App\Game\Hunts;
use App\Game\WorldGen;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

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

    /**
     * §4 / §2 -- the contested rung is a Tier 3 and it is capped per wallet.
     *
     * It is the gate the Beastfang Bow, the Beastfang Boots, Farshot and
     * Leaguewalkers all stand behind, and every one of those is mintable. §2
     * requires a per-wallet cap behind anything that can leave the game, so a
     * Tier 1 uncapped hide feeding an epic is the grind->NFT path the threat
     * model exists to close.
     *
     * It became Tier 1 by accident: the four biome ladders emit their base
     * grades at tier 1 and Catalog hand-lists their Tier 3 separately, and when
     * this ladder moved out of gen_variants.py it emitted all four of its rungs
     * itself -- every one of them tier 1.
     */
    public function test_the_contested_hide_is_a_capped_tier_three(): void
    {
        $def = Catalog::materials()[Hunts::GRADES['contested']['material']];

        $this->assertSame(3, $def['tier']);
        $this->assertSame(Balance::RARE_WALLET_CAP, $def['walletCap'] ?? null);
        $this->assertSame(0, $def['npcPrice'], 'a capped rare must have no gold price');
    }

    /** And the rungs under it are not, because Tier 1 is never capped. */
    public function test_the_rungs_below_it_are_plain_tier_one(): void
    {
        foreach (Hunts::GRADES as $grade => $rung) {
            if ($grade === 'contested') {
                continue;
            }

            $def = Catalog::materials()[$rung['material']];
            $this->assertSame(1, $def['tier'], "{$rung['material']} is not Tier 1");
            $this->assertArrayNotHasKey('walletCap', $def);
        }
    }

    /**
     * §5.5 -- a hunt is a mine, so it is reported as one.
     *
     * It fell through jobPayload's bench branch and came back as a processing
     * job standing at no settlement -- no col, no row, no material. The client
     * sorts a field job on `mining|battle` and a bench job on
     * `processing|craft`, so a hunting job matched neither and a started hunt
     * was invisible from that moment on, with no way to claim it.
     */
    public function test_a_hunt_is_reported_as_a_job_on_a_hex(): void
    {
        config(['game.packs' => false]);

        $game = app(\App\Game\GameService::class);
        $character = $game->createCharacter(\App\Models\Player::create(['wallet' => '0xpayload']));

        for ($i = 0; $i < 2000; $i++) {
            $col = ($i * 7919) % 400 - 200;
            $row = ($i * 104729) % 400 - 200;
            $character->update(['col' => $col, 'row' => $row]);
            $fresh = $character->fresh();

            if ($game->huntHere($fresh) === null) {
                continue;
            }

            $job = $game->startMining($fresh, $col, $row, Drops::HUNTING);
            $payload = $game->jobPayload($job);

            $this->assertSame(Drops::HUNTING, $payload['kind']);
            $this->assertSame($col, $payload['col'], 'a hunt came back with no hex');
            $this->assertSame($row, $payload['row']);
            $this->assertArrayHasKey('material', $payload, 'a hunt came back with no haul');
            // And none of the bench keys, which is what it used to answer with.
            $this->assertArrayNotHasKey('settlementName', $payload);
            $this->assertArrayNotHasKey('recipeKey', $payload);

            return;
        }

        $this->fail('found nowhere with an animal on it');
    }

    /**
     * §4 -- all three verbs answer `drops` in one shape: a list of keys, most
     * likely first.
     *
     * The hunt answered with the weighted TABLE instead -- an object where the
     * other two send an array -- so the tile card, which is the one screen that
     * reads all three, had nothing to slice. And the first attempt at the fix
     * put HUNTING through `kinds()`, which reads the ground's grade against the
     * tool's and fell through to the gather table: chaff and thistle where the
     * hide should have been. Both are pinned here because both looked right
     * until the list was read.
     */
    public function test_all_three_verbs_answer_drops_in_one_shape(): void
    {
        config(['game.packs' => false]);

        $game = app(\App\Game\GameService::class);
        $character = $game->createCharacter(\App\Models\Player::create(['wallet' => '0xshape']));

        for ($i = 0; $i < 2000; $i++) {
            $col = ($i * 7919) % 400 - 200;
            $row = ($i * 104729) % 400 - 200;
            $character->update(['col' => $col, 'row' => $row]);
            $fresh = $character->fresh();

            if ($game->huntHere($fresh) === null) {
                continue;
            }

            $mine = $game->previewTile($fresh, $col, $row);
            $gather = $game->previewGather($fresh, $col, $row);
            $hunt = $game->previewTile($fresh, $col, $row, Drops::HUNTING);

            foreach (['mine' => $mine, 'gather' => $gather, 'hunt' => $hunt] as $verb => $preview) {
                $this->assertArrayHasKey('drops', $preview);
                $this->assertIsList($preview['drops'], "{$verb} sent something other than a list");
                $this->assertNotEmpty($preview['drops'], "{$verb} sent nothing");

                foreach ($preview['drops'] as $key) {
                    $this->assertIsString($key, "{$verb} sent a weight where a key belongs");
                    $this->assertArrayHasKey($key, Catalog::materials(), "{$verb} named {$key}");
                }
            }

            // And the hunt's list is the ANIMAL's, not the ground's: the hide
            // leads it, and nothing off the gather table is on it.
            $this->assertSame(
                $hunt['material'],
                $hunt['drops'][0],
                'the hunt did not lead with what the animal gives up',
            );
            $this->assertNotContains(
                Catalog::BIOME_SCRAP[WorldGen::generateTile($col, $row, 0)['biome']],
                $hunt['drops'],
                'the hunt was costed off the ground',
            );

            return;
        }

        $this->fail('found nowhere with an animal on it');
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
