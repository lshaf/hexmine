<?php

declare(strict_types=1);

namespace App\Game;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\CharacterSkill;
use App\Models\GameJob;
use App\Models\Player;
use App\Models\TileState;
use Illuminate\Support\Facades\DB;

/**
 * All authoritative game logic, §16.
 *
 * The client sends intent ("mine this tile") and is told what happened. It never
 * sends a duration, an elapsed time, a yield or a drop. Every timer is a
 * millisecond deadline computed here and compared against this clock.
 *
 * Where the earlier in-browser simulation had to fake other players -- tile slot
 * contention and processing-queue congestion were derived from a time bucket --
 * this counts real rows, so contention between real players is genuine.
 */
class GameService
{
    /** The server clock. Nothing else in the app may ask what time it is. */
    public function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    // ------------------------------------------------------------ provisioning

    /**
     * §5.4 -- spawn is auto-assigned, never player-chosen, and biased toward
     * under-populated regions.
     *
     * It also has to guarantee the §12 tutorial is completable from where it
     * drops you: a forest tile in the outer ring, with a village running the
     * woodcutting line inside level-1 travel range. Villages run only 1 of 5
     * lines (§6), so unconstrained spawns leave most players with no reachable
     * way to turn wood into planks.
     *
     * @return array{col:int,row:int}
     */
    public function pickSpawn(int $seed): array
    {
        $angle = Hash::rand01(Hash::hash2($seed, 1, Balance::MAP_SEED)) * M_PI * 2;
        $radius = 0.78 + Hash::rand01(Hash::hash2($seed, 2, Balance::MAP_SEED)) * 0.14;
        $startCol = (int) round(Balance::MAP_COLS / 2 + cos($angle) * $radius * (Balance::MAP_COLS / 2));
        $startRow = (int) round(Balance::MAP_ROWS / 2 + sin($angle) * $radius * (Balance::MAP_ROWS / 2));

        $fallback = ['col' => $startCol, 'row' => $startRow];
        $range = Balance::travelRange(1);

        for ($ring = 0; $ring < 70; $ring++) {
            for ($dc = -$ring; $dc <= $ring; $dc++) {
                for ($dr = -$ring; $dr <= $ring; $dr++) {
                    if (max(abs($dc), abs($dr)) !== $ring) {
                        continue;
                    }
                    $col = min(Balance::MAP_COLS - 1, max(0, $startCol + $dc));
                    $row = min(Balance::MAP_ROWS - 1, max(0, $startRow + $dr));

                    if (WorldGen::biomeOf($col, $row) !== 'forest') {
                        continue;
                    }
                    if (WorldGen::ringOf($col, $row) !== 'outer') {
                        continue;
                    }
                    if (WorldGen::settlementAt($col, $row) !== null) {
                        continue;
                    }

                    $fallback = ['col' => $col, 'row' => $row];
                    if ($this->findNearbySettlement($col, $row, $range, 'woodcutting') !== null) {
                        return ['col' => $col, 'row' => $row];
                    }
                }
            }
        }

        return $fallback;
    }

    private function findNearbySettlement(int $col, int $row, int $range, ?string $requiredLine = null): ?array
    {
        for ($dc = -$range; $dc <= $range; $dc++) {
            for ($dr = -$range; $dr <= $range; $dr++) {
                $s = WorldGen::settlementAt($col + $dc, $row + $dr);
                if ($s === null || HexGeometry::distance($col, $row, $s['col'], $s['row']) > $range) {
                    continue;
                }
                if ($requiredLine !== null && ! in_array($requiredLine, $s['lines'], true)) {
                    continue;
                }

                return $s;
            }
        }

        return null;
    }

    /** Mint the one soulbound character this wallet is allowed, §7. */
    public function createCharacter(Player $player): Character
    {
        $now = $this->now();
        $spawn = $this->pickSpawn(crc32($player->wallet));

        return DB::transaction(function () use ($player, $now, $spawn) {
            $character = Character::create([
                'player_id' => $player->id,
                'name' => 'Prospector',
                'level' => 1,
                'xp' => 0,
                'ap' => Balance::STARTING_AP,
                'ap_updated_at' => $now,
                'gold' => Balance::STARTING_GOLD,
                'col' => $spawn['col'],
                'row' => $spawn['row'],
                'tutorial_step' => 0,
                'last_decay_at' => $now,
            ]);

            foreach (Catalog::SKILLS as $skill) {
                CharacterSkill::create([
                    'character_id' => $character->id,
                    'skill_key' => $skill,
                    'level' => 1,
                    'xp' => 0,
                ]);
            }

            return $character;
        });
    }

    // ---------------------------------------------------------------- settling

    /**
     * Lazy settlement of everything time-based. Runs before every read and write.
     *
     * Nothing in this game ticks: AP regen and storage decay are derived from
     * timestamps, so an hour offline and an hour idle produce the same result.
     */
    public function settle(Character $character): void
    {
        $now = $this->now();

        $dirtyTravel = $this->arriveIfDue($character, $now);

        $regen = Formulas::regenerateAp(
            $character->ap,
            $character->ap_updated_at,
            $character->level,
            $now,
            Balance::scaled(Balance::AP_REGEN_MS),
        );

        $dirty = $dirtyTravel;
        if ($regen['ap'] !== $character->ap || $regen['apUpdatedAt'] !== $character->ap_updated_at) {
            $character->ap = $regen['ap'];
            $character->ap_updated_at = $regen['apUpdatedAt'];
            $dirty = true;
        }

        // §11.1 -- storage overflow decay.
        $interval = Balance::scaled(Balance::DECAY_INTERVAL_MS);
        $elapsed = $now - $character->last_decay_at;
        if ($elapsed >= $interval) {
            $intervals = intdiv($elapsed, $interval);
            $this->applyDecay($character, $intervals);
            $character->last_decay_at += $intervals * $interval;
            $dirty = true;
        }

        if ($dirty) {
            $character->save();
        }
    }

