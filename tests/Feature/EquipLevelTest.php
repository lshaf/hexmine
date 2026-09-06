<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\GameException;
use App\Game\GameService;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §7.1/§8.0 -- a rung has a level on it, and there is no jumping it.
 */
final class EquipLevelTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = app(GameService::class);
    }

    /**
     * §12 -- the opening arc is worked in common gear at level 1, so common
     * must never be gated. A soft-locked tutorial is the one failure this rule
     * could actually cause.
     */
    public function test_common_is_wearable_from_the_first_level(): void
    {
        $this->assertSame(1, Balance::equipLevel('common'));
    }

    /** And every rung above it is strictly later than the one under it. */
    public function test_the_gates_climb_with_the_ladder(): void
    {
        $seen = 0;
        foreach (Balance::RARITIES as $rarity) {
            $need = Balance::equipLevel($rarity);
            $this->assertGreaterThan($seen, $need, "{$rarity} did not climb");
            $seen = $need;
        }

        // §7.4.4's cap. A gate nobody can reach is a rung nobody can wear.
        $this->assertLessThanOrEqual(100, $seen);
    }

    /** §7.1 -- and a character under the gate is refused at the belt. */
    public function test_a_rung_above_your_level_is_refused(): void
    {
        [$character, $item] = $this->owning('mythril_pickaxe');

        $this->assertSame('epic', Catalog::item('mythril_pickaxe')['rarity']);

        try {
            $this->game->equipItem($character, $item->id);
            $this->fail('an epic went on at level 1');
        } catch (GameException $e) {
            $this->assertSame('level', $e->errorCode);
            // The refusal names the number, because a wall with no figure on it
            // is a bug as far as anybody reading it can tell.
            $this->assertStringContainsString((string) Balance::equipLevel('epic'), $e->getMessage());
        }

        $this->assertFalse((bool) $item->fresh()->equipped);
    }

    /** And goes on the moment the level is reached, with nothing else changed. */
    public function test_the_same_piece_goes_on_at_the_gate(): void
    {
        [$character, $item] = $this->owning('mythril_pickaxe');

        $character->update(['level' => Balance::equipLevel('epic')]);

        $this->game->equipItem($character->fresh(), $item->id);

        $this->assertTrue((bool) $item->fresh()->equipped);
    }

    /**
     * §8.1 rule 4 -- F2P viability. Every rung below unique stays reachable by
     * crafting, so a gate may delay a rung and may never put one out of reach:
     * the top gate has to sit inside the level cap with room to play in.
     */
    public function test_the_ladder_leaves_a_career_room_above_it(): void
    {
        $this->assertLessThan(
            100,
            Balance::equipLevel('unique'),
            'the top rung is gated at or past the end of a career',
        );
    }

    /**
     * §16 -- and the client's copy says the same thing.
     *
     * The gate is enforced on the server and DRAWN on the client -- a chip on
     * every piece of gear, ember when it cannot be met -- so the two carry the
     * same table. A drift here is the worst kind: the shop would offer a rung
     * the belt then refuses, and nothing would fail until a player tapped it.
     */
    public function test_the_client_carries_the_same_ladder(): void
    {
        $mirror = file_get_contents(base_path('resources/js/game/balance.ts'));

        foreach (Balance::EQUIP_LEVEL as $rarity => $level) {
            $this->assertStringContainsString(
                "{$rarity}: {$level},",
                $mirror,
                "balance.ts disagrees with Balance::EQUIP_LEVEL['{$rarity}']",
            );
        }
    }

    /**
     * And so does the generator, which is where a monster's level is decided.
     *
     * §9.5.2 quotes a monster on this ladder, and gen_monsters.py holds its own
     * literal copy to do it. That copy is the one place the two ladders meet:
     * if it drifts, every monster in the game is quoted on a scale nothing else
     * uses and no test on either side would notice.
     */
    public function test_the_monster_generator_carries_the_same_ladder(): void
    {
        $gen = file_get_contents(base_path('scripts/gen_monsters.py'));

        foreach (Balance::EQUIP_LEVEL as $rarity => $level) {
            if ($rarity === 'unique') {
                continue;  // never a monster band: nothing is crafted at it (§8.0).
            }

            $this->assertStringContainsString(
                "'{$rarity}': {$level}",
                $gen,
                "gen_monsters.py disagrees with Balance::EQUIP_LEVEL['{$rarity}']",
            );
        }
    }

    /** @return array{0:Character,1:CharacterItem} */
    private function owning(string $key): array
    {
        $player = Player::create(['wallet' => '0x'.bin2hex(random_bytes(8))]);
        $character = Character::create([
            'player_id' => $player->id,
            'name' => 'Gated',
            'col' => 0, 'row' => 0, 'level' => 1, 'xp' => 0, 'gold' => 0,
        ]);

        $item = CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => $key,
            'durability' => Catalog::item($key)['maxDurability'],
            'equipped' => false,
        ]);

        return [$character->fresh(), $item];
    }
}
