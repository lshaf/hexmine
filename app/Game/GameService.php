<?php

declare(strict_types=1);

namespace App\Game;

use App\Models\Character;
use App\Models\CharacterBuff;
use App\Models\CharacterConsumable;
use App\Models\CharacterItem;
use App\Models\CharacterJob;
use App\Models\CharacterMaterial;
use App\Models\CharacterNode;
use App\Models\CharacterSkill;
use App\Models\GameJob;
use App\Models\Player;
use App\Models\TileState;
use Illuminate\Database\Eloquent\Model;
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
     * woodcutting line a short walk away. Villages run only 1 of 5 lines (§6),
     * so unconstrained spawns leave most players with no nearby way to turn
     * wood into planks -- and with sight down to one hex (§5.6) they would
     * not even be able to see that there is one.
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
        $range = Balance::SPAWN_VILLAGE_RADIUS;

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
            ]);

            foreach (Catalog::SKILLS as $skill) {
                CharacterSkill::create([
                    'character_id' => $character->id,
                    'skill_key' => $skill,
                    'level' => 1,
                    'xp' => 0,
                ]);
            }

            // §7.4 -- every job exists from the start at level 1, including the
            // three dormant battle ones. A tree you can look at and cannot yet
            // afford is information; a tree that appears out of nowhere later is
            // a surprise.
            // §7.4 -- a row per job that needs one. The five gathering jobs are
            // deliberately absent: their level is the CharacterSkill level above,
            // so giving them a second row would be two places to disagree about
            // one number.
            foreach (Jobs::JOBS as $job => $def) {
                if ($def['kind'] === Jobs::GATHERING) {
                    continue;
                }

                CharacterJob::create([
                    'character_id' => $character->id,
                    'job_key' => $job,
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
     * Nothing in this game ticks: AP regen, arrival and buff expiry are derived
     * from timestamps, so an hour offline and an hour idle produce the same
     * result.
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

        if ($dirty) {
            $character->save();
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

        // §7.6 -- a kind the bag is not already holding needs a free row, and
        // when there is none the haul does not land. Silent rather than thrown,
        // because the callers that grant are collections: they report what was
        // lost through `lostToOverflow` and carry on.
        if ($current <= 0 && ! $this->hasFreeRow($character)) {
            return 0;
        }

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

    // -------------------------------------------------------------------- bag

    /**
     * §7.6 -- what is in the bag, against the two limits on it.
     *
     * Two numbers, because one is not enough. `units` is the weight of the thing
     * -- every material, every potion, every unworn tool -- and `rows` is how
     * many separate things they are. A bucket has only the first, and a bucket
     * lets a prospector carry one of everything for free, which is exactly the
     * pressure §4's biome-locked ladder is built on.
     *
     * **Worn gear is not carried.** An equipped axe is on your belt, not in your
     * pack, so equipping is itself a way to free a row -- and a prospector who
     * has committed to their five lines is not punished for it. Spares and
     * anything waiting to be equipped cost a row each, because two axes do not
     * stack.
     *
     * Derived on every read and never stored. A counter would be a second
     * opinion about what you are carrying, and the two would eventually
     * disagree the first time a row was deleted somewhere that forgot about it.
     *
     * @return array{units:int,unitCap:int,rows:int,rowCap:int,over:bool}
     */
    public function bag(Character $character): array
    {
        $materials = $character->materials()->where('quantity', '>', 0)->pluck('quantity');
        $potions = $character->consumables()->where('quantity', '>', 0)->pluck('quantity');
        // Queried rather than read off the loaded relation: the bag is checked
        // right after equipping and dropping, and a cached collection would be
        // one action out of date exactly when it matters.
        $loose = $character->items()->where('equipped', false)->count();

        $units = (int) $materials->sum() + (int) $potions->sum() + $loose;
        $rows = $materials->count() + $potions->count() + $loose;

        $effects = $this->nodeEffects($character);
        $unitCap = Balance::BAG_UNITS + $effects['bagUnits'];
        $rowCap = Balance::BAG_ROWS + $effects['bagRows'];

        return [
            'units' => $units,
            'unitCap' => $unitCap,
            'rows' => $rows,
            'rowCap' => $rowCap,
            'over' => $units > $unitCap || $rows > $rowCap,
        ];
    }

    /**
     * §7.6 -- is there a strap free for something the bag is not already holding?
     *
     * The two limits refuse in two different ways, on purpose. **Units** may go
     * over: a haul lands whole, and being too heavy stops the road rather than
     * the work, which is a decision the player can undo from where they stand.
     * **Rows** may not, because a row is not weight -- it is a place to put a
     * thing, and there is nowhere to put a thing that has no strap. So the row
     * limit is checked at the door and the unit limit at the gate.
     *
     * More of what you already carry never needs a new row, and that asymmetry
     * is the whole point: the limit is on *variety*, which is what keeps §4's
     * five lines a choice instead of a checklist.
     */
    public function hasFreeRow(Character $character): bool
    {
        $bag = $this->bag($character);

        return $bag['rows'] < $bag['rowCap'];
    }

    /** True when this material can land: an open strap, or a stack to join. */
    public function canTakeMaterial(Character $character, string $key): bool
    {
        return $this->held($character, $key) > 0 || $this->hasFreeRow($character);
    }

    /**
     * §7.6 -- refuse a new row, in the words the player needs.
     *
     * Called before anything is spent, never after: a craft that takes the
     * planks and then finds nowhere to put the axe would be the worst version
     * of this rule.
     */
    private function requireFreeRow(Character $character, string $what): void
    {
        if ($this->hasFreeRow($character)) {
            return;
        }

        $bag = $this->bag($character);

        throw new GameException(
            "No room for {$what} — your bag is full at {$bag['rowCap']} kinds. Clear one out first.",
            'no_room',
        );
    }

    /**
     * §7.6 -- why you cannot leave, in the words the player needs.
     *
     * Null when the bag is fine. The message names the limit that is broken and
     * by how much, because "your bag is full" in front of a map that will not
     * move is the kind of refusal that reads as a bug.
     */
    public function overloaded(Character $character): ?string
    {
        $bag = $this->bag($character);

        if ($bag['units'] > $bag['unitCap']) {
            $over = $bag['units'] - $bag['unitCap'];

            return "Too much to carry — {$bag['units']} of {$bag['unitCap']}. Sell, process or drop {$over} before you set off.";
        }

        if ($bag['rows'] > $bag['rowCap']) {
            $over = $bag['rows'] - $bag['rowCap'];
            $things = $over === 1 ? 'one kind of thing' : "{$over} kinds of thing";

            return "Your pack will not close — {$bag['rows']} kinds against {$bag['rowCap']} straps. Clear {$things} before you set off.";
        }

        return null;
    }

    /**
     * Tip materials out on the ground, §11.1.
     *
     * Nothing comes back. Selling is what pays, and selling needs a trader --
     * this is for the pile of scrap filling your bag three hexes from anywhere,
     * where the only thing worth having is the room. Giving it a salvage return
     * would make it a worse shop that works everywhere, which is not a decision
     * anyone should have to weigh.
     *
     * @return int how many were actually thrown away
     */
    public function discardMaterial(Character $character, string $materialKey, int $quantity): int
    {
        return DB::transaction(function () use ($character, $materialKey, $quantity) {
            if (Catalog::material($materialKey) === null) {
                throw new GameException('Unknown material.', 'not_found');
            }

            $held = $this->held($character, $materialKey);
            if ($held <= 0) {
                throw new GameException('You are not carrying any.', 'insufficient');
            }

            // Asking to drop more than you have drops what you have, rather than
            // failing: the intent is unambiguous and refusing it is just rude.
            $count = min($held, max(1, $quantity));
            $this->takeMaterial($character, $materialKey, $count);

            return $count;
        });
    }

    // -------------------------------------------------------------- equipment

    /** @return array<int,array{key:string,durability:int,equipped:bool}> */
    private function itemRows(Character $character): array
    {
        return $character->items->map(fn (CharacterItem $i) => [
            'key' => $i->item_key,
            'durability' => $i->durability,
            'equipped' => $i->equipped,
            'options' => $i->options ?? [],
        ])->all();
    }

    /**
     * §8.0.1 -- roll a new item's bonus lines.
     *
     * The seed mixes the wallet, the item and the clock so two players crafting
     * the same recipe in the same second do not get the same roll, and so a
     * given outcome can still be reproduced from what produced it.
     *
     * @return array<int,array{stat:string,value:float}>
     */
    private function rollFor(Character $character, array $def, int $extra = 0): array
    {
        $seed = Hash::hash2(
            (int) $character->id + $this->now() % 100000,
            crc32($def['name']),
            Balance::MAP_SEED,
        );

        return Formulas::rollOptions($def, $seed, $extra);
    }

    /**
     * §8.0.1 -- turn a chance into a count of extra option rolls.
     *
     * Server-rolled from a seed like every other outcome, and shared by the
     * bazaar and by a Smith's bench so the two cannot drift into different
     * ideas of what "sometimes" means.
     */
    private function extraRoll(Character $character, float $chance, int $salt): int
    {
        if ($chance <= 0) {
            return 0;
        }

        $roll = Hash::rand01(Hash::hash2(
            (int) $character->id,
            $this->now() % 100000,
            Balance::MAP_SEED ^ $salt,
        ));

        return $roll < $chance ? 1 : 0;
    }

    /**
     * §8.0.1 -- the capital bazaar's extra slot. A capital stocks nothing a city
     * does not; what it sometimes adds is a line on top, and it is the only
     * place a common item ever carries one.
     */
    private function bazaarBonus(Character $character): int
    {
        $here = $this->currentSettlement($character);
        if ($here === null || $here['tier'] !== 'capital') {
            return 0;
        }

        $roll = Hash::rand01(Hash::hash2(
            (int) $character->id,
            $this->now() % 100000,
            Balance::MAP_SEED ^ 0xba2a,
        ));

        return $roll < Balance::CAPITAL_SHOP_OPTION_CHANCE ? 1 : 0;
    }

    /**
     * Aggregated equipment bonuses. `$line` is the skill line being worked --
     * §8 gathering tools only count for their own line, so a trip must say which
     * line it is, and a read with no line in mind (the hero sheet, travel range)
     * gets only the gear that works everywhere.
     *
     * @return array<string,float>
     */
    public function bonuses(Character $character, ?string $line = null): array
    {
        $items = $this->itemRows($character);

        // §8.5 -- a live buff is another contributor to the same aggregate, and
        // is clamped by the same ceiling. A potion that could push a stat past
        // STAT_CEILING would be a power ladder you can drink, which §8.1 rule 1
        // exists to prevent.
        $buffs = [];
        foreach ($this->liveBuffs($character) as $buff) {
            $buffs[$buff->stat] = ($buffs[$buff->stat] ?? 0) + $buff->value;
        }

        // §7.4.3 -- bought tree nodes are a third contributor to the very same
        // sum, and go under the very same ceiling. This is the whole balance
        // argument for letting 90 skill points exist: a point buys a different
        // road to +15%, never a higher one. Clamping the tree separately and
        // adding it afterwards would let two clamped halves total 30%.
        $effects = $this->nodeEffects($character);
        $tree = $effects['stats'];

        // A gathering line's nodes only count on that line's own work, so they
        // join the sum only when a line is being asked about.
        if ($line !== null) {
            foreach ($effects['byLine'][$line] ?? [] as $stat => $value) {
                $tree[$stat] = ($tree[$stat] ?? 0) + $value;
            }
        }

        $out = [];
        foreach (['yield', 'tripReduction', 'travelSpeed', 'processingSpeed', 'power', 'defence'] as $stat) {
            $gear = Formulas::aggregateStat($items, $stat, $line);
            $out[$stat] = min(
                Balance::STAT_CEILING,
                $gear + ($buffs[$stat] ?? 0) + ($tree[$stat] ?? 0),
            );
        }

        return $out;
    }

    // ------------------------------------------------------- consumables §8.5

    /** How many of a potion the character is carrying. */
    public function heldConsumable(Character $character, string $key): int
    {
        return (int) ($character->consumables()->where('item_key', $key)->value('quantity') ?? 0);
    }

    /**
     * Buffs that have not run out, §8.5.
     *
     * Expired rows are deleted on read rather than swept by a job: this is the
     * same lazy-settlement idea as AP regen (§16), and it means a buff cannot
     * linger just because nobody looked.
     *
     * @return array<int,\App\Models\CharacterBuff>
     */
    public function liveBuffs(Character $character): array
    {
        $now = $this->now();
        $character->buffs()->where('expires_at', '<=', $now)->delete();
        $character->unsetRelation('buffs');

        return $character->buffs()->where('expires_at', '>', $now)->get()->all();
    }

    /**
     * Drink one. §11.1 -- the buff expiring is the sink, which is why nothing
     * here is permanent and why a second flask refreshes rather than stacks.
     *
     * @return array{stat:string,value:float,expiresAt:int}
     */
    public function useConsumable(Character $character, string $key): array
    {
        return DB::transaction(function () use ($character, $key) {
            $def = Catalog::item($key);
            if ($def === null || empty($def['consumable'])) {
                throw new GameException('That is not something you can use.', 'not_found');
            }

            $row = $character->consumables()->where('item_key', $key)->first();
            if ($row === null || $row->quantity < 1) {
                throw new GameException("You have no {$def['name']}.", 'insufficient');
            }

            $row->quantity -= 1;
            $row->quantity > 0 ? $row->save() : $row->delete();

            $now = $this->now();
            $expiresAt = $now + Balance::scaled(Balance::BUFF_MS);

            // One buff per stat. Drinking a second of the same kind restarts the
            // clock instead of stacking -- stacking would let a player bank an
            // afternoon of potions into one enormous window.
            CharacterBuff::updateOrCreate(
                ['character_id' => $character->id, 'stat' => $def['stat']],
                ['item_key' => $key, 'value' => $def['value'], 'expires_at' => $expiresAt],
            );
            $character->unsetRelation('buffs');

            return ['stat' => $def['stat'], 'value' => $def['value'], 'expiresAt' => $expiresAt];
        });
    }

    /**
     * Whether the character is carrying a working tool for this line, §8.0.
     * Broken counts as absent: §8.2 makes a 0-durability item inactive, and it
     * would be a strange rule that let a snapped axe still fell trees.
     */
    public function hasLineTool(Character $character, string $line): bool
    {
        $slot = Catalog::slotForSkill($line);

        foreach ($character->items as $item) {
            if (! $item->equipped || $item->durability <= 0) {
                continue;
            }
            if ((Catalog::item($item->item_key)['slot'] ?? null) === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * What each line's equipped tool is worth on its own trips, §8. The hero
     * sheet needs all five at once; there is no single "yield bonus" any more.
     *
     * @return array<string,float>
     */
    public function toolYieldByLine(Character $character): array
    {
        $items = $this->itemRows($character);
        $out = [];
        foreach (Catalog::SKILLS as $line) {
            $out[$line] = Formulas::aggregateStat($items, 'yield', $line);
        }

        return $out;
    }

    /**
     * §5.6 -- how far this character can see, right now.
     *
     * Two hexes standing still, nothing at all on the road, and up to four for
     * an Explorer deep enough into the chain (§7.5). Deliberately untouched by
     * level or gear: the only thing that widens the eye is having walked, which
     * is the one behaviour worth paying for here and the only one whose reward
     * cannot be bought.
     */
    public function sightRadius(Character $character): int
    {
        if ($this->isTravelling($character)) {
            return Balance::SIGHT_TRAVELLING;
        }

        // §7.5 -- the one thing that widens it, already capped.
        return Balance::SIGHT_RADIUS + $this->nodeEffects($character)['sight'];
    }

    /** §8.3 -- the character's own walking pace, in wall-clock ms per hex. */
    public function travelMsPerHex(Character $character): int
    {
        return Balance::scaled(
            Balance::travelMsPerHex($this->bonuses($character)['travelSpeed']),
        );
    }

    /**
     * Wear on the gear that did the work. §8 gathering tools are line-locked, so
     * only the tool for `$line` wears -- the axe on your back does not blunt
     * itself while you are down a mine. Everything worn on the body wears every
     * trip regardless.
     */
    private function drainDurability(Character $character, int $amount, ?string $line = null): int
    {
        $drained = 0;
        foreach ($character->items as $item) {
            if (! $item->equipped || $item->durability <= 0) {
                continue;
            }
            $def = Catalog::item($item->item_key);
            if ($def === null) {
                continue;
            }
            $toolLine = Catalog::skillForSlot($def['slot'] ?? '');
            if ($toolLine !== null && $toolLine !== $line) {
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
        // Mining only. §5.5 hunts sit on a hex without taking one of its two
        // seats -- the pelts come off the herd, not out of the ground -- so
        // counting them here would let a wandering herd close a seam.
        return GameJob::where('col', $col)
            ->where('row', $row)
            ->where('kind', 'mining')
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
        // Hunting counts, §5.5. A herd is not a seam, but it is still a person
        // out in the field, and a person is only in one place at a time -- so a
        // hunt blocks a dig and a dig blocks a hunt.
        return $character->jobs()->whereIn('kind', ['mining', 'hunting'])->first();
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
     * Scoped to sight (§5.6), which is one hex at the start and three at the
     * end of the Explorer chain, and takes no arguments. The
     * camera can be dragged anywhere and costs nothing, because terrain is
     * derived (§5); but live state follows the character, not the camera, so a
     * client cannot walk a viewport parameter across the map to harvest where
     * everyone is mining. Nothing outside sight is knowable, not merely undrawn.
     *
     * One hex is seven tiles and three is thirty-seven, rather than the several
     * hundred that reach-as-sight scanned, and a character on the road sees zero
     * -- so the walk itself costs no queries at all, however long it is.
     *
     * @return array{depleted:array<int,array{0:int,1:int,2:int}>,occupied:array<int,array{0:int,1:int,2:int}>}
     */
    public function mapMutations(Character $character): array
    {
        $now = $this->now();
        $range = $this->sightRadius($character);
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

    /**
     * Server-computed preview of what a trip here would cost and give.
     *
     * Bounded by sight (§5.6), and that bound is the point: a costed hex names
     * its material, its haul and how many people are already on it, which is
     * exactly the live state the two-hex disc exists to withhold. Without the
     * check this endpoint would be the map query in a slower form -- one tile
     * per request, but no limit on how many hexes a client asks about.
     *
     * The hex underfoot is distance 0, so it survives a sight of zero and the
     * dock keeps working on the road.
     */
    public function previewTile(Character $character, int $col, int $row): array
    {
        $now = $this->now();

        $distance = HexGeometry::distance((int) $character->col, (int) $character->row, $col, $row);

        if ($distance > $this->sightRadius($character)) {
            return [
                'canMine' => false,
                'reason' => $this->isTravelling($character)
                    ? 'You are watching your feet. Nothing is scouted until you stop.'
                    : 'Too far to make out. Walk there and see for yourself.',
                'seconds' => 0,
                'baseSeconds' => 0,
                'skillReduction' => 0,
                'equipReduction' => 0,
                'clamped' => false,
                'yield' => 0,
                'material' => null,
                'skill' => null,
                'scrap' => false,
                'note' => null,
                'apCost' => Balance::MINING_AP_COST,
                'unseen' => true,
            ];
        }

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
            'skill' => null,
            'scrap' => false,
            'note' => null,
            'apCost' => Balance::MINING_AP_COST,
            'unseen' => false,
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
        // §8 -- the only tool that counts here is the one for this tile's line.
        $bonuses = $this->bonuses($character, $skillKey);

        // §4.0 -- no tool for this line means bare hands, and bare hands bring
        // back scrap. The hex is not blocked: you may always work it, you just
        // will not get the material out of it. This is the whole reason the
        // first tool is worth buying, so it must never read as a refusal.
        $bare = ! $this->hasLineTool($character, $skillKey);
        $material = $bare ? Catalog::BIOME_SCRAP[$tile['biome']] : $tile['material'];
        $note = null;

        if ($bare) {
            // The slot keys are the nouns -- axe, pickaxe, bow, hammer, sickle.
            $tool = Catalog::slotForSkill($skillKey);
            $scrap = Catalog::material($material)['name'];
            $real = Catalog::material($tile['material'])['name'];
            $note = "No {$tool} — bare hands take {$scrap} here, not {$real}.";
        }

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
                ? 'Your reward is waiting. Claim it before working anything else.'
                : 'You are already working a hex. Finish that one first.';
        } elseif ($distance !== 0) {
            $reason = 'You are standing elsewhere. Travel to this hex to work it.';
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
            'material' => $material,
            // The line stays the tile's own even on a scrap haul: swinging at a
            // tree by hand is still woodcutting practice, §4.0, just poor.
            'skill' => $skillKey,
            'scrap' => $bare,
            'note' => $note,
            'apCost' => Balance::MINING_AP_COST,
            'unseen' => false,
        ];
    }

    // ----------------------------------------------------------- hunting §5.5

    /**
     * What working a herd on this hex would cost and give.
     *
     * Shaped like previewTile() and bounded by the same sight rule, because a
     * herd is live state: whether one is standing here is exactly the kind of
     * fact §5.6 keeps outside the disc.
     *
     * @return array<string,mixed>
     */
    public function previewHunt(Character $character, int $col, int $row): array
    {
        $now = $this->now();
        $distance = HexGeometry::distance((int) $character->col, (int) $character->row, $col, $row);

        $base = [
            'canHunt' => false,
            'reason' => null,
            'seconds' => Balance::scaled(Balance::HUNT_BASE_SECONDS * 1000) / 1000,
            'herdUntil' => null,
            'yield' => 0,
            'material' => null,
            'scrap' => false,
            'essenceChance' => 0.0,
            'note' => null,
            'apCost' => Balance::HUNT_AP_COST,
            'unseen' => false,
        ];

        if ($distance > $this->sightRadius($character)) {
            $base['reason'] = $this->isTravelling($character)
                ? 'You are watching your feet. Nothing is scouted until you stop.'
                : 'Too far to make out. Walk there and see for yourself.';
            $base['unseen'] = true;

            return $base;
        }

        $tile = $this->buildTile($col, $row, $now);
        $herdUntil = $tile['herdUntil'] ?? null;

        if ($herdUntil === null || $herdUntil <= $now) {
            $base['reason'] = 'No herd here. They wander, and they do not stay long.';

            return $base;
        }

        $base['herdUntil'] = $herdUntil;

        // §8.0 rule 1 -- the bow is the hunting line's tool and nothing else
        // stands in for it. Bare hands still get something, per §4.0.
        $bare = ! $this->hasLineTool($character, 'hunting');
        $material = $bare ? Catalog::BIOME_SCRAP['plains'] : 'pelt';
        $bonuses = $this->bonuses($character, 'hunting');
        $skillLevel = (int) ($character->skills()->where('skill_key', 'hunting')->value('level') ?? 1);

        $rolled = Hash::randInt(
            Hash::hash2($col, $row + $herdUntil, Balance::MAP_SEED ^ 0x8eed),
            Balance::HUNT_PELT_MIN,
            Balance::HUNT_PELT_MAX,
        );

        $base['material'] = $material;
        $base['scrap'] = $bare;
        $base['yield'] = Formulas::tripYield(
            $rolled,
            $skillLevel,
            $bonuses['yield'],
            WorldGen::ringYield($tile['ring']),
        );

        // §4.0 again, and this is the sharp end of it. Essence is the most
        // valuable thing a non-raider can hold, so a bare-handed haul must not
        // reach it -- otherwise the bow is optional on the one line where it
        // pays for a Tier 4 material, and the §8.0 ladder inverts.
        $base['essenceChance'] = $bare ? 0.0 : Balance::HUNT_ESSENCE_CHANCE;

        if ($bare) {
            $base['note'] = 'No bow — bare hands take Torn Hide here, not Pelt, and the herd leaves you nothing else.';
        }

        $working = $this->miningTrip($character);

        $base['reason'] = match (true) {
            $this->isTravelling($character) => 'You are on the road. Stop the journey, or wait until you arrive.',
            $working !== null => $working->isReady($now)
                ? 'Your reward is waiting. Claim it before working anything else.'
                : 'You are already working a hex. Finish that one first.',
            $distance !== 0 => 'You are standing elsewhere. Travel to this hex to hunt it.',
            $character->ap < Balance::HUNT_AP_COST => 'Not enough action points.',
            default => null,
        };

        $base['canHunt'] = $base['reason'] === null;

        return $base;
    }

    /**
     * §5.5 -- AP and time, no party and no raid charge.
     *
     * A hunt takes no tile slot and never depletes the tile: the pelts come off
     * the herd, not out of the ground, so two miners can be working the same hex
     * while everyone else hunts across it.
     */
    public function startHunt(Character $character, int $col, int $row): GameJob
    {
        return DB::transaction(function () use ($character, $col, $row) {
            $preview = $this->previewHunt($character, $col, $row);

            if (! $preview['canHunt']) {
                throw new GameException($preview['reason'] ?? 'Cannot hunt here.', 'blocked');
            }

            $now = $this->now();
            $character->ap -= Balance::HUNT_AP_COST;

            $job = GameJob::create([
                'character_id' => $character->id,
                'kind' => 'hunting',
                'status' => 'active',
                'col' => $col,
                'row' => $row,
                // No slot: a herd is not one of the hex's two seats, §5.5.
                'slot' => null,
                'material_key' => $preview['material'],
                'quantity' => max(1, (int) $preview['yield']),
                'skill_key' => 'hunting',
                'started_at' => $now,
                'ends_at' => $now + Balance::scaled(Balance::HUNT_BASE_SECONDS * 1000),
            ]);

            $character->save();

            return $job;
        });
    }

    // ------------------------------------------------------------------ mining

    public function startMining(Character $character, int $col, int $row): GameJob
    {
        return DB::transaction(function () use ($character, $col, $row) {
            $preview = $this->previewTile($character, $col, $row);
            if (! $preview['canMine']) {
                throw new GameException($preview['reason'] ?? 'Cannot mine here.', 'blocked');
            }

            // §7.6 -- a full bag refuses a kind it is not already carrying, so
            // the refusal belongs here rather than an hour later at the haul.
            if (! $this->canTakeMaterial($character, $preview['material'])) {
                $name = Catalog::material($preview['material'])['name'] ?? $preview['material'];
                $this->requireFreeRow($character, $name);
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
                // From the preview, not from the material: a scrap haul still
                // belongs to the hex's own line, §4.0.
                'skill_key' => $preview['skill'],
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

                // §4.0 -- bare-handed work still teaches the line, badly. Full
                // rate here would make the §8.0 tool ladder optional.
                $xpAmount = Catalog::isScrap($job->material_key)
                    ? max(1, (int) round($job->quantity * 4 * Balance::SCRAP_XP_RATE))
                    : $job->quantity * 4;

                // Nothing was in your hands, so nothing wore out.
                $durabilityLost = $this->drainDurability($character, Balance::DRAIN_PER_MINE, $job->skill_key);

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
            } elseif ($job->kind === 'hunting') {
                $granted = $this->addMaterial($character, $job->material_key, $job->quantity);
                $lostToOverflow = $job->quantity - $granted;
                $gained[$job->material_key] = $granted;

                $bare = Catalog::isScrap($job->material_key);

                $xpAmount = $bare
                    ? max(1, (int) round($job->quantity * 4 * Balance::SCRAP_XP_RATE))
                    : $job->quantity * 4;

                // §5.5 -- the bridge to the raid track, and the only Tier 4
                // faucet outside a dungeon. Rolled server-side from a seed like
                // every other outcome, and closed to bare hands: see
                // previewHunt() for why that is not a tuning value.
                if (! $bare) {
                    $roll = Hash::rand01(
                        Hash::hash2($job->col + $job->id, $job->row + $now, Balance::MAP_SEED ^ 0x3550)
                    );

                    if ($roll < Balance::HUNT_ESSENCE_CHANCE) {
                        $essence = $this->addMaterial($character, 'essence', 1);
                        $lostToOverflow += 1 - $essence;

                        if ($essence > 0) {
                            $gained['essence'] = $essence;
                        }
                    }
                }

                // A bow is drawn, so a bow wears. The other four slots idle,
                // §8.0 rule 2 -- drainDurability already scopes to the line.
                $durabilityLost = $this->drainDurability($character, Balance::DRAIN_PER_MINE, 'hunting');

                // No depletion and no TileState row: the herd was the resource,
                // and it leaves on its own clock whatever anybody does here.
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

            // §7.6 -- same rule as a dig: the output needs a strap before the
            // inputs are spent, not after the run finishes.
            if (! $this->canTakeMaterial($character, $recipe['output'])) {
                $name = Catalog::material($recipe['output'])['name'] ?? $recipe['output'];
                $this->requireFreeRow($character, $name);
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
                    ? 'Claim your reward before you move on.'
                    : 'You are working this hex. Claim it when it finishes, or drop it.',
                'working',
            );
        }

        if ($this->isTravelling($character)) {
            throw new GameException(
                'You are already on the road. Stop where you are before setting a new course.',
                'travelling',
            );
        }

        // Anywhere on the map, seen or not (§5.6). Distance is the whole cost:
        // the far side of the world is a walk of days, which is a decision the
        // clock enforces on its own. The only refusal left is the map's edge.
        if ($col < 0 || $col >= Balance::MAP_COLS || $row < 0 || $row >= Balance::MAP_ROWS) {
            throw new GameException('There is nothing past the edge of the map.', 'off_map');
        }

        $distance = HexGeometry::distance($character->col, $character->row, $col, $row);
        if ($distance === 0) {
            throw new GameException('You are already standing here.', 'blocked');
        }

        // §7.6 -- the second refusal, and the only one that is not the edge of
        // the map. An overloaded bag does not stop you working the hex you are
        // standing on, selling, processing or dropping what is in it; it stops
        // you carrying it somewhere else. The way out is always in reach, which
        // is what keeps this a decision rather than a dead end.
        $overloaded = $this->overloaded($character);
        if ($overloaded !== null) {
            throw new GameException($overloaded, 'overloaded');
        }

        // Whatever you were helping with, you are not helping with it any more.
        $this->leavePresence($character);

        $now = $this->now();
        $character->travel_to_col = $col;
        $character->travel_to_row = $row;
        $character->travel_started_at = $now;
        $character->travel_ends_at = $now + $distance * $this->travelMsPerHex($character);
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

        $perHex = $this->journeyPerHex($character);
        $path = $this->travelPath($character);
        $elapsed = max(0, $this->now() - (int) $character->travel_started_at);

        $steps = min(count($path) - 1, intdiv($elapsed, $perHex));
        $stop = $path[$steps];

        $character->col = $stop['col'];
        $character->row = $stop['row'];
        $this->clearTravel($character);
        $this->grantExplorerXp($character, $steps);

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

    /**
     * The pace of the journey actually under way, derived from its own clock.
     *
     * Read back rather than recomputed, so swapping boots mid-walk cannot move
     * the hex a stop would land on: the departure time, the arrival time and
     * the floor that turns one into the other are all the same arithmetic.
     */
    private function journeyPerHex(Character $character): int
    {
        $hexes = max(1, count($this->travelPath($character)) - 1);
        $span = (int) $character->travel_ends_at - (int) $character->travel_started_at;

        return max(1, intdiv($span, $hexes));
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
            'perHexMs' => $this->journeyPerHex($character),
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

        $hexes = count($this->travelPath($character)) - 1;

        $character->col = (int) $character->travel_to_col;
        $character->row = (int) $character->travel_to_row;
        $this->clearTravel($character);
        $this->grantExplorerXp($character, $hexes);

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
            // §3.2 -- gold reaches the bottom two rungs and stops. This is the
            // rule that keeps gold from ever bridging to NFT value, so it is
            // checked here rather than left to the catalog getting it right.
            if (Balance::rarityRank($def['rarity']) > Balance::rarityRank(Balance::SHOP_RARITY_CAP)) {
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

            // §7.6 -- gear does not stack, so a purchase is always a new row.
            $this->requireFreeRow($character, $def['name']);

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
                'options' => $this->rollFor($character, $def, $this->bazaarBonus($character)),
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

    /** @return CharacterItem|CharacterConsumable a new object, or the grown stack */
    public function craftItem(Character $character, string $itemKey): Model
    {
        return DB::transaction(function () use ($character, $itemKey) {
            $def = Catalog::item($itemKey);
            if ($def === null || ! isset($def['inputs'])) {
                throw new GameException('Not craftable.', 'not_found');
            }

            $here = $this->requireSettlement($character, 'craft');

            // §8.0 -- the bench's reach, checked against rarity rather than the
            // item's own `station`. Both gates agree today; rarity goes first
            // because it can say *why*, and because it is the one that still
            // holds when someone adds a recipe and forgets to set a station.
            if (! Balance::stationReaches($here['tier'], $def['rarity'])) {
                $needs = Balance::stationForRarity($def['rarity']);
                $reason = $needs === null
                    ? "{$def['name']} is never crafted. It only drops."
                    : "A {$here['tier']} bench cannot make {$def['rarity']} work. That needs a {$needs}.";

                throw new GameException($reason, 'station');
            }

            if (isset($def['station'])) {
                $this->requireSettlement($character, 'craft', $def['station']);
            }

            // §7.4.3 -- a Smith's cheaper crafts do not make an Armorer's
            // cheaper, so the discount is read from the job whose bench this is.
            // Never below one of anything: a free craft is not a discount, it is
            // a hole in the §11 materials sink.
            // §7.6 -- a potion joins a shelf it may already have; anything with
            // a slot is a new row every time, because gear does not stack.
            if (empty($def['consumable']) || $this->heldConsumable($character, $itemKey) <= 0) {
                $this->requireFreeRow($character, $def['name']);
            }

            $effects = $this->craftEffects($character, $this->jobForItem($def));
            $inputs = [];
            foreach ($def['inputs'] as $key => $qty) {
                $inputs[$key] = max(1, (int) round($qty * (1 - $effects['costReduction'])));
            }

            foreach ($inputs as $key => $qty) {
                if ($this->held($character, $key) < $qty) {
                    $name = Catalog::material($key)['name'] ?? $key;
                    throw new GameException("Not enough {$name}.", 'insufficient');
                }
            }
            foreach ($inputs as $key => $qty) {
                $this->takeMaterial($character, $key, $qty);
            }

            $this->fireTutorial($character, 'craft');
            $character->save();

            // §7.4 -- the bench that made it is the job that learns from it, and
            // a better piece teaches more: common 10 through epic 40.
            $jobKey = $this->jobForItem($def);
            if ($jobKey !== null) {
                $this->grantJobXp(
                    $character,
                    $jobKey,
                    Balance::JOB_XP_PER_RARITY_RANK * (Balance::rarityRank($def['rarity']) + 1),
                );
            }

            // §8.5 -- a potion stacks on a shelf. It has no durability to track
            // and no slot to sit in, so it never becomes a CharacterItem.
            if (! empty($def['consumable'])) {
                $row = CharacterConsumable::firstOrNew([
                    'character_id' => $character->id,
                    'item_key' => $itemKey,
                ]);

                if ($row->quantity >= Balance::CONSUMABLE_STACK_CAP) {
                    throw new GameException(
                        "You cannot carry more than {$row->quantity} {$def['name']}.",
                        'at_cap',
                    );
                }

                $row->quantity = min(Balance::CONSUMABLE_STACK_CAP, (int) $row->quantity + 1);
                $row->save();

                return $row;
            }

            // §7.4.3 -- a better-made thing lasts longer. Capped, because
            // durability is the repair sink and this thins it.
            $durability = (int) round($def['maxDurability'] * (1 + $effects['craftDurability']));

            return CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => $itemKey,
                'durability' => $durability,
                'equipped' => false,
                'options' => $this->rollFor($character, $def, $this->extraRoll(
                    $character,
                    $effects['craftOption'],
                    0x5c11,
                )),
            ]);
        });
    }

    // ------------------------------------------------------------------ jobs §7.4

    /**
     * §7.4.1 -- points come from character levels and nothing else.
     *
     * Spent is counted from the rows rather than stored, so the two can never
     * drift apart. There is no refund: §7.4.2 makes buying permanent, because a
     * respec would turn the 100-point cap into a suggestion.
     *
     * @return array{total:int,spent:int,available:int}
     */
    public function skillPoints(Character $character): array
    {
        $total = Balance::skillPointsFor($character->level);
        $spent = $character->nodes()->count();

        return [
            'total' => $total,
            'spent' => $spent,
            'available' => max(0, $total - $spent),
        ];
    }

    /**
     * Job levels, keyed by job.
     *
     * Two sources on purpose. Craft and battle jobs keep their own row; the five
     * gathering jobs read the skill level they have always had (§7.2), which is
     * the same number that takes time off a trip (§7.3). Reusing it means a
     * gathering tree is playable the moment it exists, and means there is never
     * a second opinion about how good a woodcutter someone is.
     */
    private function jobLevels(Character $character): array
    {
        $levels = $character->jobLevels()->pluck('level', 'job_key')->all();

        foreach ($character->skills()->get() as $skill) {
            if (isset(Jobs::JOBS[$skill->skill_key])) {
                $levels[$skill->skill_key] = $skill->level;
            }
        }

        return $levels;
    }

    /** Job levels for the state payload, both kinds folded into one list. */
    public function jobLevelPayload(Character $character): array
    {
        $out = [];

        foreach ($this->jobLevels($character) as $key => $level) {
            $isGathering = Jobs::JOBS[$key]['kind'] === Jobs::GATHERING;

            $out[] = [
                'key' => $key,
                'level' => $level,
                'xp' => $isGathering
                    ? (int) ($character->skills()->where('skill_key', $key)->value('xp') ?? 0)
                    : (int) ($character->jobLevels()->where('job_key', $key)->value('xp') ?? 0),
                'xpToNext' => $isGathering
                    ? Balance::skillXpForLevel($level)
                    : Balance::jobXpForLevel($level),
            ];
        }

        return $out;
    }

    /**
     * §7.4 -- a craft teaches the job whose bench made it, and a walk teaches
     * the only job that learns from walking (§7.5).
     *
     * Two kinds are deliberately unreachable from here. Gathering jobs read
     * their CharacterSkill level instead (§7.2), so writing a row for one would
     * create a second opinion about a number that already exists. Battle jobs
     * level by raiding (§9) and by nothing else; giving them a stand-in source
     * would make combat optional, which is the whole reason the slot and the
     * trees are dormant rather than absent.
     */
    private function grantJobXp(Character $character, string $jobKey, int $amount): void
    {
        $def = Jobs::JOBS[$jobKey] ?? null;
        if ($def === null || $amount <= 0) {
            return;
        }
        if ($def['kind'] === Jobs::GATHERING || $def['kind'] === Jobs::BATTLE) {
            return;
        }

        // firstOrCreate rather than first: createCharacter seeds a row per job,
        // but a character made before a job existed has none, and the first
        // walk should start the trade rather than quietly discard the XP.
        $row = $character->jobLevels()->firstOrCreate(
            ['job_key' => $jobKey],
            ['level' => 1, 'xp' => 0],
        );

        $result = Formulas::applyXp(
            $row->level,
            $row->xp,
            $amount,
            Balance::JOB_MAX_LEVEL,
            fn (int $l) => Balance::jobXpForLevel($l),
        );

        $row->level = $result['level'];
        $row->xp = $result['xp'];
        $row->save();
    }

    /**
     * §7.5 -- what a walk is worth.
     *
     * Explorer XP and no character XP, which is the whole shape of the system:
     * walking cannot level you, so nobody walks to grind, and the only thing it
     * advances is the ability to walk further and see more of what you walked
     * to. Paid on hexes actually crossed, so a journey abandoned halfway pays
     * for the half that happened -- the same arithmetic that decides which hex
     * you are standing on when you stop.
     */
    private function grantExplorerXp(Character $character, int $hexes): void
    {
        if ($hexes <= 0) {
            return;
        }

        $this->grantJobXp($character, 'explorer', $hexes * Balance::EXPLORER_XP_PER_HEX);
    }

    /** The job a crafted item teaches, from its category (§8.4). */
    private function jobForItem(array $def): ?string
    {
        $category = Catalog::category($def);

        foreach (Jobs::JOBS as $key => $job) {
            if ($job['kind'] === Jobs::CRAFT && $job['source'] === $category) {
                return $key;
            }
        }

        return null;
    }

    /**
     * §7.4.3 -- what this character's bought nodes add up to.
     *
     * Returned as a bundle rather than applied here, because the pieces land in
     * different places: `stat` joins the gear aggregate inside its clamp, the
     * rest apply at the craft site. Each non-stat total is clamped to its own
     * cap, which is what keeps a maxed tree from switching off a §11 sink.
     *
     * @return array{stats:array<string,float>,unlocks:array<int,string>,byJob:array<string,array<string,float>>,sight:int,bagUnits:int,bagRows:int}
     */
    public function nodeEffects(Character $character): array
    {
        $stats = [];
        $byLine = [];
        $unlocks = [];
        $byJob = [];
        $sight = 0;
        $bagUnits = 0;
        $bagRows = 0;

        foreach ($this->ownedNodes($character) as $key) {
            $node = Jobs::node($key);
            if ($node === null) {
                continue;
            }

            $effect = $node['effect'];
            $job = $node['job'];

            switch ($effect['kind']) {
                case 'stat':
                    // §8 rule 1 again: a gathering node pays out on its own line
                    // and no other, exactly as that line's tool does. Three
                    // gathering trees must not stack yield on every trip.
                    if (Jobs::JOBS[$job]['kind'] === Jobs::GATHERING) {
                        $line = Jobs::JOBS[$job]['source'];
                        $byLine[$line][$effect['stat']] =
                            ($byLine[$line][$effect['stat']] ?? 0) + $effect['value'];
                        break;
                    }

                    $stats[$effect['stat']] = ($stats[$effect['stat']] ?? 0) + $effect['value'];
                    break;
                case 'unlock':
                    $unlocks[] = $effect['target'];
                    break;
                case 'sight':
                    // §7.5 -- hexes, not a percentage, so it has nothing to do
                    // with the stat ceiling and everything to do with the query
                    // cap it is bounded by.
                    $sight += (int) $effect['value'];
                    break;
                case 'bagUnits':
                    // §7.6 -- counts, like sight, and bounded by their own caps
                    // rather than the stat ceiling. What they thin is the §11
                    // pressure to sell, process and dump, not a power curve.
                    $bagUnits += (int) $effect['value'];
                    break;
                case 'bagRows':
                    $bagRows += (int) $effect['value'];
                    break;
                default:
                    $byJob[$job][$effect['kind']] = ($byJob[$job][$effect['kind']] ?? 0) + $effect['value'];
            }
        }

        // Stats are NOT clamped here. They are handed to the same aggregate as
        // gear so they share one falloff and one ceiling (§8.1 rule 1); clamping
        // twice would let the sum of two clamped halves pass the cap.
        $caps = [
            'costReduction' => Balance::SKILL_COST_REDUCTION_CAP,
            'craftDurability' => Balance::SKILL_DURABILITY_CAP,
            'craftOption' => Balance::SKILL_OPTION_CHANCE_CAP,
            'batch' => Balance::SKILL_BATCH_CAP,
        ];
        foreach ($byJob as $job => $kinds) {
            foreach ($kinds as $kind => $value) {
                $byJob[$job][$kind] = min($value, $caps[$kind] ?? $value);
            }
        }

        return [
            'stats' => $stats,
            'byLine' => $byLine,
            'unlocks' => $unlocks,
            'byJob' => $byJob,
            'sight' => min($sight, Balance::SKILL_SIGHT_CAP),
            'bagUnits' => min($bagUnits, Balance::SKILL_BAG_UNITS_CAP),
            'bagRows' => min($bagRows, Balance::SKILL_BAG_ROWS_CAP),
        ];
    }

    /**
     * §7.4 + §7.5 -- every node this character has, however it got there.
     *
     * Two sources, and only one of them is a table. Bought nodes are rows;
     * wayfaring nodes are a function of a job level and are never written down,
     * because a granted row would be a second place for "do you have this yet"
     * to be answered and the two would eventually disagree. It also means the
     * point ledger stays honest: `skillPoints()` counts rows, so a granted node
     * cannot cost a point by accident.
     *
     * @return array<int,string>
     */
    public function ownedNodes(Character $character): array
    {
        return array_merge(
            $character->nodes()->pluck('node_key')->all(),
            Jobs::granted($this->jobLevels($character)),
        );
    }

    /** One job's capped craft effects, or zeroes. */
    private function craftEffects(Character $character, ?string $jobKey): array
    {
        $zero = ['costReduction' => 0.0, 'craftDurability' => 0.0, 'craftOption' => 0.0, 'batch' => 0.0];
        if ($jobKey === null) {
            return $zero;
        }

        return array_merge($zero, $this->nodeEffects($character)['byJob'][$jobKey] ?? []);
    }

    /**
     * §7.4 -- buy one node.
     *
     * Every gate is checked here and nowhere else that matters: the client draws
     * the tree, the server decides what may be bought (§16). The unique index on
     * (character, node) is the last line of defence against a doubled request.
     */
    public function buyNode(Character $character, string $nodeKey): array
    {
        return DB::transaction(function () use ($character, $nodeKey) {
            $node = Jobs::node($nodeKey);
            if ($node === null) {
                throw new GameException('No such skill.', 'not_found');
            }

            if (Jobs::isAutomatic($node['job'])) {
                $name = Jobs::JOBS[$node['job']]['name'];
                throw new GameException(
                    "{$name} skills are not bought. {$node['name']} arrives at {$name} level {$node['jobLevel']}.",
                    'granted',
                );
            }

            if ($character->nodes()->where('node_key', $nodeKey)->exists()) {
                throw new GameException("You already have {$node['name']}.", 'owned');
            }

            $points = $this->skillPoints($character);
            if ($points['available'] < 1) {
                throw new GameException('No skill points left. Level up first.', 'no_points');
            }

            $jobLevel = $this->jobLevels($character)[$node['job']] ?? 1;
            if ($jobLevel < $node['jobLevel']) {
                $name = Jobs::JOBS[$node['job']]['name'];
                throw new GameException(
                    "{$node['name']} needs {$name} level {$node['jobLevel']}. You are level {$jobLevel}.",
                    'job_level',
                );
            }

            foreach ($node['requires'] as $parentKey) {
                if (! $character->nodes()->where('node_key', $parentKey)->exists()) {
                    $parent = Jobs::node($parentKey);
                    throw new GameException("{$node['name']} needs {$parent['name']} first.", 'requires');
                }
            }

            CharacterNode::create(['character_id' => $character->id, 'node_key' => $nodeKey]);

            return ['node' => $nodeKey, 'points' => $this->skillPoints($character->fresh())];
        });
    }

    // ------------------------------------------------------------- end jobs §7.4

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

        // §7.6 -- worn is not carried, so taking something off is the one action
        // that *adds* a row. With no strap free it stays on the belt, which is
        // the only place left for it.
        $this->requireFreeRow($character, Catalog::item($item->item_key)['name'] ?? 'it');

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
                // §7.6 -- the bag, both limits, and whether it is over one of
                // them. Published rather than derived client-side because it is
                // what the travel refusal is decided on, and the map must never
                // offer a walk the server will refuse.
                'bag' => $this->bag($character),
                // §5.6 -- how far the character can see. There is no reach to
                // publish any more: every hex is walkable. Sight is published
                // rather than mirrored client-side so the fog on the map and
                // the endpoints that refuse to cost an unscouted hex are always
                // the same number, and so it can drop to zero on the road.
                'sight' => $this->sightRadius($character),
                // §8.3 -- the character's pace, for costing a walk before it is
                // taken. Already wall-clock: the dev clock is applied here.
                'travelPerHexMs' => $this->travelMsPerHex($character),
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
                'options' => $i->options ?? [],
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
            'underfoot' => [
                ...$this->previewTile($character, $character->col, $character->row),
                'hunt' => $this->previewHunt($character, $character->col, $character->row),
            ],
            'shopStock' => $this->shopStock($character),
            // §7.4 -- what the tree panel needs about *this* character. The tree
            // itself is static and comes from GET /api/jobs instead, so it is
            // fetched once rather than on every state refresh.
            'skillPoints' => $this->skillPoints($character),
            // Not 'jobs': that key is already the running mining and processing
            // work above, and a duplicate here silently clobbered it.
            'jobLevels' => $this->jobLevelPayload($character),
            'nodes' => $this->ownedNodes($character),
            'bonuses' => $this->bonuses($character),
            'toolYield' => $this->toolYieldByLine($character),
            // §8.5 -- what is on the shelf, and what is running right now.
            'consumables' => $character->consumables()
                ->where('quantity', '>', 0)
                ->pluck('quantity', 'item_key')
                ->all(),
            'buffs' => array_map(fn (CharacterBuff $b) => [
                'key' => $b->item_key,
                'stat' => $b->stat,
                'value' => $b->value,
                'expiresAt' => $b->expires_at,
            ], $this->liveBuffs($character)),
        ];
    }
}