    /**
     * §11.1 -- only tier 1 raw materials rot. Refined and rare goods represent
     * invested work; punishing those twice reads as a bug, not a sink.
     */
    private function applyDecay(Character $character, int $intervals): void
    {
        $cap = Balance::storageCap($character->level);
        $rows = $character->materials()->get();
        $total = $rows->sum('quantity');

        if ($total <= $cap) {
            return;
        }

        $raw = $rows->filter(fn ($r) => Catalog::materialTier($r->material_key) === 1 && $r->quantity > 0)
            ->sortByDesc('quantity')
            ->values();

        if ($raw->isEmpty()) {
            return;
        }

        for ($i = 0; $i < $intervals && $total > $cap; $i++) {
            $overflow = $total - $cap;
            $loss = max(1, (int) ceil($overflow * Balance::DECAY_RATE));
            $remaining = $loss;

            foreach ($raw as $row) {
                if ($remaining <= 0) {
                    break;
                }
                if ($row->quantity <= 0) {
                    continue;
                }
                $take = min($row->quantity, max(1, (int) ceil($remaining / max(1, $raw->count()))));
                $row->quantity -= $take;
                $remaining -= $take;
                $total -= $take;
            }
        }

        foreach ($raw as $row) {
            $row->quantity > 0 ? $row->save() : $row->delete();
        }
    }

    // -------------------------------------------------------------- inventory

    /** @return array<string,int> */
    public function inventory(Character $character): array
    {
        return $character->materials()
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'material_key')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    public function held(Character $character, string $key): int
    {
        return (int) ($character->materials()->where('material_key', $key)->value('quantity') ?? 0);
    }

    /** Grant materials, honouring the §2 per-wallet cap. Returns units granted. */
    private function addMaterial(Character $character, string $key, int $quantity): int
    {
        $row = CharacterMaterial::firstOrNew([
            'character_id' => $character->id,
            'material_key' => $key,
        ]);
        $current = (int) ($row->quantity ?? 0);

        $granted = $quantity;
        $cap = Catalog::walletCap($key);
        if ($cap !== null) {
            // §2 -- a bot farm with 1000 wallets gets 1000x capped, non-liquid output.
            $granted = max(0, min($quantity, $cap - $current));
        }

        if ($granted > 0) {
            $row->quantity = $current + $granted;
            $row->save();
        }

        return $granted;
    }

    private function takeMaterial(Character $character, string $key, int $quantity): void
    {
        $row = $character->materials()->where('material_key', $key)->first();
        $have = (int) ($row->quantity ?? 0);

        if ($have < $quantity) {
            $name = Catalog::material($key)['name'] ?? $key;
            throw new GameException("Not enough {$name}.", 'insufficient');
        }

        $row->quantity = $have - $quantity;
        $row->quantity > 0 ? $row->save() : $row->delete();
    }

    public function storageUsed(Character $character): int
    {
        return (int) $character->materials()->sum('quantity');
    }

    // -------------------------------------------------------------- equipment

    /** @return array<int,array{key:string,durability:int,equipped:bool}> */
    private function itemRows(Character $character): array
    {
        return $character->items->map(fn (CharacterItem $i) => [
            'key' => $i->item_key,
            'durability' => $i->durability,
            'equipped' => $i->equipped,
        ])->all();
    }

    /** @return array<string,float> */
    public function bonuses(Character $character): array
    {
        $items = $this->itemRows($character);

        return [
            'yield' => Formulas::aggregateStat($items, 'yield'),
            'tripReduction' => Formulas::aggregateStat($items, 'tripReduction'),
            'travelSpeed' => Formulas::aggregateStat($items, 'travelSpeed'),
            'processingSpeed' => Formulas::aggregateStat($items, 'processingSpeed'),
            'power' => Formulas::aggregateStat($items, 'power'),
        ];
    }

    public function travelRange(Character $character): int
    {
        return Balance::travelRange($character->level, $this->bonuses($character)['travelSpeed']);
    }

    private function drainDurability(Character $character, int $amount): int
    {
        $drained = 0;
        foreach ($character->items as $item) {
            if (! $item->equipped || $item->durability <= 0) {
                continue;
            }
            // §8.2 -- at 0 an item is broken and inactive, never destroyed.
            $item->durability = max(0, $item->durability - $amount);
            $item->save();
            $drained += $amount;
        }

        return $drained;
    }

    // ------------------------------------------------------------------ skills

    private function grantSkillXp(Character $character, string $skillKey, int $amount): void
    {
        // §7.2 -- total points are capped so characters stay specialised.
        $spent = (int) $character->skills()->sum('level');
        if ($spent >= Balance::SKILL_TOTAL_POINT_CAP) {
            return;
        }

        $skill = $character->skills()->where('skill_key', $skillKey)->first();
        if ($skill === null) {
            return;
        }

        $result = Formulas::applyXp(
            $skill->level,
            $skill->xp,
            $amount,
            Balance::SKILL_MAX_LEVEL,
            fn (int $l) => Balance::skillXpForLevel($l),
        );

        $skill->level = $result['level'];
        $skill->xp = $result['xp'];
        $skill->save();
    }

    private function grantCharacterXp(Character $character, int $amount): int
    {
        $result = Formulas::applyXp(
            $character->level,
            $character->xp,
            $amount,
            Balance::MAX_LEVEL,
            fn (int $l) => Balance::xpForLevel($l),
        );

        $character->level = $result['level'];
        $character->xp = $result['xp'];

        if ($result['levelsGained'] > 0) {
            // A new level raises the AP ceiling; top up so it feels immediate.
            $character->ap = min(
                Balance::apMax($character->level),
                $character->ap + $result['levelsGained'] * Balance::AP_PER_LEVEL,
            );
        }

        return $result['levelsGained'];
    }

    // ---------------------------------------------------------------- tutorial

    private function fireTutorial(Character $character, string $event): void
    {
        $character->tutorial_step = Tutorial::advance($character->tutorial_step, $event);
    }

    // ------------------------------------------------------------------- tiles

