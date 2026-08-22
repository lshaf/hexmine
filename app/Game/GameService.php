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
use App\Models\CharacterQuest;
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
     * It also has to guarantee the §12 opening arc is completable from where it
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
        $angle = Hash::rand01(Hash::hash2($seed, 1, Balance::mapSeed())) * M_PI * 2;
        $radius = 0.78 + Hash::rand01(Hash::hash2($seed, 2, Balance::mapSeed())) * 0.14;
        $startCol = (int) round(cos($angle) * $radius * Balance::mapRadius());
        $startRow = (int) round(sin($angle) * $radius * Balance::mapRadius());

        $edge = Balance::mapRadius();
        $fallback = ['col' => $startCol, 'row' => $startRow];
        $range = Balance::SPAWN_VILLAGE_RADIUS;

        for ($ring = 0; $ring < 70; $ring++) {
            for ($dc = -$ring; $dc <= $ring; $dc++) {
                for ($dr = -$ring; $dr <= $ring; $dr++) {
                    if (max(abs($dc), abs($dr)) !== $ring) {
                        continue;
                    }
                    $col = min($edge, max(-$edge, $startCol + $dc));
                    $row = min($edge, max(-$edge, $startRow + $dr));

                    if (WorldGen::biomeOf($col, $row) !== 'forest') {
                        continue;
                    }
                    if (WorldGen::ringOf($col, $row) !== 'outer') {
                        continue;
                    }
                    if (WorldGen::settlementAt($col, $row) !== null) {
                        continue;
                    }
                    // §5.3 -- nobody starts in a lake. Water refuses both
                    // verbs, so a spawn on it would open the game on a hex
                    // with nothing to do and no explanation of why.
                    if (WorldGen::waterAt($col, $row) !== null) {
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
                'gold' => Balance::STARTING_GOLD,
                'col' => $spawn['col'],
                'row' => $spawn['row'],
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

        if ($dirtyTravel) {
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
            Balance::mapSeed(),
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
            Balance::mapSeed() ^ $salt,
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
            Balance::mapSeed() ^ 0xba2a,
        ));

        return $roll < Balance::CAPITAL_SHOP_OPTION_CHANCE ? 1 : 0;
    }

    /**
     * Aggregated equipment bonuses.
     *
     * `$action` is what is being costed, and it is one of the seven §8.5 names:
     * a gathering line, `travel`, or `processing`. §8 gathering tools only count
     * for their own line, so a trip must say which one it is, and a read with no
     * action in mind (the hero sheet) gets only the gear that works everywhere.
     *
     * `$line` is the *material* line underneath that action, and it exists
     * because §6 processing has both: sawing planks is the action `processing`
     * on the line `woodcutting`. Gear and potions are scoped by the action, a
     * line-locked tree node by the line. For a gathering trip the two are the
     * same word and the second argument is left off.
     *
     * @return array<string,float>
     */
    public function bonuses(Character $character, ?string $action = null, ?string $line = null): array
    {
        $items = $this->itemRows($character);

        // §8.5 -- an armed charge is another contributor to the same aggregate,
        // and is clamped by the same ceiling. A potion that could push a stat
        // past STAT_CEILING would be a power ladder you can drink, which §8.1
        // rule 1 exists to prevent.
        //
        // §8.5 -- a scoped charge counts only when its own action is the one
        // being costed. `global` always counts; a line scope counts on that
        // line's work; `travel` and `processing` are asked for by name. This is
        // the same filter §7.4.3 already applies to line-locked tree nodes, and
        // it is what lets seventy potions exist without seventy of them stacking.
        //
        // §8.5 -- and potions take the HIGHEST rather than the sum. A player may
        // hold as many different effects as they like, but two charges on the
        // same stat are the same effect twice: the better draught wins and the
        // other is simply not felt. Summing would let a `global` charge and a
        // line-scoped one quietly double up on one trip, which is the stack the
        // rung ladder exists to prevent -- twelve potions a rung would otherwise
        // be a way of buying the ceiling in instalments.
        $buffs = [];
        foreach ($this->armedBuffs($character) as $buff) {
            $scope = $buff->scope ?? 'global';
            if ($scope !== 'global' && $scope !== $action) {
                continue;
            }

            $buffs[$buff->stat] = max($buffs[$buff->stat] ?? 0.0, (float) $buff->value);
        }

        // §7.4.3 -- bought tree nodes are a third contributor to the very same
        // sum, and go under the very same ceiling. This is the whole balance
        // argument for letting 90 skill points exist: a point buys a different
        // road to +15%, never a higher one. Clamping the tree separately and
        // adding it afterwards would let two clamped halves total 30%.
        $effects = $this->nodeEffects($character);
        $tree = $effects['stats'];

        // A line-locked node -- gathering or processing -- only counts on its
        // own line's work, so these join the sum only when a line is being asked
        // about. The bucket is the action and the line together, which is what
        // keeps a Sawyer's speed off a woodcutting trip: both are `woodcutting`
        // work and only one of them happens at a saw pit.
        $nodeLine = $line === null ? $action : $action.':'.$line;
        if ($nodeLine !== null) {
            foreach ($effects['byLine'][$nodeLine] ?? [] as $stat => $value) {
                $tree[$stat] = ($tree[$stat] ?? 0) + $value;
            }
        }

        $out = [];
        foreach (['yield', 'tripReduction', 'travelSpeed', 'processingSpeed', 'power', 'defence'] as $stat) {
            // Gear is scoped by the action, not the line: an axe is a forest
            // tool, and standing at a saw pit is not swinging it (§8 rule 1).
            $gear = Formulas::aggregateStat($items, $stat, $action);
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
     * Charges waiting on an action, §8.5.
     *
     * Every row here is armed: there is no clock to compare against and nothing
     * to sweep, because a draught is ended by being used rather than by running
     * out. The unique index on (character, stat, scope) caps how many can be
     * held at once.
     *
     * @return array<int,\App\Models\CharacterBuff>
     */
    public function armedBuffs(Character $character): array
    {
        return $character->buffs()->get()->all();
    }

    /**
     * Spend whatever was armed for this action, §8.5.
     *
     * Called once the work has been costed and committed -- the job row already
     * carries the shortened clock and the larger haul, so the charge has done
     * its job and being spent is the sink (§11.1). `global` goes with it: a
     * draught that applies everywhere applied here.
     *
     * Read-only paths never call this. Costing a hex you are only looking at
     * must not burn what you are carrying, which is why this is a step of its
     * own rather than something bonuses() does on the way past.
     */
    public function spendBuffs(Character $character, string $action): void
    {
        $spent = $character->buffs()
            ->whereIn('scope', ['global', $action])
            ->delete();

        if ($spent > 0) {
            $character->unsetRelation('buffs');
        }
    }

    /**
     * Drink one. §8.5 -- this arms the action the draught names; it does not
     * start a clock. A second flask of the same kind replaces the charge rather
     * than stacking, which is what stops an afternoon of potions being banked.
     *
     * @return array{stat:string,scope:string,value:float}
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

            // §8.5 -- one charge per stat PER ACTION. A woodcutting draught and
            // a mining draught are different things and both may be held; two
            // draughts on the same stat and the same action are the same thing
            // twice, and the better one wins.
            $scope = $def['scope'] ?? 'global';

            $armed = $character->buffs()
                ->where('stat', $def['stat'])
                ->where('scope', $scope)
                ->first();

            // Refused before the flask is opened, never after. A weaker draught
            // poured on top of a stronger one would be paid for and never felt,
            // and an idle game must not take something away for nothing -- so
            // this reads as "you already have better", not as a downgrade.
            if ($armed !== null && (float) $armed->value >= (float) $def['value']) {
                throw new GameException(
                    $armed->item_key === $key
                        ? "A {$def['name']} is already waiting on the same work. A second would not make it any stronger."
                        : sprintf(
                            '%s is already waiting on the same work, and it is the stronger of the two. Keep the %s for later.',
                            Catalog::item($armed->item_key)['name'] ?? 'A stronger draught',
                            $def['name'],
                        ),
                    'weaker_charge',
                );
            }

            $row->quantity -= 1;
            $row->quantity > 0 ? $row->save() : $row->delete();

            CharacterBuff::updateOrCreate(
                ['character_id' => $character->id, 'stat' => $def['stat'], 'scope' => $scope],
                ['item_key' => $key, 'value' => $def['value']],
            );
            $character->unsetRelation('buffs');

            return [
                'stat' => $def['stat'],
                'scope' => $scope,
                'value' => $def['value'],
            ];
        });
    }

    /**
     * Whether the character is carrying a working tool for this line, §8.0.
     * Broken counts as absent: §8.2 makes a 0-durability item inactive, and it
     * would be a strange rule that let a snapped axe still fell trees.
     */
    /**
     * The rarity of the tool doing this line's work, or null bare-handed.
     *
     * §5.3 -- the tool sets which grade of material you can reliably take, so
     * this is the one thing the drop table needs to know about the belt. Best
     * equipped wins, because nothing stops a player owning two.
     */
    public function lineToolRarity(Character $character, string $line): ?string
    {
        $slot = Catalog::slotForSkill($line);
        $best = null;

        foreach ($character->items as $item) {
            if (! $item->equipped || $item->durability <= 0) {
                continue;
            }

            $def = Catalog::item($item->item_key);
            if (($def['slot'] ?? null) !== $slot) {
                continue;
            }

            if ($best === null || Balance::rarityRank($def['rarity']) > Balance::rarityRank($best)) {
                $best = $def['rarity'];
            }
        }

        return $best;
    }

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
     * A tool for this line is equipped, and it is at zero durability (§8.2).
     *
     * Worth telling apart from having none at all: broken gear is not destroyed
     * gear, so the answer is a repair rather than a purchase, and a refusal
     * that says "no axe" to someone wearing one reads as a bug.
     */
    public function brokenLineTool(Character $character, string $line): bool
    {
        $slot = Catalog::slotForSkill($line);

        foreach ($character->items as $item) {
            if (! $item->equipped || $item->durability > 0) {
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
     * an Explorer deep enough into the tree (§7.5). Deliberately untouched by
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
        // §8.5 -- 'travel' by name, or a road potion would be filtered out of
        // the one thing it was drunk for.
        return Balance::scaled(
            Balance::travelMsPerHex($this->bonuses($character, 'travel')['travelSpeed']),
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

        return $result['levelsGained'];
    }

    // ----------------------------------------------------------------- quests §12

    /**
     * §12 -- credit a counted goal.
     *
     * Called from wherever the work actually finishes. Two properties matter and
     * both come from riding the call sites the game already had rather than
     * inventing new ones: a quest can only ever be advanced by work the server
     * itself witnessed, and adding a quest costs a row in Quests::DEFS rather
     * than a new hook.
     *
     * These are the same eleven sites the tutorial cursor used to sit on, which
     * is most of why §12 converted to quests without losing anything.
     *
     * A quest already claimed is skipped. Counting past a finished quest would
     * be bookkeeping nobody reads, and it is the one place a `claimed_at` check
     * is cheaper than the alternative.
     *
     * A quest still LOCKED is counted anyway, and deliberately. A prospector who
     * walked two hundred hexes before anybody wrote the quest down has still
     * walked them, and being handed a task already half done is a better welcome
     * than being told to start again.
     */
    private function fireQuest(Character $character, string $kind, int $amount, ?string $subject = null): void
    {
        if ($amount <= 0) {
            return;
        }

        foreach (Quests::counting($kind, $subject) as $key) {
            $row = $character->quests()->firstOrCreate(
                ['quest_key' => $key],
                ['progress' => 0],
            );

            if ($row->claimed_at !== null) {
                continue;
            }

            // Held at the target rather than run past it: a counter that keeps
            // climbing after the goal is met is a number the panel would have to
            // apologise for.
            $row->progress = min(
                Quests::DEFS[$key]['goal']['target'],
                (int) $row->progress + $amount,
            );
            $row->save();
        }

        $character->unsetRelation('quests');
    }

    /**
     * §12 -- where a quest stands, whichever kind of goal it has.
     *
     * A counted goal reads its stored tally; a measured one is read off the
     * character every single time. The second is the reason there is no
     * `completed_at` column: "am I level five" is a question with a live answer,
     * and storing it would let the stored copy and the character disagree.
     */
    private function questProgress(Character $character, string $key, ?CharacterQuest $row): int
    {
        $goal = Quests::DEFS[$key]['goal'];

        return match ($goal['kind']) {
            'level' => (int) $character->level,
            'job' => $this->jobLevels($character)[$goal['subject']] ?? 0,
            default => (int) ($row->progress ?? 0),
        };
    }

    /**
     * §12 -- a quest is offered once the one before it has been *claimed*.
     *
     * Claimed rather than merely met, so the chain advances on a decision the
     * player made. A quest still locked is not sent to the client at all: what
     * is next should be legible, and what comes after that is not yet anybody's
     * problem.
     */
    private function questUnlocked(array $claimed, string $key): bool
    {
        $requires = Quests::DEFS[$key]['requires'] ?? null;

        return $requires === null || in_array($requires, $claimed, true);
    }

    /**
     * §12 -- every quest this character can see, with where it stands.
     *
     * Rides in the state payload rather than on the quests endpoint, because it
     * changes with almost every action while the catalog behind it never does.
     *
     * @return array<int,array<string,mixed>>
     */
    public function questPayload(Character $character): array
    {
        $rows = $character->quests()->get()->keyBy('quest_key');
        $claimed = $rows->filter(fn (CharacterQuest $q) => $q->claimed_at !== null)
            ->pluck('quest_key')
            ->all();

        $out = [];

        foreach (Quests::DEFS as $key => $def) {
            if (! $this->questUnlocked($claimed, $key)) {
                continue;
            }

            $row = $rows->get($key);
            $progress = $this->questProgress($character, $key, $row);

            $out[] = [
                'key' => $key,
                'progress' => min($progress, $def['goal']['target']),
                // Derived here rather than client-side: whether a goal is met is
                // a rule, and §16 puts rules on this side of the wire.
                'complete' => $progress >= $def['goal']['target'],
                'claimed' => $row?->claimed_at !== null,
                'claimedAt' => $row?->claimed_at,
            ];
        }

        return $out;
    }

    /**
     * §12 -- take the gold, once and forever.
     *
     * Every gate is here rather than on the button: the client draws a list, the
     * server decides what is payable (§16). The unique index on (character,
     * quest) is the last line of defence against a doubled request, exactly as
     * it is for a bought node.
     *
     * @return array<string,mixed>
     */
    public function claimQuest(Character $character, string $key): array
    {
        return DB::transaction(function () use ($character, $key) {
            $def = Quests::def($key);
            if ($def === null) {
                throw new GameException('No such quest.', 'not_found');
            }

            $rows = $character->quests()->get()->keyBy('quest_key');
            $claimed = $rows->filter(fn (CharacterQuest $q) => $q->claimed_at !== null)
                ->pluck('quest_key')
                ->all();

            if (! $this->questUnlocked($claimed, $key)) {
                $before = Quests::DEFS[$def['requires']]['name'];
                throw new GameException("{$before} comes first.", 'locked');
            }

            $row = $character->quests()->firstOrCreate(
                ['quest_key' => $key],
                ['progress' => 0],
            );

            if ($row->claimed_at !== null) {
                throw new GameException('Already claimed. A quest pays once.', 'already_claimed');
            }

            $progress = $this->questProgress($character, $key, $row);
            if ($progress < $def['goal']['target']) {
                throw new GameException('Not finished yet.', 'incomplete');
            }

            // §3.2 -- gold and only gold. A quest that paid a material would be
            // a hole in §2 rather than a nicer reward.
            $character->gold += $def['gold'];
            $row->claimed_at = $this->now();
            $row->save();
            $character->save();
            $character->unsetRelation('quests');

            return [
                'quest' => $key,
                'name' => $def['name'],
                'gold' => $def['gold'],
                'goldAfter' => (int) $character->gold,
                // What this claim just made visible, so the modal can say the
                // road goes on rather than ending on a receipt.
                'unlocked' => array_values(array_map(
                    fn (string $next) => ['key' => $next, 'name' => Quests::DEFS[$next]['name']],
                    array_filter(
                        array_keys(Quests::DEFS),
                        fn (string $next) => (Quests::DEFS[$next]['requires'] ?? null) === $key,
                    ),
                )),
            ];
        });
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

        // §9.5.1 -- the seed says a pack is standing here; the cache says
        // whether anybody has already settled it. Win or lose it is gone, and
        // folding that in here means no reader downstream has to remember to
        // ask.
        if ($tile['pack'] !== null && Packs::isCleared($col, $row, $tile['pack']['bucket'])) {
            $tile['pack'] = null;
        }

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
            'seed' => Balance::mapSeed(),
            'size' => Balance::mapSize(),
            'radius' => Balance::mapRadius(),
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
            // §9.5.1 -- sent rather than compiled in, like every other
            // generation constant: the algorithm is mirrored, the numbers are
            // not, so tuning the rings cannot silently desync the two.
            'packLifetimeMs' => Balance::scaled(Balance::PACK_LIFETIME_MS),
            'packChance' => Balance::PACK_CHANCE,
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
     * end of the Explorer tree, and takes no arguments. The
     * camera can be dragged anywhere and costs nothing, because terrain is
     * derived (§5); but live state follows the character, not the camera, so a
     * client cannot walk a viewport parameter across the map to harvest where
     * everyone is mining. Nothing outside sight is knowable, not merely undrawn.
     *
     * One hex is seven tiles and three is thirty-seven, rather than the several
     * hundred that reach-as-sight scanned, and a character on the road sees zero
     * -- so the walk itself costs no queries at all, however long it is.
     *
     * `cleared` is the third of those things and the only one that is a
     * subtraction rather than an addition: §9.5.1 packs are derived from the
     * seed, so the client already knows where they stand -- what it cannot know
     * is which of them somebody has already fought. One MGET over the disc
     * answers that for every hex at once.
     *
     * @return array{depleted:array<int,array{0:int,1:int,2:int}>,occupied:array<int,array{0:int,1:int,2:int}>,cleared:array<int,array{0:int,1:int}>}
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

        // §9.5.1 -- generating the disc is thirty-seven tiles at the very most
        // (§5.6 caps sight at three), and it is the only way to know which of
        // them the hash put a pack on this bucket.
        $packs = [];
        for ($col = $minCol; $col <= $maxCol; $col++) {
            for ($row = $minRow; $row <= $maxRow; $row++) {
                if (! $inSight($col, $row)) {
                    continue;
                }

                $pack = WorldGen::generateTile($col, $row, $now)['pack'] ?? null;
                if ($pack !== null) {
                    $packs[] = ['col' => $col, 'row' => $row, 'bucket' => $pack['bucket']];
                }
            }
        }

        return [
            'depleted' => $depleted,
            'occupied' => $occupied,
            'cleared' => Packs::clearedAmong($packs),
        ];
    }

    // ------------------------------------------------------------ combat §9.5

    /**
     * §9.5.3 -- is a pack standing on the hex this character is on.
     *
     * The pin is about the ground under your feet and nothing else: a pack two
     * hexes away is a hazard on a road you have not taken yet, and refusing
     * work because of it would be a fence rather than a fight.
     *
     * @return array{key:string,bucket:int,until:int,monster:array<string,mixed>}|null
     */
    public function packHere(Character $character): ?array
    {
        // On the road there is no hex under your feet yet. A traveller is
        // stopped by a pack when they arrive (§9.5.6), not while they walk.
        if ($this->isTravelling($character)) {
            return null;
        }

        $tile = $this->buildTile((int) $character->col, (int) $character->row, $this->now());
        $pack = $tile['pack'] ?? null;

        if ($pack === null || $pack['until'] <= $this->now()) {
            return null;
        }

        return $pack + ['monster' => Monsters::ROSTER[$pack['key']]];
    }

    /**
     * §9.5.3 -- what the pin says when it refuses.
     *
     * It names the thing and its clock, because the two exits are fighting it
     * and waiting it out, and a refusal that mentions neither reads as a bug.
     */
    private function pinnedReason(array $pin): string
    {
        return $pin['monster']['name'].' is standing here. Fight it, or wait for it to move on.';
    }

    /** §9.5.4 -- the battle job the equipped weapon levels, and its level. */
    private function battleJobLevel(Character $character): array
    {
        foreach ($this->itemRows($character) as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }

            $def = Catalog::item($item['key']);
            $family = $def['family'] ?? null;
            if ($family === null) {
                continue;
            }

            $job = Catalog::BATTLE_JOB_FOR_FAMILY[$family] ?? null;
            if ($job === null) {
                continue;
            }

            return ['job' => $job, 'level' => (int) ($this->jobLevels($character)[$job] ?? 0)];
        }

        return ['job' => null, 'level' => 0];
    }

    /**
     * §9.5.5 -- what this fight would cost and what it would probably do.
     *
     * The odds are shown BEFORE anything is committed, which is what makes a
     * forced encounter a decision rather than a gamble. So is the warning: §8.2
     * destroys an item at zero durability, and an idle game may take something
     * expensive from a player but never by surprise.
     */
    public function previewBattle(Character $character): array
    {
        $pin = $this->packHere($character);

        if ($pin === null) {
            return ['canFight' => false, 'reason' => 'Nothing is standing here.'];
        }

        $monster = $pin['monster'];
        $items = $this->itemRows($character);
        $job = $this->battleJobLevel($character);

        // §8.5 -- a battle draught is armed for exactly this and nothing else.
        $bonuses = $this->bonuses($character, 'battle');
        $pair = Formulas::combatPair($items, $job['level'], $bonuses['power'], $bonuses['defence']);

        $warnings = [];
        $wear = ['weapon' => 0, 'armor' => 0];

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }

            $def = Catalog::item($item['key']);
            $slot = $def['slot'] ?? null;
            $max = (int) ($def['maxDurability'] ?? 1);

            // The worst case is what a warning has to be built on: the fight is
            // not rolled yet, and "it might survive" is not a thing to tell
            // somebody about to lose a legendary.
            if ($slot === 'weapon') {
                $wear['weapon'] = Formulas::weaponWear($pair['attack'], $monster, false, $max);
                $cost = $wear['weapon'];
            } elseif (in_array($slot, ['armor', 'boots', 'gloves'], true)) {
                $cost = Formulas::armorWear((int) ($def['defence'] ?? 0), $monster, false, $max);
                $wear['armor'] = max($wear['armor'], $cost);
            } else {
                continue;
            }

            if ($cost >= $item['durability']) {
                $warnings[] = $def['name'].' will not survive this.';
            }
        }

        return [
            'canFight' => true,
            'reason' => null,
            'until' => $pin['until'],
            'monster' => [
                'key' => $pin['key'],
                'name' => $monster['name'],
                'tier' => $monster['tier'],
                'profile' => $monster['profile'],
                'attack' => $monster['attack'],
                'defence' => $monster['defence'],
                'description' => $monster['description'],
            ],
            'attack' => $pair['attack'],
            'defence' => $pair['defence'],
            'odds' => round(Formulas::battleOdds($pair['attack'], $pair['defence'], $monster), 3),
            'job' => $job['job'],
            'jobLevel' => $job['level'],
            'wear' => $wear,
            'warnings' => $warnings,
        ];
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
    public function previewTile(
        Character $character,
        int $col,
        int $row,
        string $activity = Drops::MINING,
    ): array {
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
                'bare' => false,
                'drops' => [],
                'activity' => $activity,
                'skill' => null,
                'scrap' => false,
                'note' => null,
                'unseen' => true,
            ];
        }

        // §9.5.3 -- the pin. A pack on the hex under your feet stops mining,
        // gathering and hunting ANYWHERE in sight, not merely on the hex it is
        // standing on: you are not working while something is looking at you.
        $pin = $this->packHere($character);

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
            'bare' => false,
            'drops' => [],
            'activity' => $activity,
            'skill' => null,
            'scrap' => false,
            'note' => null,
            'unseen' => false,
            // §9.5.3 -- something is standing on the hex you are on.
            'pinned' => false,
        ];

        if ($pin !== null) {
            $base['reason'] = $this->pinnedReason($pin);
            $base['pinned'] = true;

            return $base;
        }

        if ($tile['material'] === null) {
            $base['reason'] = match (true) {
                // §5.3 -- water is the one ground both verbs refuse. Named
                // rather than lumped in with "nothing here": a lake is plainly
                // something, and a refusal that pretends otherwise reads as a
                // bug in the map.
                $tile['water'] === 'lake' => 'Open water. There is nothing here to work, by hand or otherwise.',
                $tile['water'] === 'river' => 'A waterway. There is nothing here to work, by hand or otherwise.',
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
        $gathering = $activity === Drops::GATHERING;

        // §8 -- the only tool that counts here is the one for this tile's line,
        // and a gather uses none: bare hands are bare hands whatever is on the
        // belt, so the line-locked tool and its nodes sit this trip out.
        $bonuses = $this->bonuses($character, $gathering ? null : $skillKey);

        // §4.0 -- the hex is never blocked for want of a tool. It is the VERB
        // that is refused, not the ground: mining wants the line's tool and
        // says so, and gathering is the answer standing next to it. Rolling the
        // two into one button hid that choice behind a haul the player never
        // asked for.
        $bare = ! $this->hasLineTool($character, $skillKey);
        $note = null;

        // §5.3 -- the tool sets the grade, the ground sets the ceiling. A
        // common axe on a Hardwood Stand takes wood nearly every time and
        // hardwood occasionally: the better timber is standing there, you are
        // simply not equipped to take it down.
        $variants = Variants::BIOME_VARIANTS[$tile['biome']];
        $tileGrade = 0;
        foreach ($variants as $index => $variant) {
            if ($variant['key'] === $tile['variant']) {
                $tileGrade = $index;
                break;
            }
        }

        $reach = min(Drops::toolGrade($this->lineToolRarity($character, $skillKey)), $tileGrade);
        $material = $gathering
            ? Catalog::BIOME_SCRAP[$tile['biome']]
            : $variants[$reach]['material'];

        // The slot keys are the nouns -- axe, pickaxe, bow, hammer, sickle.
        $tool = Catalog::slotForSkill($skillKey);

        if ($gathering) {
            $scrap = Catalog::material($material)['name'];
            $real = Catalog::material($tile['material'])['name'];
            $note = "Bare hands. Mostly {$scrap} and whatever grows here, and {$real} only by luck.";
        } elseif ($bare) {
            $note = $this->brokenLineTool($character, $skillKey)
                ? "Your {$tool} is broken. Repair it, or gather this hex by hand."
                : "No {$tool}. Gather this hex by hand, or come back with one.";
        } elseif ($reach < $tileGrade) {
            $have = Catalog::material($variants[$reach]['material'])['name'];
            $best = Catalog::material($variants[$tileGrade]['material'])['name'];
            $note = "This ground carries {$best}. Your tool reliably takes {$have} — the better grade is a long shot.";
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
        } elseif (! $gathering && $bare) {
            // §8.0 rule 1 -- the tool is what makes this mining rather than
            // rummaging, so without it the verb has nothing behind it. The hex
            // stays open: gathering is the same trip on the same ground, and
            // §4.0 is satisfied by the button beside this one, not by quietly
            // handing back scrap from the one that was pressed.
            $reason = $note;
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
            'bare' => $bare,
            // §4 -- what this ground can give up, most likely first. The ODDS
            // are deliberately absent: naming them would turn a hex into a
            // spreadsheet and the decision into arithmetic. What a prospector
            // is owed is what is here, which is a fact about the place; how
            // often is what the trip is for.
            'drops' => Drops::kinds($activity, $tile, $gathering ? 0 : $reach),
            'activity' => $activity,
            // The line stays the tile's own even on a gathered haul: swinging
            // at a tree by hand is still woodcutting practice, §4.0, just poor.
            'skill' => $skillKey,
            'scrap' => $gathering,
            'note' => $note,
            'unseen' => false,
            // §9.5.3 -- nothing is standing on you, or this return was never
            // reached: the pin refuses above, before any of this is costed.
            'pinned' => false,
        ];
    }

    /**
     * §4.0 -- the same hex, worked by hand.
     *
     * A separate costing rather than a fallback inside previewTile(), because
     * the two verbs are now two cells on the dock and each has to be able to
     * say what IT would give. Always available: there is no tool to lack, which
     * is the whole of what makes it the floor under the ladder.
     *
     * @return array<string,mixed>
     */
    public function previewGather(Character $character, int $col, int $row): array
    {
        return $this->previewTile($character, $col, $row, Drops::GATHERING);
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
            'note' => null,
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

        // §8.0 rule 1 -- the bow is the hunting line's tool, and here it is not
        // optional. Mining has a bare-handed floor because a hex is never
        // blocked (§4.0); a hunt is not a hex, it is an animal, and you do not
        // take one down by hand. This is the one refusal §4.0 does not cover,
        // and it is what makes the bow the only tool with a gate behind it.
        if (! $this->hasLineTool($character, 'hunting')) {
            $base['reason'] = 'No bow. A herd is not something you take by hand.';
            $base['note'] = 'Hunting needs a bow equipped — every other line will work bare-handed.';

            return $base;
        }

        // §5.3 -- better ground carries better animals, capped by the bow, the
        // same way a seam is capped by the pick.
        $variants = Variants::BIOME_VARIANTS[$tile['biome']];
        $tileGrade = 0;
        foreach ($variants as $index => $variant) {
            if ($variant['key'] === $tile['variant']) {
                $tileGrade = $index;
                break;
            }
        }

        $bare = false;
        $reach = min(Drops::toolGrade($this->lineToolRarity($character, 'hunting')), $tileGrade);
        $material = Variants::BIOME_VARIANTS['plains'][$reach]['material'];
        $bonuses = $this->bonuses($character, 'hunting');
        $skillLevel = (int) ($character->skills()->where('skill_key', 'hunting')->value('level') ?? 1);

        $rolled = Hash::randInt(
            Hash::hash2($col, $row + $herdUntil, Balance::mapSeed() ^ 0x8eed),
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

        $base['drops'] = Drops::kinds(Drops::HUNTING, $tile, $reach);

        $working = $this->miningTrip($character);

        $base['reason'] = match (true) {
            $this->isTravelling($character) => 'You are on the road. Stop the journey, or wait until you arrive.',
            $working !== null => $working->isReady($now)
                ? 'Your reward is waiting. Claim it before working anything else.'
                : 'You are already working a hex. Finish that one first.',
            $distance !== 0 => 'You are standing elsewhere. Travel to this hex to hunt it.',
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

            // §8.5 -- the haul above already carries the charge, so this is
            // where it is spent.
            $this->spendBuffs($character, 'hunting');

            $character->save();

            return $job;
        });
    }

    // ------------------------------------------------------------------ mining

    /**
     * Work the hex underfoot, §5.1.
     *
     * One method for both verbs, because everything that makes a trip a trip is
     * the same either way: a tile slot, a bag row, a clock and a charge. What
     * the verb decides is the table the haul comes off (§4) and whether a tool
     * is required, and both of those are already settled by the preview.
     */
    public function startMining(
        Character $character,
        int $col,
        int $row,
        string $activity = Drops::MINING,
    ): GameJob {
        return DB::transaction(function () use ($character, $col, $row, $activity) {
            $preview = $this->previewTile($character, $col, $row, $activity);
            if (! $preview['canMine']) {
                throw new GameException($preview['reason'] ?? 'Cannot mine here.', 'blocked');
            }

            // §7.6 -- a full bag refuses a kind it is not already carrying, so
            // the refusal belongs here rather than an hour later at the haul.
            if (! $this->canTakeMaterial($character, $preview['material'])) {
                $name = Catalog::material($preview['material'])['name'] ?? $preview['material'];
                $this->requireFreeRow($character, $name);
            }

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

            // §8.5 -- the clock and the haul above are already costed with
            // whatever was armed for this line, so the charge is spent here.
            $this->spendBuffs($character, $preview['skill']);

            // Nobody moves here: the character was already standing on this hex
            // before the trip could start, and stays on it while the timer runs.
            // Position changes only through explicit travel.
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
                // §4 -- the haul is the same SIZE the tile card promised and a
                // different shape: one draw per unit off the activity's table,
                // so the thing you came for still dominates and the rest of
                // what is lying about on that ground turns up alongside it.
                [$gained, $lostToOverflow] = $this->grantHaul($character, $job);

                // §4.0 -- bare-handed work still teaches the line, badly. Full
                // rate here would make the §8.0 tool ladder optional.
                $xpAmount = Catalog::isScrap($job->material_key)
                    ? max(1, (int) round($job->quantity * 4 * Balance::SCRAP_XP_RATE))
                    : $job->quantity * 4;

                // Nothing was in your hands, so nothing wore out.
                $durabilityLost = $this->drainDurability($character, Balance::DRAIN_PER_MINE, $job->skill_key);

                // §5.1 -- worked-out tiles regrow rather than dying.
                $exhausted = Hash::rand01(
                    Hash::hash2($job->col + $now, $job->row, Balance::mapSeed() ^ 0xdeed)
                ) < Balance::DEPLETE_CHANCE;

                if ($exhausted) {
                    TileState::updateOrCreate(
                        ['col' => $job->col, 'row' => $job->row],
                        ['regrows_at' => $now + Balance::scaled(Balance::REGROW_MS)],
                    );
                }

            } elseif ($job->kind === 'hunting') {
                [$gained, $lostToOverflow] = $this->grantHaul($character, $job);

                $bare = Catalog::isScrap($job->material_key);
                $xpAmount = $bare
                    ? max(1, (int) round($job->quantity * 4 * Balance::SCRAP_XP_RATE))
                    : $job->quantity * 4;

                // A bow is drawn, so a bow wears. The other four slots idle,
                // §8.0 rule 2 -- drainDurability already scopes to the line.
                $durabilityLost = $this->drainDurability($character, Balance::DRAIN_PER_MINE, 'hunting');

                // No depletion and no TileState row: the herd was the resource,
                // and it leaves on its own clock whatever anybody does here.
            } else {
                $granted = $this->addMaterial($character, $job->output_key, $job->quantity);
                $lostToOverflow = $job->quantity - $granted;
                $gained[$job->output_key] = $granted;
                $xpAmount = $job->quantity * 9;

                // §6 -- a finished run teaches the line that ran it. Paid on
                // collection like everything else, so a run walked away from
                // teaches nothing (§11.1), and paid on what came off the bench
                // rather than what went in, because a bigger batch is more work.
                $processJob = $this->jobForLine((string) $job->skill_key);
                if ($processJob !== null) {
                    $this->grantJobXp(
                        $character,
                        $processJob,
                        $job->quantity * Balance::JOB_XP_PER_PROCESS_UNIT,
                    );
                }

            }

            // §12 -- one hook for all three verbs, because what a quest counts
            // is what LANDED in the bag. A unit lost to a full bag was never
            // carried home, and crediting it would pay for a haul the player
            // does not have. Per material, so "ten of anything" and "thirty iron
            // ore" are the same mechanism; a processing run is counted against
            // its line instead, because that is what the bench was running.
            $processing = $job->kind === 'processing';
            foreach ($gained as $materialKey => $qty) {
                $this->fireQuest(
                    $character,
                    $processing ? 'process' : 'gather',
                    (int) $qty,
                    $processing ? (string) $job->skill_key : (string) $materialKey,
                );
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

    /**
     * Turn a finished trip into a haul, §4.
     *
     * The job already records the material its trip resolved to, and that key
     * is the grade the tool could reach -- so the table is rebuilt from the job
     * rather than from whatever is on the belt now, and swapping to a better
     * tool while the timer runs buys nothing.
     *
     * Overflow is counted across the whole haul: the bag can refuse one kind
     * and take another, and the player is owed a single honest number for what
     * would not fit rather than one per stack.
     *
     * @return array{0:array<string,int>,1:int}
     */
    private function grantHaul(Character $character, GameJob $job): array
    {
        $tile = $this->buildTile((int) $job->col, (int) $job->row, $this->now());
        $activity = Drops::activityFor($job->kind, (string) $job->material_key);
        $table = Drops::tableFor($activity, $tile, (string) $job->material_key);

        // Seeded from the job, so a haul is settled the moment it is claimed
        // and cannot be re-rolled by claiming again (§16).
        $rolled = Drops::roll($table, (int) $job->quantity, (int) $job->id);

        $gained = [];
        $lost = 0;

        foreach ($rolled as $key => $qty) {
            $granted = $this->addMaterial($character, (string) $key, $qty);
            $lost += $qty - $granted;

            if ($granted > 0) {
                $gained[(string) $key] = $granted;
            }
        }

        return [$gained, $lost];
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

            // §6 -- the line's own job is what a run is read against. A Sawyer's
            // cheaper planks do not make a Tanner's leather cheaper, exactly as
            // a Smith's discount stops at the Smith's bench (§7.4.3).
            $line = $recipe['skill'];
            $effects = $this->craftEffects($character, $this->jobForLine($line));

            // Never below one of anything per batch: a free run is not a
            // discount, it is a hole in the §11 materials sink.
            $this->takeMaterial(
                $character,
                $recipe['input'],
                max($count, (int) round($recipe['inputQty'] * $count * (1 - $effects['costReduction']))),
            );
            if (isset($recipe['secondInput'])) {
                $this->takeMaterial(
                    $character,
                    $recipe['secondInput'],
                    max($count, (int) round(($recipe['secondInputQty'] ?? 1) * $count * (1 - $effects['costReduction']))),
                );
            }

            $now = $this->now();
            $presence = $character->presence_settlement_id === $settlementId;
            $seconds = Formulas::processingTime(
                $recipe['baseSeconds'] * $count,
                $settlement['tier'],
                $presence,
                // §8.5 -- named, for the same reason travel is. The line comes
                // with it because a processing run has both: this is the action
                // `processing` on the line the recipe belongs to.
                $this->bonuses($character, 'processing', $line)['processingSpeed'],
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
                // §7.4.3 -- `batch` is extra output per RUN, not per batch.
                // Multiplied through the count it would grow with the very
                // number the player chooses, which is not a bounded effect.
                'quantity' => $recipe['outputQty'] * $count + (int) $effects['batch'],
                'skill_key' => $line,
                'started_at' => $now,
                'ends_at' => $now + Balance::scaled($seconds * 1000),
            ]);

            $this->spendBuffs($character, 'processing');

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

        // §9.5.3 -- and the road is shut while something holds the hex. Both
        // ways out are here on this tile: fight it, or wait for its clock.
        // Neither is a dead end, because a loss clears the pack as surely as a
        // win does.
        $pin = $this->packHere($character);
        if ($pin !== null) {
            throw new GameException($this->pinnedReason($pin), 'pinned');
        }

        // Anywhere on the map, seen or not (§5.6). Distance is the whole cost:
        // the far side of the world is a walk of days, which is a decision the
        // clock enforces on its own. The only refusal left is the map's edge.
        if (! WorldGen::inBounds($col, $row)) {
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

        // §8.5 -- the arrival time above is already costed with whatever was
        // armed for the road, so the charge is spent on setting off.
        $this->spendBuffs($character, 'travel');

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
            // §12 -- fired under both names the quest could mean: the item and
            // the slot it goes in. The matcher knows about neither.
            $this->fireQuest($character, 'buy', 1, $itemKey);
            $this->fireQuest($character, 'buy', 1, $def['slot'] ?? null);
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
            // §12 -- counted in gold taken rather than stacks sold, so a
            // quest about the trader is about the rate rather than the trips.
            $this->fireQuest($character, 'sell', $gold);
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

            // §12 -- the item and its bench, so a quest may name either.
            $this->fireQuest($character, 'craft', 1, $itemKey);
            $this->fireQuest($character, 'craft', 1, Catalog::category($def));
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
     * §7.4 -- a craft teaches the job whose bench made it, a finished §6 run
     * teaches the line that ran it, and a walk teaches the only job that learns
     * from walking (§7.5).
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

        // §12 -- the same hexes, counted once. Paid on ground actually
        // crossed, so a journey abandoned halfway credits the half that
        // happened -- the same arithmetic the Explorer is paid on.
        $this->fireQuest($character, 'travel', $hexes);
    }

    /**
     * §6 -- the job a processing run teaches, from the line it belongs to.
     *
     * The recipe already names its line and the job already names its source,
     * so the two are matched rather than a third table being written down. A
     * line with no processing job simply teaches nothing, which is what makes
     * this safe to call before every run.
     */
    private function jobForLine(string $line): ?string
    {
        foreach (Jobs::JOBS as $key => $job) {
            if ($job['kind'] === Jobs::PROCESSING && $job['source'] === $line) {
                return $key;
            }
        }

        return null;
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
                    //
                    // §6 -- a processing node is line-locked by the same rule
                    // and for the same reason: a Sawyer is faster at a saw pit,
                    // not at a tannery. It files under `processing:<line>`
                    // rather than the bare line, because a saw pit and a forest
                    // are two different pieces of work on the same word --
                    // filing both under `woodcutting` would pay a Sawyer out on
                    // a felling trip.
                    $jobKind = Jobs::JOBS[$job]['kind'];
                    if ($jobKind === Jobs::GATHERING || $jobKind === Jobs::PROCESSING) {
                        $line = $jobKind === Jobs::PROCESSING
                            ? Jobs::PROCESSING.':'.Jobs::JOBS[$job]['source']
                            : Jobs::JOBS[$job]['source'];
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

        // §12 -- the item and the slot, same as a purchase. "Put an axe on" and
        // "put the Stone Axe on" are the same event asked about differently.
        $this->fireQuest($character, 'equip', 1, $item->item_key);
        $this->fireQuest($character, 'equip', 1, $def['slot'] ?? null);
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

        if ($job->kind === 'mining' || $job->kind === 'hunting') {
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
                'gather' => $this->previewGather($character, $character->col, $character->row),
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
            // §12 -- where every visible quest stands. In the state rather
            // than on the quests endpoint because it moves with almost every
            // action, while the catalog behind it never moves at all.
            'quests' => $this->questPayload($character),
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
                // §8.5 -- which action it applies to. The HUD has to say so, or
                // two charges on the same stat read as a contradiction.
                'scope' => $b->scope ?? 'global',
                'value' => $b->value,
            ], $this->armedBuffs($character)),
        ];
    }
}
