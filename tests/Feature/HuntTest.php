<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Catalog;
use App\Game\Drops;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Hunts;
use App\Game\Packs;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §5.5 -- the hunt. What mining a plains hex used to be.
 */
final class HuntTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    protected function setUp(): void
    {
        parent::setUp();

        // §9.5.3 -- quiet roads. A pack pins the hex and refuses the hunt with
        // it, which is correct and is asserted on its own below; left on, it
        // would refuse these tests on a schedule nobody can see.
        config(['game.packs' => false]);

        $this->game = app(GameService::class);
    }

    /**
     * §5.5 -- an animal stands on every workable hex of its two countries, and
     * on no hex of any other. That is the whole difference from a pack, which
     * is a chance per ring because it is a hazard rather than a line's ground.
     */
    public function test_an_animal_stands_on_forest_and_grassland_and_nowhere_else(): void
    {
        $seen = 0;

        for ($i = 0; $i < 900; $i++) {
            $col = ($i * 7919) % 400 - 200;
            $row = ($i * 104729) % 400 - 200;
            $tile = WorldGen::generateTile($col, $row, 0);
            $hunt = $tile['hunt'] ?? null;

            if ($hunt === null) {
                // The only hexes of those two countries without one are the
                // ones nothing stands on at all.
                if (in_array($tile['biome'], Hunts::BIOMES, true)) {
                    $this->assertTrue(
                        $tile['dead'] || $tile['water'] !== null
                            || $tile['settlement'] !== null || $tile['dungeon'] !== null,
                        "workable {$tile['biome']} at {$col},{$row} carries no animal",
                    );
                }

                continue;
            }

            $seen++;
            $this->assertContains($tile['biome'], Hunts::BIOMES, 'an animal stood on the wrong country');
            $this->assertSame(
                $tile['biome'],
                Hunts::ROSTER[$hunt['key']]['biome'],
                'the animal belongs to another country',
            );
        }

        $this->assertGreaterThan(50, $seen, 'no animals anywhere');
    }

    /**
     * §5.3/§2 -- the grade ladder moved onto the creature, weights and all, so
     * Beastfang Hide is still contested-only. A Tier 3 on the safe rim is the
     * grind->NFT path the threat model exists to close.
     */
    public function test_the_contested_rung_never_stands_on_a_safe_ring(): void
    {
        $contested = array_column(
            array_filter(Hunts::ROSTER, static fn (array $a) => $a['grade'] === 'contested'),
            'material',
        );

        $this->assertSame(['beastfang_hide', 'beastfang_hide'], $contested);

        for ($i = 0; $i < 1500; $i++) {
            $col = ($i * 5077) % 400 - 200;
            $row = ($i * 88883) % 400 - 200;
            $tile = WorldGen::generateTile($col, $row, 0);
            $hunt = $tile['hunt'] ?? null;

            if ($hunt === null || $hunt['grade'] !== 'contested') {
                continue;
            }

            $this->assertContains(
                $tile['ring'],
                ['inner', 'center'],
                "a contested animal stood on the {$tile['ring']} ring",
            );
        }
    }

    /**
     * §4.0 -- the bow is the whole difference, and it is the same bargain a
     * mine strikes. Same kill either way; a fraction of the worth without it.
     */
    public function test_a_bow_decides_what_the_kill_is_worth(): void
    {
        [$character, $hunt] = $this->standOnAnimal();

        $bare = $this->takeIt($character);
        $this->assertArrayHasKey(Catalog::HUNT_SCRAP, $bare['gained'], 'bare hands paid no scrap');

        // And the same animal, with a bow on the belt.
        [$armedCharacter, $armedHunt] = $this->standOnAnimal('0xbow');
        $this->equipBow($armedCharacter);

        $armed = $this->takeIt($armedCharacter);
        $this->assertArrayHasKey(
            $armedHunt['animal']['material'],
            $armed['gained'],
            'a bow did not bring the pelt home',
        );
        $this->assertArrayNotHasKey(Catalog::HUNT_SCRAP, $armed['gained']);
    }

    /**
     * §5.5/§9.5.1 -- the kill clears it for everybody, and there is no second
     * roll to wait for inside the bucket. That is the anti-farm rule, and it
     * needs no cooldown of its own.
     */
    public function test_a_kill_clears_the_hex_for_the_bucket(): void
    {
        [$character] = $this->standOnAnimal();

        $this->takeIt($character);

        $this->assertNull(
            $this->game->huntHere($character->fresh()),
            'the animal was still standing after it was taken',
        );

        // And the verb refuses on a hex it has just been spent on.
        $fresh = $character->fresh();
        $this->assertFalse(
            $this->game->previewTile($fresh, (int) $fresh->col, (int) $fresh->row, Drops::HUNTING)['canMine'],
        );
    }

    /** §5.5 -- and a hex with nothing on it refuses rather than paying. */
    public function test_a_hex_with_no_animal_refuses(): void
    {
        $character = $this->character('0xnone');

        // Somewhere with no animal on it: any hex whose tile carries none.
        for ($i = 0; $i < 900; $i++) {
            $col = ($i * 3571) % 400 - 200;
            $row = ($i * 65599) % 400 - 200;
            if ((WorldGen::generateTile($col, $row, 0)['hunt'] ?? null) === null) {
                $character->update(['col' => $col, 'row' => $row]);
                break;
            }
        }

        $this->assertNull($this->game->huntHere($character->fresh()));

        $fresh = $character->fresh();
        $preview = $this->game->previewTile($fresh, (int) $fresh->col, (int) $fresh->row, Drops::HUNTING);

        $this->assertFalse($preview['canMine']);
        $this->assertSame('Nothing to hunt here.', $preview['reason']);

        try {
            $this->game->startMining($fresh, (int) $fresh->col, (int) $fresh->row, Drops::HUNTING);
            $this->fail('hunted a hex with nothing on it');
        } catch (GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }
    }

    /** §7.2 -- a kill teaches the hunting line, and bare-handed teaches badly. */
    public function test_a_kill_teaches_the_hunting_line(): void
    {
        [$character] = $this->standOnAnimal();

        $result = $this->takeIt($character);

        $this->assertGreaterThan(0, array_sum($result['gained']));
        $this->assertGreaterThan(
            0,
            (int) $character->fresh()->skills()->where('skill_key', 'hunting')->value('level'),
        );
    }

    /**
     * §9.5.3 -- a pack stops a hunt the way it stops a mine.
     *
     * You are not working while something is looking at you, and an animal on
     * the same hex is still work. The refusal names the pack rather than the
     * animal, because the pack is what has to be dealt with first.
     */
    public function test_a_pack_on_the_hex_refuses_the_hunt(): void
    {
        config(['game.packs' => true]);

        $character = $this->character('0xpinned');

        // Asked through the service rather than off `generateTile(.., 0)`: a
        // pack is bucketed on the CURRENT clock, so a hex that carries one at
        // time zero need not carry one now.
        for ($i = 0; $i < 3000; $i++) {
            $col = ($i * 7919) % 400 - 200;
            $row = ($i * 104729) % 400 - 200;

            $character->update(['col' => $col, 'row' => $row]);
            $fresh = $character->fresh();

            if ($this->game->huntHere($fresh) === null || $this->game->packHere($fresh) === null) {
                continue;
            }

            $preview = $this->game->previewTile($fresh, $col, $row, Drops::HUNTING);

            $this->assertFalse($preview['canMine'], 'hunted a hex with a pack on it');
            $this->assertTrue($preview['pinned']);
            // And it still describes the ANIMAL, not the seam underneath.
            $this->assertNotNull($preview['animal']);

            return;
        }

        $this->markTestSkipped('found no hex with both a pack and an animal');
    }

    // ------------------------------------------------------------------ helpers

    private function character(string $wallet = '0xhunt'): Character
    {
        return $this->game->createCharacter(Player::create(['wallet' => $wallet]));
    }

    /** Put a character on a hex that has an animal, and hand back both. */
    private function standOnAnimal(string $wallet = '0xhunt'): array
    {
        $character = $this->character($wallet);

        for ($i = 0; $i < 2000; $i++) {
            $col = ($i * 7919) % 400 - 200;
            $row = ($i * 104729) % 400 - 200;
            $tile = WorldGen::generateTile($col, $row, 0);

            if (($tile['hunt'] ?? null) === null) {
                continue;
            }

            $character->update(['col' => $col, 'row' => $row]);
            $fresh = $character->fresh();
            $hunt = $this->game->huntHere($fresh);

            if ($hunt !== null) {
                return [$fresh, $hunt];
            }
        }

        $this->fail('found nowhere with an animal on it');
    }

    /**
     * §5.5 -- a hunt is a mine, so taking one is: start it, let the clock run
     * out, claim the haul. The clock is the server's, so it is moved rather
     * than waited on.
     */
    private function takeIt(Character $character): array
    {
        $fresh = $character->fresh();
        $job = $this->game->startMining($fresh, (int) $fresh->col, (int) $fresh->row, Drops::HUNTING);
        $job->update(['ends_at' => $this->game->now() - 1]);

        return $this->game->collectJob($character->fresh(), $job->id);
    }

    private function equipBow(Character $character): void
    {
        CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => 'crude_bow',
            'durability' => 40,
            'equipped' => true,
        ]);
    }
}