    /**
     * §5.1 -- exactly two mining slots per hex, shared by everyone. A COUNT over
     * live jobs, so contention is real rather than per-player bookkeeping.
     */
    private function occupiedSlots(int $col, int $row): int
    {
        return GameJob::where('col', $col)
            ->where('row', $row)
            ->where('status', 'active')
            ->count();
    }

    /**
     * One trip at a time.
     *
     * A character works a single hex, standing on it, and cannot leave until the
     * haul is claimed or dropped. That single rule is what makes the dock read
     * as "what I can do here" rather than a queue manager: there is only ever
     * one mining thing to do, and it is on this tile.
     *
     * Rows are deleted on collect, so the job existing at all means unfinished
     * business -- either still running or waiting to be claimed.
     */
    public function miningTrip(Character $character): ?GameJob
    {
        return $character->jobs()->where('kind', 'mining')->first();
    }

    /**
     * The NPC does the processing; the player only helps (§6.2). Helping is a
     * matter of standing there, and a person can only stand in one place, so a
     * character may be attached to one job at a time.
     */
    public function processingJob(Character $character): ?GameJob
    {
        return $character->jobs()->where('kind', 'processing')->first();
    }

    public function buildTile(int $col, int $row, int $now): array
    {
        $regrowsAt = (int) (TileState::where('col', $col)->where('row', $row)->value('regrows_at') ?? 0);
        $tile = WorldGen::generateTile($col, $row, $now, ['regrowsAt' => $regrowsAt]);

        // Only mineable tiles have slots to occupy: barren capital-ring hexes,
        // settlements and dungeon entrances would otherwise show phantom pips.
        if ($tile['material'] !== null) {
            $tile['slotsUsed'] = min(Balance::SLOTS_PER_TILE, $this->occupiedSlots($col, $row));
        }

        return $tile;
    }

    /**
     * Everything the client needs to generate the world itself.
     *
     * The map is 25 million tiles derived from (col, row, seed). Shipping
     * generated tiles for every viewport cost ~200KB a pan; shipping the
     * *parameters* is a few hundred bytes, once, and lets the client redraw
     * while panning with no network at all.
     *
     * Note this sends the generation constants rather than expecting the client
     * to hardcode them: the algorithm is mirrored, the numbers are not, so a
     * balance change here cannot silently desync the two.
     */
    public function worldConfig(): array
    {
        return [
            'seed' => Balance::MAP_SEED,
            'cols' => Balance::MAP_COLS,
            'rows' => Balance::MAP_ROWS,
            'biomeCell' => Balance::BIOME_CELL,
            'biomeRegionCells' => Balance::BIOME_REGION_CELLS,
            'rings' => [
                'center' => Balance::RING_CENTER,
                'inner' => Balance::RING_INNER,
                'mid' => Balance::RING_MID,
            ],
            'baseMinSeconds' => Balance::MINING_BASE_MIN_SECONDS,
            'baseMaxSeconds' => Balance::MINING_BASE_MAX_SECONDS,
            'rareSpawnChance' => Balance::RARE_SPAWN_CHANCE,
            'slotsPerTile' => Balance::SLOTS_PER_TILE,
            'herdLifetimeMs' => Balance::scaled(Balance::HERD_LIFETIME_MS),
            'herdChance' => Balance::HERD_CHANCE,
            'biomes' => Catalog::BIOMES,
            'biomeMaterial' => Catalog::BIOME_MATERIAL,
            'biomeRare' => Catalog::BIOME_RARE,
            'skills' => Catalog::SKILLS,
            'namePrefixes' => Catalog::NAME_PREFIXES,
            'nameSuffixes' => Catalog::NAME_SUFFIXES,
            'dungeonSites' => array_map(
                fn (array $site) => [
                    'col' => $site['col'],
                    'row' => $site['row'],
                    'key' => $site['dungeon']['key'],
                    'name' => $site['dungeon']['name'],
                ],
                WorldGen::dungeonSites(),
            ),
        ];
    }

    /**
     * The only things about a viewport that cannot be derived: which tiles are
     * worked out, and which have miners on them. Sent as compact tuples because
     * this is the one request that fires on every pan.
     *
     * @return array{depleted:list<array{int,int,int}>,occupied:list<array{int,int,int}>}
     */
    /**
     * Everything about the world a player is allowed to be told, beyond what the
     * seed already gives them: which tiles are worked out, and who is standing
     * on them.
     *
     * Scoped to sight, and sight is the character's travel range -- you see
     * exactly as far as you can act. That is why this takes no coordinates. The
     * camera can be dragged anywhere and costs nothing, because terrain is
     * derived (§5); but live state follows the character, not the camera, so a
     * client cannot walk a viewport parameter across the map to harvest where
     * everyone is mining. Nothing outside sight is knowable, not merely undrawn.
     *
     * @return array{depleted:array<int,array{0:int,1:int,2:int}>,occupied:array<int,array{0:int,1:int,2:int}>}
     */
    public function mapMutations(Character $character): array
    {
        $now = $this->now();
        $range = $this->travelRange($character);
        $centerCol = (int) $character->col;
        $centerRow = (int) $character->row;

        [$minCol, $maxCol] = [$centerCol - $range, $centerCol + $range];
        [$minRow, $maxRow] = [$centerRow - $range, $centerRow + $range];

        // The box above is the cheap index scan; sight is a hex disc inside it.
        $inSight = fn (int $col, int $row): bool =>
            HexGeometry::distance($centerCol, $centerRow, $col, $row) <= $range;

        $depleted = TileState::whereBetween('col', [$minCol, $maxCol])
            ->whereBetween('row', [$minRow, $maxRow])
            ->where('regrows_at', '>', $now)
            ->get()
            ->filter(fn ($tile) => $inSight((int) $tile->col, (int) $tile->row))
            ->map(fn ($tile) => [(int) $tile->col, (int) $tile->row, (int) $tile->regrows_at])
            ->values()
            ->all();

        $occupied = GameJob::where('status', 'active')
            ->whereNotNull('col')
            ->whereBetween('col', [$minCol, $maxCol])
            ->whereBetween('row', [$minRow, $maxRow])
            ->selectRaw('col, row, COUNT(*) as total')
            ->groupBy('col', 'row')
            ->get()
            ->filter(fn ($job) => $inSight((int) $job->col, (int) $job->row))
            ->map(fn ($job) => [
                (int) $job->col,
                (int) $job->row,
                min(Balance::SLOTS_PER_TILE, (int) $job->total),
            ])
            ->values()
            ->all();

        return ['depleted' => $depleted, 'occupied' => $occupied];
    }

