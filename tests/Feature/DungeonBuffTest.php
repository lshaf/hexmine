<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\BattleGear;
use App\Game\Dungeons;
use App\Game\DungeonService;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\CharacterBuff;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §8.5 -- a draft is spent by the work it was armed for, underground included.
 *
 * A `battle` charge applies in a dungeon because `combatProfile()` reads the
 * same bonuses a road fight reads -- which is right, and is exactly what makes
 * forgetting to spend it dangerous. §8.5's rule is that nothing may be
 * permanent, and an unspent charge is permanent: it paid out on every fight on
 * every floor and was never consumed. §11.1 counts the spending as the sink, so
 * the bug was also a sink that never collected.
 */
final class DungeonBuffTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private DungeonService $dungeons;

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.packs' => false]);
        Dungeons::forget();

        $this->game = app(GameService::class);
        $this->dungeons = app(DungeonService::class);
    }

    private function fighterOnAMonster(string $wallet): Character
    {
        $site = WorldGen::dungeonSites()[0];
        $character = $this->game->createCharacter(Player::create(['wallet' => $wallet]));
        $character->col = $site['col'];
        $character->row = $site['row'];
        $character->save();

        foreach (BattleGear::ITEMS as $key => $def) {
            if ($def['rarity'] !== 'rare') {
                continue;
            }
            if ($character->items()->count() >= 4) {
                break;
            }
            CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => $key,
                'equipped' => true,
                'durability' => $def['maxDurability'],
                'max_durability' => $def['maxDurability'],
                'quality' => 0,
                'options' => [],
            ]);
        }

        $session = $this->dungeons->open($character, $site['dungeon']['key'], 'tools', 'easy');
        $this->dungeons->enter($character);

        [$col, $row] = Dungeons::seededHexes($session->fresh()->floorSeed(1))[0];
        $this->dungeons->memberFor($character, $this->game->now())
            ->fill(['col' => $col, 'row' => $row, 'busy_until_ms' => null])->save();

        return $character;
    }

    /** The charge applies underground, and is gone afterwards. */
    public function test_a_battle_draft_is_spent_by_a_dungeon_fight(): void
    {
        $character = $this->fighterOnAMonster('0xdraft');

        CharacterBuff::create([
            'character_id' => $character->id,
            'item_key' => 'warcry_draught',
            'stat' => 'power',
            'scope' => 'battle',
            'value' => 0.1,
        ]);

        $character->refresh();

        // It is read into the pair, which is why it has to be spent.
        $withDraft = $this->game->combatProfile($character)['attack'];

        $this->assertSame(
            1,
            CharacterBuff::where('character_id', $character->id)->where('scope', 'battle')->count(),
        );

        $this->dungeons->fight($character);

        $this->assertSame(
            0,
            CharacterBuff::where('character_id', $character->id)->where('scope', 'battle')->count(),
            'the draft survived the fight it was armed for, so it is permanent underground',
        );

        // And the next fight is fought without it.
        $character->refresh();
        $this->assertLessThan($withDraft, $this->game->combatProfile($character)['attack']);
    }

    /** A draft armed for other work is untouched: §8.5 scopes a charge to an action. */
    public function test_a_mining_draft_survives_a_dungeon_fight(): void
    {
        $character = $this->fighterOnAMonster('0xmining');

        CharacterBuff::create([
            'character_id' => $character->id,
            'item_key' => 'deepseam_draught',
            'stat' => 'yield',
            'scope' => 'mining',
            'value' => 0.1,
        ]);

        $this->dungeons->fight($character);

        $this->assertSame(
            1,
            CharacterBuff::where('character_id', $character->id)->where('scope', 'mining')->count(),
            'a dungeon fight drank a mining draft',
        );
    }
}
