<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Formulas;
use App\Game\GameService;
use App\Game\HexGeometry;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedDemoCharacter extends Command
{
    protected $signature = 'game:demo
        {--session= : Bind to this session id instead of the most recently active one}
        {--wallet= : Bind to this wallet instead of the session-derived one}
        {--fresh : Delete the existing character and mint a new one before seeding}';

    protected $description = 'Give a character a mid-game kit so every screen can be exercised';

    /**
     * §7.6 -- a kit that fits in the bag, because everything now has to.
     *
     * Six stacks, not twenty. Units are the limit that bites (§7.6), and these
     * six plus three potions and three unworn items land at 99 of 120 -- a bag
     * with a haul or two of room in it, which is the state most screens want to
     * be shown in. A demo character who cannot walk would be a demo of the
     * wrong thing.
     *
     * One stack per tier that has one, so every §4 band still has something to
     * render on the bag screen. Heartknot is the sixth: no recipe on the weapon
     * bench is component-free any more, so a kit without one cannot craft.
     *
     * @var array<string,int>
     */
    private const MATERIALS = [
        'wood' => 36,
        'iron_ore' => 24,
        'heartknot' => 8,

        'planks' => 16,

        'ironwood' => 5,

        'essence' => 4,
    ];

    /** @var array<string,int> */
    private const SKILLS = [
        'woodcutting' => 12,
        'mining' => 10,
        'quarrying' => 6,
        'hunting' => 5,
        'harvesting' => 5,
    ];

    /**
     * §8 -- one tool per gathering line, so the kit fills three of the five and
     * deliberately leaves the sickle line bare. An empty line is a state the
     * hero sheet has to render, and it is the one the old single-tool kit could
     * never produce.
     *
     * @var array<int,array{key:string,durability:int,equipped:bool}>
     */
    private const ITEMS = [
        ['key' => 'ironbound_axe', 'durability' => 88, 'equipped' => true],
        ['key' => 'mythril_pickaxe', 'durability' => 168, 'equipped' => true],
        ['key' => 'crude_bow', 'durability' => 31, 'equipped' => true],
        ['key' => 'leather_armor', 'durability' => 108, 'equipped' => true],
        ['key' => 'work_gloves', 'durability' => 61, 'equipped' => true],
        ['key' => 'reinforced_boots', 'durability' => 140, 'equipped' => false],
        ['key' => 'stone_maul', 'durability' => 44, 'equipped' => false],
        ['key' => 'stone_axe', 'durability' => 0, 'equipped' => false],
    ];

    /**
     * §8.5 -- a shelf of potions, one already running so the "Refresh" state on
     * the bag has something to render.
     *
     * @var array<string,int>
     */
    private const CONSUMABLES = [
        'forest_draught' => 4,
        'road_tonic' => 2,
        'quarry_salts' => 3,
    ];

    private const LEVEL = 8;

    private const GOLD = 4200;

    private const PROCESSING_BATCHES = 2;

    public function handle(GameService $game): int
    {
        $sessionId = $this->resolveSessionId();
        $named = $this->option('wallet');
        $named = is_string($named) && $named !== '' ? $named : null;
        $wallet = $named ?? $this->walletForSession($sessionId);

        if ($wallet === null) {
            $this->components->error('No session to bind to.');
            $this->line('  Open the app once so a session cookie exists, then run this again.');
            $this->line('  Or name the wallet yourself: <options=bold>php artisan game:demo --wallet=0xdemo</>');

            return self::FAILURE;
        }

        $player = $this->resolvePlayer($wallet, $sessionId, $named !== null);

        if ($this->option('fresh') && $player->character !== null) {
            $player->character->delete();
            $player->unsetRelation('character');
        }

        $character = $player->character ?? $game->createCharacter($player);

        $summary = DB::transaction(fn () => $this->applyKit($game, $character));
        $bag = $game->bag($character->fresh());

        $this->components->info("Kitted out {$character->name} at ({$character->col}, {$character->row}).");
        $this->table(['', ''], [
            ['wallet', $player->wallet],
            ['session', $sessionId ?? 'unbound — rerun without --wallet once the app has opened a session'],
            ['level', (string) self::LEVEL],
            ['gold', (string) self::GOLD],
            ['materials', count(self::MATERIALS).' kinds, '.array_sum(self::MATERIALS).' units'],
            ['equipment', count(self::ITEMS).' items, '
                .count(array_filter(self::ITEMS, fn (array $i) => $i['equipped'])).' equipped, '
                .count(array_filter(self::ITEMS, fn (array $i) => $i['durability'] === 0)).' broken'],
            // §7.6 -- the one number that decides whether this character can
            // leave the hex it was seeded on. Printed so a kit that outgrows the
            // bag is caught here rather than in the app.
            ['bag', "{$bag['units']}/{$bag['unitCap']} units, {$bag['rows']}/{$bag['rowCap']} rows"
                .($bag['over'] ? '  — OVERLOADED, this character cannot travel' : '')],
            ['jobs', $summary['jobs']],
        ]);

        $this->line('  Reload the app to see it. Timers run at <options=bold>'.Balance::timeScale().'x</> — set GAME_TIME_SCALE in .env to compress them.');

        return self::SUCCESS;
    }

    private function resolveSessionId(): ?string
    {
        $explicit = $this->option('session');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if (config('session.driver') !== 'database') {
            return null;
        }

        $id = DB::table(config('session.table', 'sessions'))
            ->orderByDesc('last_activity')
            ->value('id');

        return is_string($id) ? $id : null;
    }

    private function walletForSession(?string $sessionId): ?string
    {
        return $sessionId === null ? null : '0x'.substr(hash('sha256', $sessionId), 0, 40);
    }

    private function resolvePlayer(string $wallet, ?string $sessionId, bool $walletNamed): Player
    {
        $player = Player::where('wallet', $wallet)->first();

        if ($player === null && ! $walletNamed && $sessionId !== null) {
            $player = Player::where('session_id', $sessionId)->first();
        }

        if ($player === null) {
            $player = new Player(['wallet' => $wallet]);
        }

        if ($sessionId !== null) {
            Player::where('session_id', $sessionId)
                ->when($player->exists, fn ($q) => $q->where('id', '!=', $player->id))
                ->update(['session_id' => null]);

            $player->session_id = $sessionId;
        }

        $player->eligible_since = null;
        $player->save();

        return $player;
    }

    /** @return array{jobs:string} */
    private function applyKit(GameService $game, Character $character): array
    {
        $now = $game->now();

        $character->level = self::LEVEL;
        $character->xp = (int) round(Balance::xpForLevel(self::LEVEL) * 0.42);
        $character->gold = self::GOLD;
        $character->presence_settlement_id = null;
        $character->save();

        $character->jobs()->delete();
        $character->items()->delete();
        $character->materials()->delete();
        $character->consumables()->delete();
        $character->buffs()->delete();

        foreach (self::CONSUMABLES as $key => $qty) {
            $character->consumables()->create(['item_key' => $key, 'quantity' => $qty]);
        }

        // One already armed, so the HUD's charge strip has something to show.
        $draught = Catalog::item('forest_draught');
        $character->buffs()->create([
            'item_key' => 'forest_draught',
            'stat' => $draught['stat'],
            'scope' => $draught['scope'] ?? 'global',
            'value' => $draught['value'],
        ]);

        $materials = self::MATERIALS;

        // §8.0.1 -- roll each item's bonus lines rather than leaving them bare,
        // so the hero sheet has rolled lines to render. Seeded per index, so a
        // reseed produces the same kit and screenshots stay comparable.
        foreach (self::ITEMS as $index => $item) {
            $def = Catalog::item($item['key']);

            CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => $item['key'],
                'durability' => $item['durability'],
                'equipped' => $item['equipped'],
                'options' => Formulas::rollOptions($def, 31 + $index),
            ]);
        }

        foreach (self::SKILLS as $key => $level) {
            $character->skills()->where('skill_key', $key)->update([
                'level' => $level,
                'xp' => (int) round(Balance::skillXpForLevel($level) * 0.3),
            ]);
        }

        $jobs = [];

        $mining = $this->seedMiningTrip($game, $character, $now);
        if ($mining !== null) {
            $jobs[] = $mining;
        }

        $processing = $this->seedProcessingBatch($game, $character, $now, $materials);
        if ($processing !== null) {
            $jobs[] = $processing;
        }

        foreach ($materials as $key => $quantity) {
            if ($quantity > 0) {
                CharacterMaterial::create([
                    'character_id' => $character->id,
                    'material_key' => $key,
                    'quantity' => $quantity,
                ]);
            }
        }

        return ['jobs' => $jobs === [] ? 'none' : implode(', ', $jobs)];
    }

    private function seedMiningTrip(GameService $game, Character $character, int $now): ?string
    {
        $preview = $game->previewTile($character, $character->col, $character->row);

        if (($preview['material'] ?? null) === null) {
            return null;
        }

        GameJob::create([
            'character_id' => $character->id,
            'kind' => 'mining',
            'status' => 'active',
            'col' => $character->col,
            'row' => $character->row,
            'slot' => 0,
            'material_key' => $preview['material'],
            'quantity' => max(1, (int) $preview['yield']),
            'skill_key' => Catalog::skillForMaterial($preview['material']),
            'started_at' => $now - 60_000,
            'ends_at' => $now - 1_000,
        ]);

        return 'a finished '.$preview['material'].' dig, ready to collect';
    }

    /** @param  array<string,int>  $materials */
    private function seedProcessingBatch(GameService $game, Character $character, int $now, array &$materials): ?string
    {
        $recipe = Catalog::recipe('planks');
        if ($recipe === null) {
            return null;
        }

        $cost = $recipe['inputQty'] * self::PROCESSING_BATCHES;
        if (($materials[$recipe['input']] ?? 0) < $cost) {
            return null;
        }

        $settlement = $this->nearestSettlementRunning($character, $recipe['skill']);
        if ($settlement === null) {
            return null;
        }

        $materials[$recipe['input']] -= $cost;

        GameJob::create([
            'character_id' => $character->id,
            'kind' => 'processing',
            'status' => 'active',
            'settlement_id' => $settlement['id'],
            'recipe_key' => 'planks',
            'material_key' => $recipe['input'],
            'output_key' => $recipe['output'],
            'presence' => false,
            'quantity' => $recipe['outputQty'] * self::PROCESSING_BATCHES,
            'skill_key' => $recipe['skill'],
            'started_at' => $now,
            'ends_at' => $now + 120_000,
        ]);

        return "planks running at {$settlement['name']}, 2 minutes out";
    }

    /** @return array<string,mixed>|null */
    private function nearestSettlementRunning(Character $character, string $line): ?array
    {
        $range = Balance::SPAWN_VILLAGE_RADIUS;

        for ($dc = -$range; $dc <= $range; $dc++) {
            for ($dr = -$range; $dr <= $range; $dr++) {
                $settlement = WorldGen::settlementAt($character->col + $dc, $character->row + $dr);

                if ($settlement === null) {
                    continue;
                }
                if (HexGeometry::distance($character->col, $character->row, $settlement['col'], $settlement['row']) > $range) {
                    continue;
                }
                if (! in_array($line, $settlement['lines'], true)) {
                    continue;
                }

                return $settlement;
            }
        }

        return null;
    }
}