    /** Server-computed preview of what a trip here would cost and give. */
    public function previewTile(Character $character, int $col, int $row): array
    {
        $now = $this->now();
        $tile = $this->buildTile($col, $row, $now);

        $base = [
            'canMine' => false,
            'reason' => null,
            'seconds' => 0,
            'baseSeconds' => $tile['baseSeconds'],
            'skillReduction' => 0,
            'equipReduction' => 0,
            'clamped' => false,
            'yield' => 0,
            'material' => $tile['material'],
            'apCost' => Balance::MINING_AP_COST,
        ];

        if ($tile['material'] === null) {
            $base['reason'] = match (true) {
                $tile['ring'] === 'center' => 'The capital ring is barren. Nothing grows or seams here.',
                $tile['settlement'] !== null => 'Settlements sit on worked ground. Nothing left to take.',
                default => 'Nothing mineable here.',
            };

            return $base;
        }

        if ($tile['regrowsAt'] > $now) {
            $base['reason'] = 'Depleted. This tile is regrowing.';

            return $base;
        }

        if ($tile['slotsUsed'] >= Balance::SLOTS_PER_TILE) {
            $base['reason'] = 'Both slots are taken. Find another hex.';

            return $base;
        }

        $skillKey = Catalog::skillForMaterial($tile['material']);
        $skillLevel = (int) ($character->skills()->where('skill_key', $skillKey)->value('level') ?? 1);
        $bonuses = $this->bonuses($character);

        $trip = Formulas::tripTime($tile['baseSeconds'], $skillLevel, $bonuses['tripReduction']);

        // You work the hex you are standing on -- there is no reaching across the
        // map for a seam. Everything above is a fact about the tile and holds
        // wherever it is read from, so a hex you are only scouting still reports
        // its haul and trip time; what changes is whether you may act on it.
        $reason = null;
        $working = $this->miningTrip($character);

        if ($this->isTravelling($character)) {
            $reason = 'You are on the road. Stop the journey, or wait until you arrive.';
        } elseif ($working !== null) {
            $reason = $working->isReady($now)
                ? 'Your haul is waiting. Claim it before working anything else.'
                : 'You are already working a hex. One trip at a time.';
        } elseif ((int) $character->col !== $col || (int) $character->row !== $row) {
            $distance = HexGeometry::distance($character->col, $character->row, $col, $row);
            $reason = $distance > $this->travelRange($character)
                ? 'Out of travel range. Move in shorter hops, or level up to reach further.'
                : 'You are standing elsewhere. Travel to this hex to work it.';
        } elseif ($character->ap < Balance::MINING_AP_COST) {
            $reason = 'Not enough action points.';
        }

        return [
            'canMine' => $reason === null,
            'reason' => $reason,
            'seconds' => $trip['total'],
            'baseSeconds' => $trip['base'],
            'skillReduction' => $trip['skillReduction'],
            'equipReduction' => $trip['equipReduction'],
            'clamped' => $trip['clamped'],
            'yield' => Formulas::tripYield(
                $tile['baseYield'],
                $skillLevel,
                $bonuses['yield'],
                WorldGen::ringYield($tile['ring']),
            ),
            'material' => $tile['material'],
            'apCost' => Balance::MINING_AP_COST,
        ];
    }

    // ------------------------------------------------------------------ mining

    public function startMining(Character $character, int $col, int $row): GameJob
    {
        return DB::transaction(function () use ($character, $col, $row) {
            $preview = $this->previewTile($character, $col, $row);
            if (! $preview['canMine']) {
                throw new GameException($preview['reason'] ?? 'Cannot mine here.', 'blocked');
            }

            if ($character->ap < $preview['apCost']) {
                throw new GameException('Not enough action points.', 'no_ap');
            }
            $character->ap -= $preview['apCost'];

            $now = $this->now();
            $slot = $this->occupiedSlots($col, $row);

            // Re-check inside the transaction: two players can race for the last
            // slot, and the preview above is only a read.
            if ($slot >= Balance::SLOTS_PER_TILE) {
                throw new GameException('Both slots are taken. Find another hex.', 'blocked');
            }

            $job = GameJob::create([
                'character_id' => $character->id,
                'kind' => 'mining',
                'status' => 'active',
                'col' => $col,
                'row' => $row,
                'slot' => $slot % Balance::SLOTS_PER_TILE,
                'material_key' => $preview['material'],
                'quantity' => $preview['yield'],
                'skill_key' => Catalog::skillForMaterial($preview['material']),
                'started_at' => $now,
                // The duration is decided here. The client is told endsAt, nothing more.
                'ends_at' => $now + Balance::scaled($preview['seconds'] * 1000),
            ]);

            // Nobody moves here: the character was already standing on this hex
            // before the trip could start, and stays on it while the timer runs.
            // Position changes only through explicit travel.
            $this->fireTutorial($character, 'mine_start');
            $character->save();

            return $job;
        });
    }

    /** @return array<string,mixed> */
    public function collectJob(Character $character, int $jobId): array
    {
        return DB::transaction(function () use ($character, $jobId) {
            $job = $character->jobs()->where('id', $jobId)->first();
            if ($job === null) {
                throw new GameException('That job no longer exists.', 'not_found');
            }

            $now = $this->now();
            if (! $job->isReady($now)) {
                throw new GameException('Still working.', 'not_ready');
            }

            $gained = [];
            $durabilityLost = 0;

            if ($job->kind === 'mining') {
                $granted = $this->addMaterial($character, $job->material_key, $job->quantity);
                $lostToOverflow = $job->quantity - $granted;
                $gained[$job->material_key] = $granted;
                $xpAmount = $job->quantity * 4;
                $durabilityLost = $this->drainDurability($character, Balance::DRAIN_PER_MINE);

                // §5.1 -- worked-out tiles regrow rather than dying.
                $exhausted = Hash::rand01(
                    Hash::hash2($job->col + $now, $job->row, Balance::MAP_SEED ^ 0xdeed)
                ) < Balance::DEPLETE_CHANCE;

                if ($exhausted) {
                    TileState::updateOrCreate(
                        ['col' => $job->col, 'row' => $job->row],
                        ['regrows_at' => $now + Balance::scaled(Balance::REGROW_MS)],
                    );
                }

                $this->fireTutorial($character, 'collect');
            } else {
                $granted = $this->addMaterial($character, $job->output_key, $job->quantity);
                $lostToOverflow = $job->quantity - $granted;
                $gained[$job->output_key] = $granted;
                $xpAmount = $job->quantity * 9;

                $this->fireTutorial($character, 'process_collect');
            }

            $this->grantSkillXp($character, $job->skill_key, $xpAmount);
            $characterXp = (int) round($xpAmount * 0.6);
            $levelsGained = $this->grantCharacterXp($character, $characterXp);

            $job->delete();
            $character->save();

            return [
                'gained' => $gained,
                'lostToOverflow' => $lostToOverflow,
                'xp' => ['skill' => $job->skill_key, 'amount' => $xpAmount],
                'characterXp' => $characterXp,
                'levelsGained' => $levelsGained,
                'durabilityLost' => $durabilityLost,
            ];
        });
    }

    public function abandonJob(Character $character, int $jobId): void
    {
        $job = $character->jobs()->where('id', $jobId)->first();
        if ($job === null) {
            throw new GameException('That job no longer exists.', 'not_found');
        }

        // §11.1 -- abandoning mid-progress forfeits the partial yield. This is a
        // sink, and it is meant to sting.
        $job->delete();
    }

    // -------------------------------------------------------------- settlement

    public function settlement(string $settlementId): array
    {
        $settlement = WorldGen::settlementById($settlementId);
        if ($settlement === null) {
            throw new GameException('Unknown settlement.', 'not_found');
        }

        return $settlement;
    }

    /**
     * §6.1 -- five open slots per feature, first-come-first-served, shared by
     * every player. Real congestion, counted from real jobs.
     */
    public function station(Character $character, string $settlementId): array
    {
        $settlement = $this->settlement($settlementId);

        $jobs = GameJob::where('settlement_id', $settlementId)
            ->where('status', 'active')
            ->orderBy('started_at')
            ->get();

        $slots = [];
        foreach ($jobs->take(Balance::PUBLIC_SLOTS) as $index => $job) {
            $mine = $job->character_id === $character->id;
            $slots[] = [
                'index' => $index,
                'job' => $mine ? $this->jobPayload($job) : null,
                'owner' => $mine ? 'you' : 'another player',
            ];
        }
        for ($i = count($slots); $i < Balance::PUBLIC_SLOTS; $i++) {
            $slots[] = ['index' => $i, 'job' => null, 'owner' => null];
        }

        return [
            'settlement' => $settlement,
            'slots' => $slots,
            'presence' => $character->presence_settlement_id === $settlementId,
        ];
    }

    public function startProcessing(Character $character, string $settlementId, string $recipeKey, int $batches): GameJob
    {
        return DB::transaction(function () use ($character, $settlementId, $recipeKey, $batches) {
            $settlement = $this->settlement($settlementId);
            $recipe = Catalog::recipe($recipeKey);

            if ($recipe === null) {
                throw new GameException('Unknown recipe.', 'not_found');
            }
            if (! in_array($recipe['skill'], $settlement['lines'], true)) {
                throw new GameException("{$settlement['name']} does not run that line.", 'no_line');
            }
            if ($this->isTravelling($character) || $character->col !== $settlement['col'] || $character->row !== $settlement['row']) {
                throw new GameException('You have to be at the settlement.', 'not_present');
            }

            if ($this->processingJob($character) !== null) {
                throw new GameException(
                    'You are already helping with a job. Collect that one first.',
                    'busy',
                );
            }

            $busy = GameJob::where('settlement_id', $settlementId)->where('status', 'active')->count();
            if ($busy >= Balance::PUBLIC_SLOTS) {
                throw new GameException('Every slot here is busy. Wait, or try another settlement.', 'queue_full');
            }

            $count = max(1, $batches);
            $this->takeMaterial($character, $recipe['input'], $recipe['inputQty'] * $count);
            if (isset($recipe['secondInput'])) {
                $this->takeMaterial($character, $recipe['secondInput'], ($recipe['secondInputQty'] ?? 1) * $count);
            }

            $now = $this->now();
            $presence = $character->presence_settlement_id === $settlementId;
            $seconds = Formulas::processingTime(
                $recipe['baseSeconds'] * $count,
                $settlement['tier'],
                $presence,
                $this->bonuses($character)['processingSpeed'],
            );

            $job = GameJob::create([
                'character_id' => $character->id,
                'kind' => 'processing',
                'status' => 'active',
                'settlement_id' => $settlementId,
                'recipe_key' => $recipeKey,
                'material_key' => $recipe['input'],
                'output_key' => $recipe['output'],
                'presence' => $presence,
                'quantity' => $recipe['outputQty'] * $count,
                'skill_key' => $recipe['skill'],
                'started_at' => $now,
                'ends_at' => $now + Balance::scaled($seconds * 1000),
            ]);

            $this->fireTutorial($character, 'process_start');
            $character->save();

            return $job;
        });
    }

    /**
     * §6.2 -- the helper bonus is paid for by staying.
     *
     * Arriving shortens what is left of the work; leaving lengthens it again by
     * exactly the same factor, so the discount only ever covers the time the
     * player was actually standing there. Without the second half, "stay at the
     * village" would mean walk in, walk out, keep the bonus -- and the one thing
     * the player contributes to processing would cost them nothing.
     *
     * There is no toggle. Presence is where you are, which is the whole of §6.2:
     * no click-checks, no QTEs, nothing to farm.
     */
    private function joinPresence(Character $character, string $settlementId): void
    {
        $character->presence_settlement_id = $settlementId;

        $now = $this->now();
        $jobs = $character->jobs()
            ->where('settlement_id', $settlementId)
            ->where('status', 'active')
            ->where('presence', false)
            ->get();

        foreach ($jobs as $job) {
            $job->presence = true;
            $remaining = $job->ends_at - $now;
            if ($remaining > 0) {
                $job->ends_at = $now + (int) round($remaining * (1 - Balance::PRESENCE_SPEED_BONUS));
            }
            $job->save();
        }
    }

    private function leavePresence(Character $character): void
    {
        $character->presence_settlement_id = null;

        $now = $this->now();
        $jobs = $character->jobs()
            ->where('status', 'active')
            ->where('presence', true)
            ->get();

        foreach ($jobs as $job) {
            $job->presence = false;
            $remaining = $job->ends_at - $now;
            if ($remaining > 0) {
                $job->ends_at = $now + (int) round($remaining / (1 - Balance::PRESENCE_SPEED_BONUS));
            }
            $job->save();
        }
    }

    public function travelTo(Character $character, int $col, int $row): array
    {
        // A trip pins you to the hex you are working. Dropping it is the way out,
        // and it forfeits the haul (§11.1) -- say so, or the lock reads as a bug.
        $trip = $this->miningTrip($character);
        if ($trip !== null) {
            throw new GameException(
                $trip->isReady($this->now())
                    ? 'Claim your haul before you move on.'
                    : 'You are working this hex. Claim the trip when it finishes, or drop it.',
                'working',
            );
        }

        if ($this->isTravelling($character)) {
            throw new GameException(
                'You are already on the road. Stop where you are before setting a new course.',
                'travelling',
            );
        }

        $distance = HexGeometry::distance($character->col, $character->row, $col, $row);
        if ($distance === 0) {
            throw new GameException('You are already standing here.', 'blocked');
        }
        if ($distance > $this->travelRange($character)) {
            throw new GameException(
                'Out of travel range. Move in shorter hops, or level up to reach further.',
                'out_of_range',
            );
        }

        // Whatever you were helping with, you are not helping with it any more.
        $this->leavePresence($character);

        $now = $this->now();
        $character->travel_to_col = $col;
        $character->travel_to_row = $row;
        $character->travel_started_at = $now;
        $character->travel_ends_at = $now + $distance * Balance::scaled(Balance::TRAVEL_MS_PER_HEX);
        $character->save();

        return $this->travelState($character);
    }

    /**
     * Stop where you stand -- which is the last hex you actually set foot on.
     *
     * Part of a hex is not a place. Time spent inside the current leg buys
     * nothing, so the walk back down to whole hexes is a floor, and a journey
     * abandoned before the first hex lands leaves you exactly where you began.
     *
     * @return array{col:int,row:int,hexes:int,settlement:array<string,mixed>|null}
     */
    public function cancelTravel(Character $character): array
    {
        if (! $this->isTravelling($character)) {
            throw new GameException('You are not going anywhere.', 'not_travelling');
        }

        $perHex = Balance::scaled(Balance::TRAVEL_MS_PER_HEX);
        $path = $this->travelPath($character);
        $elapsed = max(0, $this->now() - (int) $character->travel_started_at);

        $steps = min(count($path) - 1, intdiv($elapsed, $perHex));
        $stop = $path[$steps];

        $character->col = $stop['col'];
        $character->row = $stop['row'];
        $this->clearTravel($character);

        $settlement = WorldGen::settlementAt($character->col, $character->row);
        if ($settlement !== null) {
            $this->joinPresence($character, $settlement['id']);
            $this->fireTutorial($character, 'travel');
        }
        $character->save();

        return [
            'col' => $character->col,
            'row' => $character->row,
            'hexes' => $steps,
            'settlement' => $settlement,
        ];
    }

    /** True while the character is somewhere between two hexes. */
    public function isTravelling(Character $character): bool
    {
        return $character->travel_ends_at !== null;
    }

    /**
     * The road, derived rather than stored. The client draws the same hexes
     * from the same endpoints, so the marker it animates and the hex a stop
     * would land on are the same list.
     *
     * @return array<int,array{col:int,row:int}>
     */
    private function travelPath(Character $character): array
    {
        return HexGeometry::line(
            (int) $character->col,
            (int) $character->row,
            (int) $character->travel_to_col,
            (int) $character->travel_to_row,
        );
    }

    /** @return array<string,mixed>|null */
    public function travelState(Character $character): ?array
    {
        if (! $this->isTravelling($character)) {
            return null;
        }

        $path = $this->travelPath($character);
        $settlement = WorldGen::settlementAt((int) $character->travel_to_col, (int) $character->travel_to_row);

        return [
            'toCol' => (int) $character->travel_to_col,
            'toRow' => (int) $character->travel_to_row,
            'startedAt' => (int) $character->travel_started_at,
            'endsAt' => (int) $character->travel_ends_at,
            'perHexMs' => Balance::scaled(Balance::TRAVEL_MS_PER_HEX),
            'hexes' => count($path) - 1,
            'path' => array_map(fn (array $hex) => [$hex['col'], $hex['row']], $path),
            'destinationName' => $settlement['name'] ?? null,
        ];
    }

    /** Land a journey whose clock has run out. Called from settle, never directly. */
    private function arriveIfDue(Character $character, int $now): bool
    {
        if (! $this->isTravelling($character) || $now < (int) $character->travel_ends_at) {
            return false;
        }

        $character->col = (int) $character->travel_to_col;
        $character->row = (int) $character->travel_to_row;
        $this->clearTravel($character);

        $settlement = WorldGen::settlementAt($character->col, $character->row);
        if ($settlement !== null) {
            $this->joinPresence($character, $settlement['id']);
            $this->fireTutorial($character, 'travel');
        }

        return true;
    }

    private function clearTravel(Character $character): void
    {
        $character->travel_to_col = null;
        $character->travel_to_row = null;
        $character->travel_started_at = null;
        $character->travel_ends_at = null;
    }

    // ------------------------------------------------------------- location

    /** The settlement the character is standing on, if any. */
    public function currentSettlement(Character $character): ?array
    {
        // Mid-journey you are between hexes, so you are at nothing: the trader
        // you left is behind you and the one ahead is not in earshot yet.
        if ($this->isTravelling($character)) {
            return null;
        }

        return WorldGen::settlementAt($character->col, $character->row);
    }

    /**
     * Gate an action on where the character is standing.
     *
     * Trading, crafting and processing are things you do *at a settlement* --
     * §6 makes settlements the shared infrastructure of the world, and there is
     * no trader standing in the middle of a forest waiting to buy your wood.
     * Mining is the same rule pointed outward: it happens on the hex under your
     * feet, so you walk to the seam before you work it.
     *
     * Returns the settlement so callers can name it in messages.
     */
    private function requireSettlement(Character $character, string $action, ?string $minTier = null): array
    {
        $settlement = $this->currentSettlement($character);

        if ($settlement === null) {
            throw new GameException(
                "There is nobody out here to {$action} with. Travel to a settlement first.",
                'not_at_settlement',
            );
        }

        if ($minTier !== null && Catalog::STATION_RANK[$settlement['tier']] < Catalog::STATION_RANK[$minTier]) {
            throw new GameException(
                "{$settlement['name']} is only a {$settlement['tier']}. That needs a {$minTier}.",
                'wrong_station',
            );
        }

        return $settlement;
    }

    /** What this settlement stocks, §3.2. Bigger settlements carry more. */
    public function shopStock(Character $character): array
    {
        $settlement = $this->currentSettlement($character);
        if ($settlement === null) {
            return [];
        }

        $rank = Catalog::STATION_RANK[$settlement['tier']];
        $stock = [];
        foreach (Catalog::items() as $key => $def) {
            if (! isset($def['goldPrice'])) {
                continue;
            }
            if (Catalog::STATION_RANK[$def['station'] ?? 'village'] > $rank) {
                continue;
            }
            $stock[] = $key;
        }

        return $stock;
    }

    // -------------------------------------------------------------------- shop

    public function buyItem(Character $character, string $itemKey): CharacterItem
    {
        return DB::transaction(function () use ($character, $itemKey) {
            $def = Catalog::item($itemKey);
            if ($def === null || ! isset($def['goldPrice'])) {
                throw new GameException('Not for sale.', 'not_found');
            }

            $this->requireSettlement($character, 'trade', $def['station'] ?? 'village');

            if ($character->gold < $def['goldPrice']) {
                throw new GameException('Not enough gold.', 'no_gold');
            }

            $character->gold -= $def['goldPrice'];
            $this->fireTutorial($character, 'buy');
            $character->save();

            return CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => $itemKey,
                'durability' => $def['maxDurability'],
                'equipped' => false,
            ]);
        });
    }

    public function sellMaterial(Character $character, string $materialKey, int $quantity): int
    {
        return DB::transaction(function () use ($character, $materialKey, $quantity) {
            $def = Catalog::material($materialKey);
            if ($def === null) {
                throw new GameException('Unknown material.', 'not_found');
            }

            // You cannot sell in the middle of a forest. The trader is an NPC at
            // a settlement, not a travelling buyer.
            $this->requireSettlement($character, 'trade');

            // §3.3 -- gold must never bridge to NFT-tier value, so the trader
            // simply will not touch rare or raid materials.
            if (($def['npcPrice'] ?? 0) <= 0) {
                throw new GameException('The trader will not touch that.', 'not_sellable');
            }

            $count = max(1, $quantity);
            $this->takeMaterial($character, $materialKey, $count);

            $gold = $def['npcPrice'] * $count;
            $character->gold += $gold;
            $this->fireTutorial($character, 'sell');
            $character->save();

            return $gold;
        });
    }

    // ------------------------------------------------------------------- craft

    public function craftItem(Character $character, string $itemKey): CharacterItem
    {
        return DB::transaction(function () use ($character, $itemKey) {
            $def = Catalog::item($itemKey);
            if ($def === null || ! isset($def['inputs'])) {
                throw new GameException('Not craftable.', 'not_found');
            }

            if (isset($def['station'])) {
                $this->requireSettlement($character, 'craft', $def['station']);
            }

            foreach ($def['inputs'] as $key => $qty) {
                if ($this->held($character, $key) < $qty) {
                    $name = Catalog::material($key)['name'] ?? $key;
                    throw new GameException("Not enough {$name}.", 'insufficient');
                }
            }
            foreach ($def['inputs'] as $key => $qty) {
                $this->takeMaterial($character, $key, $qty);
            }

            $this->fireTutorial($character, 'craft');
            $character->save();

            return CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => $itemKey,
                'durability' => $def['maxDurability'],
                'equipped' => false,
            ]);
        });
    }

    // --------------------------------------------------------------- equipment

    private function ownedItem(Character $character, int $itemId): CharacterItem
    {
        $item = $character->items()->where('id', $itemId)->first();
        if ($item === null) {
            throw new GameException('You do not own that.', 'not_found');
        }

        return $item;
    }

    public function equipItem(Character $character, int $itemId): void
    {
        $item = $this->ownedItem($character, $itemId);
        $def = Catalog::item($item->item_key);

        if ($item->durability <= 0) {
            throw new GameException('Broken. Repair it before equipping.', 'broken');
        }

        // One item per slot.
        foreach ($character->items as $other) {
            if ($other->id !== $item->id && (Catalog::item($other->item_key)['slot'] ?? null) === $def['slot']) {
                if ($other->equipped) {
                    $other->equipped = false;
                    $other->save();
                }
            }
        }

        $item->equipped = true;
        $item->save();

        $this->fireTutorial($character, 'equip');
        $character->save();
    }

    public function unequipItem(Character $character, int $itemId): void
    {
        $item = $this->ownedItem($character, $itemId);
        $item->equipped = false;
        $item->save();
    }

    public function repairItem(Character $character, int $itemId): void
    {
        DB::transaction(function () use ($character, $itemId) {
            $item = $this->ownedItem($character, $itemId);
            $def = Catalog::item($item->item_key);
            $missing = $def['maxDurability'] - $item->durability;

            if ($missing <= 0) {
                throw new GameException('Nothing to repair.', 'noop');
            }

            if (isset($def['inputs'])) {
                $cost = Formulas::repairCost($def, $missing);
                foreach ($cost as $key => $qty) {
                    if ($this->held($character, $key) < $qty) {
                        $name = Catalog::material($key)['name'] ?? $key;
                        throw new GameException("Repair needs {$qty} {$name}.", 'insufficient');
                    }
                }
                foreach ($cost as $key => $qty) {
                    $this->takeMaterial($character, $key, $qty);
                }
            } else {
                // Basic gear is repaired by the NPC for gold, §3.2 -- which means
                // standing in front of one.
                $this->requireSettlement($character, 'trade');
                $gold = (int) ceil(($def['goldPrice'] ?? 20) * ($missing / $def['maxDurability']) * 0.6);
                if ($character->gold < $gold) {
                    throw new GameException('Not enough gold.', 'no_gold');
                }
                $character->gold -= $gold;
                $character->save();
            }

            $item->durability = $def['maxDurability'];
            $item->save();
        });
    }

    /** @return array<string,int> salvage returned */
    public function discardItem(Character $character, int $itemId): array
    {
        return DB::transaction(function () use ($character, $itemId) {
            $item = $this->ownedItem($character, $itemId);
            $def = Catalog::item($item->item_key);

            // §8.2 -- a small salvage return clears inventory bloat and gives
            // obsolete gear an exit that is not just deletion.
            $salvage = Formulas::salvageYield($def);
            foreach ($salvage as $key => $qty) {
                $this->addMaterial($character, $key, $qty);
            }

            $item->delete();

            return $salvage;
        });
    }

    // ----------------------------------------------------------------- payload

    public function jobPayload(GameJob $job): array
    {
        $now = $this->now();

        $payload = [
            'id' => (string) $job->id,
            'kind' => $job->kind,
            'status' => $job->isReady($now) ? 'ready' : 'active',
            'quantity' => $job->quantity,
            'startedAt' => $job->started_at,
            'endsAt' => $job->ends_at,
            'skill' => $job->skill_key,
        ];

        if ($job->kind === 'mining') {
            return $payload + [
                'col' => $job->col,
                'row' => $job->row,
                'slot' => $job->slot,
                'material' => $job->material_key,
            ];
        }

        return $payload + [
            'settlementId' => $job->settlement_id,
            'recipeKey' => $job->recipe_key,
            'input' => $job->material_key,
            'output' => $job->output_key,
            'presence' => $job->presence,
        ];
    }

    /** The full authoritative snapshot returned by every mutating call. */
    public function playerState(Character $character): array
    {
        $character->load(['materials', 'skills', 'items', 'jobs']);
        $now = $this->now();

        $skills = [];
        foreach ($character->skills as $skill) {
            $skills[$skill->skill_key] = [
                'key' => $skill->skill_key,
                'level' => $skill->level,
                'xp' => $skill->xp,
                'xpToNext' => Balance::skillXpForLevel($skill->level),
            ];
        }

        return [
            'serverTime' => $now,
            // The client renders countdowns against this rather than carrying its
            // own copy of the dev clock compression. One source of truth.
            'timeScale' => Balance::timeScale(),
            'character' => [
                'id' => (string) $character->id,
                'name' => $character->name,
                // The player's own address, in full. Abbreviating it here would
                // only hide it from the one person it belongs to, and would make
                // "copy address" impossible; the UI shortens it for display.
                'wallet' => $character->player->wallet,
                'level' => $character->level,
                'xp' => $character->xp,
                'xpToNext' => Balance::xpForLevel($character->level),
                'ap' => $character->ap,
                'apMax' => Balance::apMax($character->level),
                'apRegenMs' => Balance::scaled(Balance::AP_REGEN_MS),
                'apUpdatedAt' => $character->ap_updated_at,
                'gold' => $character->gold,
                'col' => $character->col,
                'row' => $character->row,
                'storageUsed' => (int) $character->materials->sum('quantity'),
                'storageCap' => Balance::storageCap($character->level),
                // How far the character can reach, §7.1. Published rather than
                // recomputed client-side so the map's range ring and the rule
                // that rejects a trip are always the same number.
                'travelRange' => $this->travelRange($character),
                'tutorialStep' => $character->tutorial_step,
            ],
            'skills' => $skills,
            // Cast to object so an empty inventory serialises as {} rather than
            // [], which is what the client's Record<MaterialKey, number> expects.
            'inventory' => (object) $character->materials
                ->where('quantity', '>', 0)
                ->pluck('quantity', 'material_key')
                ->map(fn ($q) => (int) $q)
                ->all(),
            'equipment' => $character->items->map(fn (CharacterItem $i) => [
                'id' => (string) $i->id,
                'key' => $i->item_key,
                'durability' => $i->durability,
                'equipped' => $i->equipped,
            ])->values()->all(),
            'jobs' => $character->jobs->map(fn (GameJob $j) => $this->jobPayload($j))->values()->all(),
            'presenceAt' => $character->presence_settlement_id,
            // The journey in progress, §5: where it ends, when, and the hexes
            // it crosses. The client animates against this and nothing else.
            'travel' => $this->travelState($character),
            // Where the character is standing. The client gates trade, crafting
            // and processing on this rather than deriving it, so the UI and the
            // server can never disagree about what is possible here.
            'standingAt' => $this->currentSettlement($character),
            // The hex under the character's feet, costed. The dock acts on this
            // rather than on whatever is selected, because selecting is aiming
            // and the dock is only ever about here.
            'underfoot' => $this->previewTile($character, $character->col, $character->row),
            'shopStock' => $this->shopStock($character),
            'bonuses' => $this->bonuses($character),
        ];
    }
}
