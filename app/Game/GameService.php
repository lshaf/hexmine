<?php

declare(strict_types=1);

namespace App\Game;

use App\Models\Carrier;
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
use App\Models\Guild;
use App\Models\GuildApplication;
use App\Models\GuildMember;
use App\Models\Player;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
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
    /**
     * Claiming a name, §7.
     *
     * Letters and digits only, and no two prospectors may hold the same one.
     * The narrowness is the point rather than prudishness: a name is drawn
     * beside other players' on a shared map, so anything that can be mistaken
     * for a different name -- a leading space, a zero-width joiner, two names
     * differing only by punctuation -- is a way to be somebody else.
     *
     * "Prospector" is not claimable. It is the LABEL every unnamed character is
     * drawn with (see the migration), so letting one player own it would make
     * every other unnamed prospector look like them.
     *
     * The unique index is the authority; this only gets to the refusal first so
     * the player is told which rule they broke instead of being handed a
     * constraint violation.
     */
    public function renameCharacter(Character $character, string $name): Character
    {
        $name = trim($name);

        if (preg_match('/^[A-Za-z0-9]+$/', $name) !== 1) {
            throw new GameException(
                'A name is letters and digits only — no spaces, punctuation or symbols.',
                'name_not_alphanumeric',
            );
        }

        if (mb_strlen($name) < Balance::CHARACTER_NAME_MIN || mb_strlen($name) > Balance::CHARACTER_NAME_MAX) {
            throw new GameException(
                'A name is between '.Balance::CHARACTER_NAME_MIN.' and '.Balance::CHARACTER_NAME_MAX.' characters.',
                'name_length',
            );
        }

        if (mb_strtolower($name) === 'prospector') {
            throw new GameException(
                'Prospector is what an unnamed character is called. Pick something of your own.',
                'name_reserved',
            );
        }

        if ($name === $character->name) {
            return $character;
        }

        // Case-insensitively, matching the collation the index is enforced
        // under -- so the answer here and the answer there are the same answer.
        $taken = Character::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('id', '!=', $character->id)
            ->exists();

        if ($taken) {
            throw new GameException('Somebody already goes by that name.', 'name_taken');
        }

        try {
            $character->name = $name;
            $character->save();
        } catch (UniqueConstraintViolationException) {
            // Two claims for one name in the same instant. The index settles it
            // and the loser is told the same thing they would have been told a
            // moment earlier.
            throw new GameException('Somebody already goes by that name.', 'name_taken');
        }

        return $character;
    }

    public function createCharacter(Player $player): Character
    {
        $now = $this->now();
        $spawn = $this->pickSpawn(crc32($player->wallet));

        return DB::transaction(function () use ($player, $spawn) {
            $character = Character::create([
                'player_id' => $player->id,
                // Unnamed. §7 -- the label is applied where it is read, so the
                // unique index has nothing to collide with (see the migration).
                'name' => null,
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

        // §9.5.3 -- the road is walked before it is arrived at. A pack met
        // halfway ends the journey where it stands, so interception is asked
        // first and arrival only gets what is left.
        $dirtyTravel = $this->interceptIfDue($character, $now);
        $dirtyTravel = $this->arriveIfDue($character, $now) || $dirtyTravel;

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

    /** Grant materials, honoring the §2 per-wallet cap. Returns units granted. */
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
            'id' => $i->id,
            'key' => $i->item_key,
            'durability' => $i->durability,
            'equipped' => $i->equipped,
            'options' => $i->options ?? [],
        ])->all();
    }

    /**
     * §9.5.6 -- one bill, aimed at the half of the kit that earned it.
     *
     * Two calls to wearShares rather than one, and whatever a half cannot
     * absorb spills to the other: a fighter with no gloves does not get a
     * discount, the rest of the kit pays it instead. That is the same rule the
     * per-piece pass already follows -- nothing goes missing from the
     * arithmetic just because a slot is empty.
     *
     * The two skill families stay in their own halves. `battleWear` spares a
     * share of the whole bill, because it is about taking a beating well;
     * `weaponWear` spares a share of the hands' portion, because it is about
     * what you are swinging.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $monster
     * @return array<int,int> item id -> durability lost
     */
    private function battleWear(
        array $items,
        array $monster,
        int $damageTaken,
        float $wearSpared,
        float $weaponSpared,
    ): array {
        $bill = (int) round(
            Formulas::battleWearBill($damageTaken) * (1 - max(0.0, $wearSpared)),
        );

        if ($bill <= 0) {
            return [];
        }

        $split = Formulas::battleWearSplit($monster);

        $hands = (int) round($bill * $split['hands'] * (1 - max(0.0, $weaponSpared)));
        $worn = $bill - $hands;

        $handsShare = $this->wearShares($items, $hands, ['weapon', 'gloves']);
        $worn += $hands - array_sum($handsShare);

        $wornShare = $this->wearShares($items, $worn, ['armor', 'boots']);
        $spill = $worn - array_sum($wornShare);

        // Anything the worn half could not take goes back to the hands, which
        // is the only place left for it -- a fighter with no gloves does not
        // get a discount (§9.5.6), and neither does one wearing nothing at all.
        //
        // What the hands ALREADY took is passed back in, because this is a
        // second look at the same pieces: without it the spill re-reads the
        // durability the first pass had spent and can charge a piece more than
        // it has. Unreachable while damage cannot exceed the pool -- the bill
        // is a quarter of it, so both halves can never be empty at once -- but
        // the figure a receipt prints must be true whatever the tuning does.
        if ($spill > 0) {
            foreach ($this->wearShares($items, $spill, ['weapon', 'gloves'], $handsShare) as $id => $extra) {
                $handsShare[$id] = ($handsShare[$id] ?? 0) + $extra;
            }
        }

        return $handsShare + $wornShare;
    }

    /**
     * §9.5.6 -- the wear bill, for a kit nobody owns.
     *
     * The one seam the battle bench needs (BattleSimController): it runs the
     * real exchange off Formulas and then has to charge the real bill, and
     * battleWear() is private because nothing in the game had any business
     * calling it from outside. A bench that reimplemented the split would be a
     * second opinion about the largest sink in the game (§11.1).
     *
     * Takes rows rather than a character precisely because there is no
     * character: it reads nothing, writes nothing and is a pure function of
     * what it is handed.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $monster
     * @return array<int,int> item id -> durability lost
     */
    public function simulateWear(
        array $items,
        array $monster,
        int $damageTaken,
        float $wearSpared = 0.0,
        float $weaponSpared = 0.0,
    ): array {
        return $this->battleWear($items, $monster, $damageTaken, $wearSpared, $weaponSpared);
    }

    /**
     * §9.5.6 -- how a beating is spread across the kit that took it.
     *
     * By how much each piece was built to soak rather than by how much is left
     * of it, so a big coat takes the brunt and a nearly-broken piece is found
     * out rather than quietly protected. What a piece cannot absorb spills to
     * the others, which is what lets a fight empty the pool without any of the
     * arithmetic going missing.
     *
     * §9.5.6 -- `$slots` aims the bill at half the kit. The split sends the
     * greater share to whichever half the fight actually happened in, and each
     * half is then spread by what its pieces were built to soak.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @param  list<string>|null  $slots
     * @param  array<int,int>  $already  durability these pieces have lost already
     * @return array<int,int> item id -> durability lost
     */
    private function wearShares(array $items, int $damage, ?array $slots = null, array $already = []): array
    {
        $allowed = $slots ?? Balance::COMBAT_SLOTS;

        $combat = array_values(array_filter($items, static function (array $item) use ($allowed): bool {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                return false;
            }

            $def = Catalog::item($item['key']);

            return $def !== null && in_array($def['slot'] ?? '', $allowed, true);
        }));

        if ($combat === [] || $damage <= 0) {
            return [];
        }

        $weights = [];
        foreach ($combat as $item) {
            $weights[$item['id']] = max(1, (int) (Catalog::item($item['key'])['maxDurability'] ?? 1));
        }

        $out = [];
        $left = $damage;

        // Two passes: the share each piece was built for, then whatever could
        // not land because a piece ran out before the arithmetic did.
        while ($left > 0 && $weights !== []) {
            $total = array_sum($weights);
            $spent = 0;

            foreach ($weights as $id => $weight) {
                $room = 0;
                foreach ($combat as $item) {
                    if ($item['id'] === $id) {
                        $room = (int) $item['durability'] - ($out[$id] ?? 0) - ($already[$id] ?? 0);
                        break;
                    }
                }

                $want = max(1, (int) round($left * $weight / $total));
                $take = min($want, $room, $left - $spent);

                if ($take <= 0) {
                    unset($weights[$id]);

                    continue;
                }

                $out[$id] = ($out[$id] ?? 0) + $take;
                $spent += $take;

                if ($spent >= $left) {
                    break;
                }
            }

            if ($spent === 0) {
                break;
            }

            $left -= $spent;
        }

        return $out;
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
    private function rollFor(Character $character, array $def, int $extra = 0, float $upgrade = 0.0): array
    {
        $seed = Hash::hash2(
            (int) $character->id + $this->now() % 100000,
            crc32($def['name']),
            Balance::mapSeed(),
        );

        return Formulas::rollOptions($def, $seed, $extra, $upgrade);
    }

    /**
     * §8.0.1 -- turn a chance into a count of extra option rolls.
     *
     * Server-rolled from a seed like every other outcome. A Smith's tree node
     * widens the ceiling by one; it does not guarantee the slot fills, because
     * the count itself is a roll (§8.0.1).
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
     * Aggregated equipment bonuses.
     *
     * `$action` is what is being costed, and it is one of the seven §8.5 names:
     * a gathering line, `travel`, or `processing`. §8 gathering tools only count
     * for their own line, so a mine must say which one it is, and a read with no
     * action in mind (the hero sheet) gets only the gear that works everywhere.
     *
     * `$line` is the *material* line underneath that action, and it exists
     * because §6 processing has both: sawing planks is the action `processing`
     * on the line `woodcutting`. Gear and potions are scoped by the action, a
     * line-locked tree node by the line. For a gathering mine the two are the
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
        // same stat are the same effect twice: the better draft wins and the
        // other is simply not felt. Summing would let a `global` charge and a
        // line-scoped one quietly double up on one mine, which is the stack the
        // rung ladder exists to prevent -- twelve potions a rung would otherwise
        // be a way of buying the ceiling in installments.
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
        // keeps a Sawyer's speed off a woodcutting mine: both are `woodcutting`
        // work and only one of them happens at a saw pit.
        $nodeLine = $line === null ? $action : $action.':'.$line;
        if ($nodeLine !== null) {
            foreach ($effects['byLine'][$nodeLine] ?? [] as $stat => $value) {
                $tree[$stat] = ($tree[$stat] ?? 0) + $value;
            }
        }

        $out = [];
        foreach (['yield', 'tripReduction', 'travelSpeed', 'processingSpeed', 'power', 'defense'] as $stat) {
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
     * to sweep, because a draft is ended by being used rather than by running
     * out. The unique index on (character, stat, scope) caps how many can be
     * held at once.
     *
     * @return array<int,CharacterBuff>
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
     * draft that applies everywhere applied here.
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
     * Drink one. §8.5 -- this arms the action the draft names; it does not
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

            // §8.5 -- one charge per stat PER ACTION. A woodcutting draft and
            // a mining draft are different things and both may be held; two
            // drafts on the same stat and the same action are the same thing
            // twice, and the better one wins.
            $scope = $def['scope'] ?? 'global';

            $armed = $character->buffs()
                ->where('stat', $def['stat'])
                ->where('scope', $scope)
                ->first();

            // Refused before the flask is opened, never after. A weaker draft
            // poured on top of a stronger one would be paid for and never felt,
            // and an idle game must not take something away for nothing -- so
            // this reads as "you already have better", not as a downgrade.
            if ($armed !== null && (float) $armed->value >= (float) $def['value']) {
                throw new GameException(
                    $armed->item_key === $key
                        ? "A {$def['name']} is already waiting on the same work. A second would not make it any stronger."
                        : sprintf(
                            '%s is already waiting on the same work, and it is the stronger of the two. Keep the %s for later.',
                            Catalog::item($armed->item_key)['name'] ?? 'A stronger draft',
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
    /**
     * §7.3 -- what the tool on this line takes out of a hex each second.
     *
     * The best equipped one, not the sum: you swing one axe. A broken tool is
     * no tool, exactly as it is for §8's reach.
     */
    public function lineToolAttack(Character $character, string $line): int
    {
        $slot = Catalog::slotForSkill($line);
        $best = 0;

        foreach ($character->items as $item) {
            if (! $item->equipped || $item->durability <= 0) {
                continue;
            }

            $def = Catalog::item($item->item_key);
            if (($def['slot'] ?? null) !== $slot) {
                continue;
            }

            // §8.0.1 -- a flat rolled line on a tool is more mining attack, and
            // it is only ever mining attack.
            $best = max(
                $best,
                Formulas::toolAttack($def) + Formulas::flatOption($item->options ?? [], 'attack'),
            );
        }

        return $best;
    }

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
     * What each line's equipped tool is worth on its own mines, §8. The hero
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
     * is the one behavior worth paying for here and the only one whose reward
     * cannot be bought.
     */
    public function sightRadius(Character $character): int
    {
        if ($this->isTraveling($character)) {
            return Balance::SIGHT_TRAVELING;
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
     * mine regardless.
     *
     * §8.2 -- AT ZERO THE THING IS GONE, on a mine exactly as in a fight. It
     * used to go inactive and wait for a repair, which made repair optional: an
     * item at zero cost nothing to leave at zero, so the sink only ever
     * collected from players who wanted their gear back. Destruction moves the
     * whole bill forward -- you repair to KEEP the thing, not to un-break it --
     * and that is what makes it the largest sink in the game (§11.1).
     *
     * Destroyed items are named through `$destroyed`, because nothing may be
     * taken quietly. The mine preview says it first (§9.5.5's rule, applied to
     * a hex): an hour of work that ends in a lost axe must be a decision the
     * player made, not one they discovered.
     *
     * @param  list<string>  $destroyed  filled with the names of anything that ran out
     */
    private function drainDurability(
        Character $character,
        int $amount,
        ?string $line = null,
        array &$destroyed = [],
    ): int {
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

            $left = max(0, (int) $item->durability - $amount);
            $drained += min($amount, (int) $item->durability);

            if ($left <= 0) {
                $destroyed[] = $def['name'];
                $item->delete();

                continue;
            }

            $item->durability = $left;
            $item->save();
        }

        return $drained;
    }

    /**
     * §7.4.3 -- what this mine takes off the line's tool, after the tree.
     *
     * A gathering node spares the whole of one mine's wear or none of it,
     * rolled from the job rather than the clock: DRAIN_PER_MINE is one point,
     * and a fraction of one point is nothing a player could ever read off the
     * item. Seeded like every other outcome (§16), so collecting twice cannot
     * roll twice.
     */
    private function tripDrain(Character $character, GameJob $job, string $line): int
    {
        $spare = (float) $this->jobEffects($character, $line)['toolWear'];
        if ($spare <= 0) {
            return Balance::DRAIN_PER_MINE;
        }

        $roll = Hash::rand01(Hash::hash2(
            (int) $job->id,
            (int) $job->started_at,
            Balance::mapSeed() ^ 0x7001,
        ));

        return $roll < $spare ? 0 : Balance::DRAIN_PER_MINE;
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
     * quest) is the last line of defense against a doubled request, exactly as
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
     * One mine at a time.
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
        //
        // So does a fight (§9.5.5). It is the sharpest case of the same rule:
        // you are swinging at something, and you are not also down a mine or
        // walking away from it.
        return $character->jobs()->whereIn('kind', ['mining', 'hunting', 'battle'])->first();
    }

    public function buildTile(int $col, int $row, int $now): array
    {
        // §5.1 -- the seed says how many hauls this hex holds; the cache says how
        // many are gone. Folding both in here means no reader downstream has to
        // remember to ask, exactly as with a cleared pack below.
        $worked = Tiles::state($col, $row);
        $tile = WorldGen::generateTile($col, $row, $now, [
            'regrowsAt' => $worked['regrowsAt'],
            'taken' => $worked['taken'],
        ]);

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
            'hpMin' => Balance::TILE_HP_MIN,
            'hpMax' => Balance::TILE_HP_MAX,
            // §5.3 -- the grade ladder the roll is scaled by, sent for the same
            // reason every other generation constant is: the algorithm is
            // mirrored, the numbers are not.
            'hpGradeAttack' => Balance::TILE_HP_GRADE_ATTACK,
            'commonAttack' => Balance::MINING_COMMON_ATTACK,
            // §5.1 -- the haul band and how many hauls a hex holds across it.
            // The client derives the count so a card can say "three of eight"
            // without asking; how many are GONE is the server's to send.
            'yieldMin' => Balance::TILE_YIELD_MIN,
            'yieldMax' => Balance::TILE_YIELD_MAX,
            'extractionsMin' => Balance::TILE_EXTRACTIONS_MIN,
            'extractionsMax' => Balance::TILE_EXTRACTIONS_MAX,
            'rareSpawnChance' => Balance::RARE_SPAWN_CHANCE,
            'slotsPerTile' => Balance::SLOTS_PER_TILE,
            'herdLifetimeMs' => Balance::scaled(Balance::HERD_LIFETIME_MS),
            'herdChance' => Balance::HERD_CHANCE,
            // §9.5.1 -- sent rather than compiled in, like every other
            // generation constant: the algorithm is mirrored, the numbers are
            // not, so tuning the rings cannot silently desync the two.
            'packLifetimeMs' => Balance::scaled(Balance::PACK_LIFETIME_MS),
            // Zeroed rather than omitted when the roads are quiet, so the
            // client's mirror of packAt() agrees with the server's instead of
            // drawing packs nobody can meet.
            'packChance' => Balance::packsEnabled()
                ? Balance::PACK_CHANCE
                : array_map(static fn () => 0.0, Balance::PACK_CHANCE),
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
        $inSight = fn (int $col, int $row): bool => HexGeometry::distance($centerCol, $centerRow, $col, $row) <= $range;

        // §5.1 -- one MGET over the sight disc, where this used to be an index
        // scan over a `tile_states` box. Thirty-seven hexes at the very most,
        // because sight caps at three (§5.6).
        $disc = [];
        for ($col = $minCol; $col <= $maxCol; $col++) {
            for ($row = $minRow; $row <= $maxRow; $row++) {
                if ($inSight($col, $row)) {
                    $disc[] = [$col, $row];
                }
            }
        }

        $depleted = [];
        foreach (Tiles::statesAmong($disc) as $at => $state) {
            if ($state['regrowsAt'] <= $now) {
                continue;
            }
            [$col, $row] = array_map('intval', explode(',', $at));
            $depleted[] = [$col, $row, $state['regrowsAt']];
        }

        $occupied = GameJob::where('status', 'active')
            ->whereNotNull('col')
            ->whereBetween('col', [$minCol, $maxCol])
            ->whereBetween('row', [$minRow, $maxRow])
            // Backticked because `row` is a RESERVED WORD in MySQL 8 and is not
            // one in MariaDB or SQLite, so this raw select parses on two of the
            // three engines and is a syntax error on the one the game runs on.
            ->selectRaw('`col`, `row`, COUNT(*) as total')
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
            // §9.5.7 -- other people's corpses, and only inside sight like
            // everything else here. Your own ride the player state instead:
            // they are yours and the fog does not apply to them.
            'carriers' => $this->carriersInSight($character),
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
        // On the road there is no hex under your feet yet. A traveler is
        // stopped by a pack when they arrive (§9.5.6), not while they walk.
        if ($this->isTraveling($character)) {
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

            return [
                'job' => $job,
                'family' => $family,
                'level' => (int) ($this->jobLevels($character)[$job] ?? 0),
            ];
        }

        return ['job' => null, 'family' => null, 'level' => 0];
    }

    /**
     * §7.4 -- what this character's battle tree is worth with THIS in hand.
     *
     * Solid numbers and a share of the bill, both locked to the weapon family
     * (§9.5.4). An empty slot is worth nothing at all: there is no family, so
     * there is no tree paying out.
     *
     * @return array{attack:int,defense:int,wear:float,weaponWear:float,gold:float,loot:float}
     */
    private function battleTree(Character $character, ?string $family): array
    {
        $zero = [
            'attack' => 0, 'defense' => 0, 'wear' => 0.0, 'weaponWear' => 0.0,
            'gold' => 0.0, 'loot' => 0.0,
            'skillPower' => 0.0, 'skillCooldown' => 0, 'skillStun' => 0,
        ];
        if ($family === null) {
            return $zero;
        }

        $effects = $this->nodeEffects($character);
        $bucket = 'battle:'.$family;
        $job = Catalog::BATTLE_JOB_FOR_FAMILY[$family] ?? null;
        $byJob = $job === null ? [] : ($effects['byJob'][$job] ?? []);

        return [
            'attack' => (int) ($effects['pair'][$bucket]['attack'] ?? 0),
            'defense' => (int) ($effects['pair'][$bucket]['defense'] ?? 0),
            // §9.5.6 -- two streams, so two nodes. What hit you comes off the
            // armor and what you hit comes off the blade, and a tree that
            // spared both with one number would be answering two questions at
            // once.
            'wear' => (float) ($effects['battleWear'][$bucket] ?? 0.0),
            'weaponWear' => (float) ($byJob['weaponWear'] ?? 0.0),
            'gold' => (float) ($byJob['goldFind'] ?? 0.0),
            'loot' => (float) ($byJob['lootOption'] ?? 0.0),
            // §9.5.9 -- what the tree does to the three skills the family
            // carries. Clamped in BattleSkills::armed(), not here, so the
            // preview and the exchange cannot read different numbers.
            'skillPower' => (float) ($byJob['skillPower'] ?? 0.0),
            'skillCooldown' => (int) ($byJob['skillCooldown'] ?? 0),
            'skillStun' => (int) ($byJob['skillStun'] ?? 0),
        ];
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
        // §9.5.7 -- a corpse answers before a pack, the same way it is fought.
        $carrier = $this->carrierHere($character);
        $pin = $carrier === null ? $this->packHere($character) : null;

        if ($carrier === null && $pin === null) {
            return ['canFight' => false, 'reason' => 'Nothing is standing here.'];
        }

        $key = $carrier?->monster_key ?? $pin['key'];
        $monster = Monsters::ROSTER[$key];
        $items = $this->itemRows($character);
        $job = $this->battleJobLevel($character);

        // §8.5 -- a battle draft is armed for exactly this and nothing else.
        // §7.4 -- the tree is scoped by the family in the slot. A Swordhand's
        // nodes are worth nothing with a shield on the arm.
        $bonuses = $this->bonuses($character, 'battle', $job['family']);
        $tree = $this->battleTree($character, $job['family']);
        $pair = Formulas::combatPair(
            $items,
            $job['level'],
            $bonuses['power'],
            $bonuses['defense'],
            $tree['attack'],
            $tree['defense'],
        );

        // §9.5.5 -- the exchange with the swing taken out. The plate is a
        // promise: this is what the arithmetic says, and the fight then wanders
        // by BATTLE_SWING either way.
        $pool = Formulas::battlePool($items);

        // §9.5.9 -- the skills are in the arithmetic even though the hex card
        // says nothing about them. They belong to the exchange rather than to
        // the ground you are standing on, so the preview COSTS them and the
        // battle plate is where they are actually read.
        $expected = Formulas::expectedBattle(
            $pair['attack'],
            $pair['defense'],
            $pool,
            $monster,
            BattleSkills::armed($job['family'], [
                'power' => $tree['skillPower'],
                'cooldown' => $tree['skillCooldown'],
                'stun' => $tree['skillStun'],
            ]),
        );

        // §9.5.6 -- the same one bill the fight will charge, so the preview and
        // the receipt cannot disagree about what this is going to cost.
        $share = $this->battleWear(
            $items,
            $monster,
            $expected['damageTaken'],
            $tree['wear'],
            $tree['weaponWear'],
        );

        $warnings = [];
        $wear = ['pool' => $pool, 'taken' => array_sum($share), 'weapon' => 0];

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }

            $def = Catalog::item($item['key']);
            $slot = $def['slot'] ?? null;
            if (! in_array($slot, Balance::COMBAT_SLOTS, true)) {
                continue;
            }

            $cost = $share[$item['id']] ?? 0;

            if ($slot === 'weapon') {
                $wear['weapon'] = $cost;
            }

            // The warning is built on what the arithmetic says plus the swing
            // against you: the fight is not run yet, and "it might survive" is
            // not a thing to tell somebody about to lose a legendary.
            if ($cost * (1 + Balance::BATTLE_SWING) >= $item['durability']) {
                $warnings[] = $def['name'].' will not survive this.';
            }
        }

        // §9.5.7 -- losing IS dying, so this is not a condition to check, it is
        // the terms of the fight. Said out loud every time, because the odds
        // beside it are only half the decision: what a loss costs is the other
        // half, and an idle game may never take something expensive by
        // surprise (§8.2).
        //
        // Both costs, in one line. It was three lines of a sentence, and being
        // unconditional it was on the plate at every pack a player ever met --
        // which is how a colour reserved for alarm (§13.3) stopped reading as
        // one. What is left in ember is the conditional warning above: THIS
        // fight will finish THAT piece of gear.
        $warnings[] = 'Lose and you die — one bag row, and the walk back.';

        // §9.5.5 -- a fight takes time, so there is a state in which one is
        // already under way. Named rather than silently refused: the dock is
        // showing the same hex it was, and "nothing happens when I tap" is the
        // worst possible answer.
        $working = $this->miningTrip($character);
        $reason = match (true) {
            $working !== null && $working->kind === 'battle' => $working->isReady($this->now())
                ? 'The fight is over. Claim it.'
                : 'You are already in this fight.',
            $working !== null => 'You are working this hex. Finish that first.',
            default => null,
        };

        return [
            'canFight' => $reason === null,
            'reason' => $reason,
            // §9.5.5 -- how long the exchange takes to WATCH, off the same
            // arithmetic the plate is showing. Not a cooldown: the fight is
            // settled the moment you close.
            'seconds' => Formulas::battleDurationMs($expected['rounds']) / 1000,
            'until' => $carrier?->expires_at ?? $pin['until'],
            // §9.5.7 -- whose it is, what it holds, and what happens to that if
            // you are not its owner. All three before a single tap.
            'corpse' => $carrier === null ? null : [
                'mine' => (int) $carrier->owner_character_id === (int) $character->id,
                'label' => $carrier->label,
                'owner' => $carrier->owner?->name ?? 'Someone',
            ],
            'monster' => [
                'key' => $key,
                'name' => $monster['name'],
                'tier' => $monster['tier'],
                'profile' => $monster['profile'],
                'attack' => $monster['attack'],
                'defense' => $monster['defense'],
                'hp' => $monster['hp'],
                'description' => $monster['description'],
            ],
            'attack' => $pair['attack'],
            'defense' => $pair['defense'],
            // §9.5.5 -- what the exchange says, before the swing.
            'pool' => $pool,
            'expected' => $expected,
            'odds' => round(Formulas::battleOdds($pair['attack'], $pair['defense'], $monster), 3),
            'job' => $job['job'],
            'jobLevel' => $job['level'],
            'wear' => $wear,
            'warnings' => $warnings,
        ];
    }

    /**
     * §9.5.5 -- the fight, in one action.
     *
     * Everything that decides it was already on the preview, so this rolls
     * rather than asks. What it does that the preview cannot is spend: the
     * charge, the durability, and the pack itself.
     *
     * THE PACK IS CLEARED EITHER WAY (§9.5.1). That is the whole anti-farm
     * argument and it needs no cooldown -- you cannot re-roll a pack, because
     * after the roll there is no pack. It also makes a loss a legitimate way
     * out of the pin (§9.5.3), which is what keeps a character who wandered in
     * over their head from being parked on a hex.
     *
     * @return array<string,mixed>
     */
    /**
     * §9.5.5 -- swing at whatever is standing here. The fight takes time.
     *
     * It used to be instant, which made a pack a button rather than an
     * engagement: tap, read the plate, walk on. A clock is what turns it into
     * something you are DOING -- you are held on that hex while it runs, the
     * same way a mine holds you, and the pack's own two-hour clock (§9.5.3) is
     * running underneath it.
     *
     * WHAT IS DECIDED HERE AND WHAT IS NOT. The roll happens now and is stored,
     * exactly as a mine stores the material its tool could reach: the kit that
     * took the fight is the kit that fought it, and swapping to a better sword
     * while the timer runs must buy nothing. What is spent now is the pack --
     * you are engaged with it, so nobody else gets it -- and the charge, whose
     * numbers the roll above already carries. Everything the fight COSTS, and
     * everything it PAYS, lands when it is collected.
     */
    public function startBattle(Character $character): GameJob
    {
        return DB::transaction(function () use ($character) {
            $now = $this->now();
            $col = (int) $character->col;
            $row = (int) $character->row;

            $working = $this->miningTrip($character);
            if ($working !== null) {
                throw new GameException(
                    $working->kind === 'battle'
                        ? 'You are already in a fight.'
                        : 'You are working this hex. Finish that first.',
                    'working',
                );
            }

            // §9.5.7 -- a corpse is answered first. It is the thing you walked
            // here for, it runs on its own 24h clock rather than the bucket's,
            // and a pack standing on the same hex is still there afterwards.
            $carrier = $this->carrierHere($character);
            $pin = $carrier === null ? $this->packHere($character) : null;

            if ($carrier === null && $pin === null) {
                throw new GameException('Nothing is standing here.', 'no_pack');
            }

            $mine = $carrier !== null
                && (int) $carrier->owner_character_id === (int) $character->id;

            // §7.6 -- the strap is asked for BEFORE the fight, never after. A
            // recovery with nowhere to land would take the row a second time,
            // and the way out is always in reach from where you are standing.
            if ($mine) {
                $this->requireRoomForLoot($character, $carrier->loot);
            }

            $key = $carrier?->monster_key ?? $pin['key'];
            $monster = Monsters::ROSTER[$key];

            $job = $this->battleJobLevel($character);
            $bonuses = $this->bonuses($character, 'battle', $job['family']);
            $tree = $this->battleTree($character, $job['family']);
            $items = $this->itemRows($character);
            $pair = Formulas::combatPair(
                $items,
                $job['level'],
                $bonuses['power'],
                $bonuses['defense'],
                $tree['attack'],
                $tree['defense'],
            );

            $odds = Formulas::battleOdds($pair['attack'], $pair['defense'], $monster);

            // Seeded server-side (§16). A pack's seed is fixed to its hex and
            // bucket, which costs nothing because it is cleared below and the
            // roll is unreachable a second time. A CORPSE is not cleared by a
            // loss, so its seed folds in the clock: without that, losing to it
            // once would mean losing to it forever, and the debt would be a
            // wall rather than a walk. In MILLISECONDS for that same reason --
            // rounded to the second, a run of quick attempts shares one seed
            // and repeats one answer, which is the same wall in a new coat.
            $seed = $carrier !== null
                ? Hash::hash2(
                    $col * 71 + (int) $carrier->id,
                    $row * 53 + $now,
                    Balance::mapSeed() ^ 0x6A77,
                )
                : Hash::hash2(
                    $col * 61 + $pin['bucket'],
                    $row * 43 + (int) $character->id,
                    Balance::mapSeed() ^ 0x6A77,
                );

            // §9.5.5 -- the exchange, run to a conclusion here rather than on
            // collection: the kit that took the fight is the kit that fought
            // it, and swapping to a better sword while the clock runs buys
            // nothing.
            $pool = Formulas::battlePool($items);

            // §9.5.9 -- the family in the slot decides which three, and the
            // tree decides how good they are. Read HERE with everything else
            // about the kit, because the fight is settled the instant you
            // close: swapping weapons while the clock runs buys nothing.
            $armedSkills = BattleSkills::armed($job['family'], [
                'power' => $tree['skillPower'],
                'cooldown' => $tree['skillCooldown'],
                'stun' => $tree['skillStun'],
            ]);

            $fight = Formulas::resolveBattle(
                $pair['attack'],
                $pair['defense'],
                $pool,
                $monster,
                $seed,
                $armedSkills,
            );
            $won = $fight['won'];

            if ($pin !== null) {
                // Cleared on ENGAGEMENT rather than on resolution (§9.5.1):
                // after the roll there is no pack, and while you are swinging
                // at it there is no pack for anybody else either.
                Packs::clear($col, $row, $pin['bucket'], (int) $pin['until'], $now);
            }

            // §8.5 -- a battle draft was armed for exactly this, and the roll
            // above already carries it.
            $this->spendBuffs($character, 'battle');

            // §9.5.5 -- the clock is the exchange itself, drawn at one round a
            // beat, rather than a flat cooldown by tier. A rout is over in two
            // seconds and a grind takes ten, which is the whole reason to watch
            // one. Real milliseconds: an animation is not a game hour, so it
            // does not go through scaled() like every other clock.
            $durationMs = Formulas::battleDurationMs($fight['rounds']);

            return GameJob::create([
                'character_id' => $character->id,
                'kind' => 'battle',
                'status' => 'active',
                'col' => $col,
                'row' => $row,
                // No tile slot: a fight is not one of the hex's two seats.
                'slot' => null,
                'quantity' => 1,
                // NOT NULL on the table, and not what the XP is paid against --
                // that is on the payload, where a bare-handed fight can say
                // honestly that it teaches nobody.
                'skill_key' => $job['job'] ?? 'swordhand',
                'payload' => [
                    'monster' => $key,
                    'won' => $won,
                    'odds' => round($odds, 3),
                    'rounds' => $fight['rounds'],
                    'damageTaken' => $fight['damageTaken'],
                    'damageDealt' => $fight['damageDealt'],
                    'pool' => $pool,
                    'left' => $fight['left'],
                    // §7.4 -- stored with the roll for the same reason the roll
                    // is: the tree that took the fight is the tree that pays.
                    'wearSpared' => $tree['wear'],
                    'weaponSpared' => $tree['weaponWear'],
                    'goldFind' => $tree['gold'],
                    'lootOption' => $tree['loot'],
                    // §9.5.5 -- what the screen draws. The fight is over; this
                    // is the replay, and it rides the job so closing the tab
                    // costs the animation and never the result.
                    'log' => $fight['log'],
                    // §9.5.9 -- the three that took the fight, as they were
                    // armed when it started. Stored with the roll for the same
                    // reason the roll is: the replay has to draw the cooldowns
                    // the exchange actually ran on, not the ones the character
                    // happens to have when they open the tab.
                    'skills' => array_map(static fn (array $skill): array => [
                        'key' => $skill['key'],
                        'name' => $skill['name'],
                        'glyph' => $skill['glyph'],
                        'cooldown' => $skill['cooldown'],
                        'description' => $skill['description'],
                        ...BattleSkills::summary($skill),
                    ], $armedSkills),
                    'monsterHp' => (int) $monster['hp'],
                    'attack' => $pair['attack'],
                    'defense' => $pair['defense'],
                    'job' => $job['job'],
                    'seed' => $seed,
                    'carrier' => $carrier?->id,
                    'mine' => $mine,
                ],
                'started_at' => $now,
                'ends_at' => $now + $durationMs,
            ]);
        });
    }

    /**
     * §9.5.5 -- the fight lands.
     *
     * Everything here was decided when it started; what happens now is the
     * spending. Wear falls on the kit that is on your back when it is over,
     * the same way a mine wears the tool it is collected with.
     *
     * @return array<string,mixed>
     */
    private function finishBattle(Character $character, GameJob $job): array
    {
        $now = $this->now();
        $payload = $job->payload ?? [];
        $key = (string) ($payload['monster'] ?? '');
        $monster = Monsters::ROSTER[$key] ?? null;

        if ($monster === null) {
            $job->delete();

            throw new GameException('Whatever that was, it is not in the roster any more.', 'not_found');
        }

        $won = (bool) ($payload['won'] ?? false);
        $seed = (int) ($payload['seed'] ?? 0);
        $col = (int) $job->col;
        $row = (int) $job->row;

        // §9.5.6 -- the beating, spent. The pool that held you up IS the gear,
        // so what the fight took off the pool comes off the pieces -- capped,
        // because an uncapped exchange in the center would strip a legendary
        // set in one go and §8.2's warning would be the only thing between a
        // player and a week of work.
        $wear = [];
        $rounds = (int) ($payload['rounds'] ?? 1);
        $pool = (int) ($payload['pool'] ?? 0);

        $share = $this->battleWear(
            $this->itemRows($character),
            $monster,
            (int) ($payload['damageTaken'] ?? 0),
            (float) ($payload['wearSpared'] ?? 0.0),
            (float) ($payload['weaponSpared'] ?? 0.0),
        );

        $byId = [];
        foreach ($character->items as $item) {
            $byId[$item->id] = $item;
        }

        foreach ($share as $id => $amount) {
            if (! isset($byId[$id]) || $amount <= 0) {
                continue;
            }

            $wear[] = $this->wearInFight($byId[$id], $amount);
        }

        // §9.5.8 -- gold needs no bag row, which is what makes it the right
        // thing to pay for a fight that was not your idea. A loss pays nothing
        // at all (§9.5.3): losing is an exit, not a strategy.
        $gold = 0;
        $xp = 0;
        $spoils = [];
        $lost = 0;
        $looted = null;
        $leftBehind = null;

        if ($won) {
            $gold = (int) round(Hash::randInt(
                Hash::hash2($col, $row, $seed ^ 0x901D),
                (int) $monster['gold'][0],
                (int) $monster['gold'][1],
            ) * (1 + (float) ($payload['goldFind'] ?? 0.0)));
            $character->gold += $gold;

            if (($payload['job'] ?? null) !== null) {
                $xp = Balance::JOB_XP_PER_BATTLE_TIER * (int) $monster['tier'];
                $this->grantJobXp($character, (string) $payload['job'], $xp);
            }

            // §9.5.8 -- what came off it. Unlike gold these need straps, so a
            // full bag loses the surplus rather than refusing: the fight was
            // often not your idea (§9.5.3), and a refusal at this point would
            // be a pin with no way out.
            foreach (Drops::battleSpoils($monster, $seed) as $material => $quantity) {
                $granted = $this->addMaterial($character, $material, $quantity);
                if ($granted > 0) {
                    $spoils[$material] = $granted;
                }
                $lost += $quantity - $granted;
            }

            $looted = $this->takeLootedGear(
                $character,
                $monster,
                $seed,
                $leftBehind,
                $this->extraRoll($character, (float) ($payload['lootOption'] ?? 0.0), 0x100E),
            );
        }

        // §9.5.7 -- A LOSS IS A DEATH. Not "a loss with nothing to absorb it":
        // losing the fight is losing, and what follows is the same whatever was
        // on your back. Armor still decides whether you lose -- defense feeds
        // the hold, and the hold is half the margin -- it just no longer
        // decides what losing costs.
        $died = ! $won;

        $recovered = null;
        $burned = null;
        $corpse = null;

        if (($payload['carrier'] ?? null) !== null) {
            $carrier = Carrier::find($payload['carrier']);
            $mine = (bool) ($payload['mine'] ?? false);

            $corpse = [
                'mine' => $mine,
                'label' => $carrier?->label ?? '',
                'owner' => $carrier?->owner?->name,
            ];

            if ($won && $carrier !== null) {
                if ($mine) {
                    // The row comes home, on top of the ordinary drops.
                    $recovered = $this->restoreLoot($character, $carrier->loot);
                } else {
                    // §2 -- an item another wallet can pick up is a direct
                    // player-to-player transfer, and "random row" is no defense
                    // at all: empty the bag to the one thing worth moving, fight
                    // naked, die on purpose, and a partner walks over and
                    // collects it. So the row BURNS unless its owner is the one
                    // standing over it. Rivals can still race you for the
                    // recovery, which is the sharper kind of interesting anyway.
                    $burned = $carrier->label;
                }

                $carrier->delete();
            }
        }

        $stolen = null;
        $woke = null;

        if ($died) {
            $stolen = $this->takeRowForCarrier($character, $key, $seed, $now);

            // The walk back is the first bill, and at ten minutes a hex it is
            // a real one.
            $woke = $this->wakeAtNearestSettlement($character);
        }

        $character->save();
        $job->delete();

        return [
            'won' => $won,
            'odds' => (float) ($payload['odds'] ?? 0),
            'monster' => [
                'key' => $key,
                'name' => $monster['name'],
                'tier' => $monster['tier'],
                'profile' => $monster['profile'],
                'attack' => $monster['attack'],
                'defense' => $monster['defense'],
                'hp' => $monster['hp'],
            ],
            'attack' => (int) ($payload['attack'] ?? 0),
            'defense' => (int) ($payload['defense'] ?? 0),
            // §9.5.5 -- the exchange, as it actually went. There is no health
            // bar to watch, so the receipt is where the fight is read.
            'rounds' => $rounds,
            'pool' => $pool,
            'damageTaken' => (int) ($payload['damageTaken'] ?? 0),
            'damageDealt' => (int) ($payload['damageDealt'] ?? 0),
            'gold' => $gold,
            'job' => $payload['job'] ?? null,
            'jobXp' => $xp,
            'wear' => $wear,
            'spoils' => $spoils,
            'spoilsLost' => $lost,
            'looted' => $looted,
            'leftBehind' => $leftBehind,
            'destroyed' => array_values(array_map(
                static fn (array $w) => $w['name'],
                array_filter($wear, static fn (array $w) => $w['destroyed']),
            )),
            'corpse' => $corpse,
            'recovered' => $recovered,
            'burned' => $burned,
            'died' => $died,
            'stolen' => $stolen,
            'wokeAt' => $woke,
        ];
    }

    /**
     * §9.5.7 -- the corpse standing on this hex, if one is.
     *
     * Your own first: two can stand on one hex, and the one you walked here for
     * is never the stranger's.
     *
     * A carrier does NOT pin (§9.5.3). A pack owns the ground for two hours,
     * which is a hazard; a corpse stands for twenty-four, and a hex locked for
     * a day would be the griefing the settlement rule exists to forbid. It is a
     * hook, not a fence -- something you kit up for and come back to.
     */
    public function carrierHere(Character $character): ?Carrier
    {
        if ($this->isTraveling($character)) {
            return null;
        }

        return Carrier::where('col', (int) $character->col)
            ->where('row', (int) $character->row)
            ->where('expires_at', '>', $this->now())
            ->orderByRaw('CASE WHEN owner_character_id = ? THEN 0 ELSE 1 END', [$character->id])
            ->orderBy('expires_at')
            ->first();
    }

    /**
     * §9.5.8 -- the kit the monster was using, at 5-50% of its life.
     *
     * Never past rare, whatever the tier, and that ceiling is a §2 rule rather
     * than a tuning value: epic is where gear becomes mintable (§8.0), so a
     * monster that dropped one would be exactly the grind->NFT faucet the
     * threat model exists to close. A harder pack answers with better OPTION
     * ROLLS instead -- the same mechanism the capital bazaar uses (§8.0.1), and
     * the reason a center kill can hand you a rare carrying three lines.
     *
     * §7.6 -- it needs a strap. Without one it is named as left behind rather
     * than forced in or silently dropped: an unwinnable refusal at the end of a
     * fight you did not pick would be worse than either.
     *
     * @return array<string,mixed>|null
     */
    private function takeLootedGear(
        Character $character,
        array $monster,
        int $seed,
        ?string &$leftBehind,
        int $extraOption = 0,
    ): ?array {
        $key = Drops::lootedGear($monster, $seed);
        if ($key === null) {
            return null;
        }

        $def = Catalog::item($key);
        if ($def === null) {
            return null;
        }

        if (! $this->hasFreeRow($character)) {
            $leftBehind = $def['name'];

            return null;
        }

        $max = (int) ($def['maxDurability'] ?? 1);
        $durability = max(1, (int) round($max * Hash::randInt(
            Hash::hash2($seed, 41, Balance::mapSeed() ^ 0x5909),
            Balance::LOOT_DURABILITY_MIN_PERCENT,
            Balance::LOOT_DURABILITY_MAX_PERCENT,
        ) / 100));

        // §8.0.1 -- harder packs roll better options, never better rarity.
        $item = CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => $key,
            'durability' => $durability,
            'equipped' => false,
            'options' => $this->rollFor(
                $character,
                $def,
                intdiv((int) $monster['tier'], 2) + $extraOption,
            ),
        ]);

        return [
            'key' => $key,
            'name' => $def['name'],
            'rarity' => $def['rarity'],
            'durability' => $durability,
            'maxDurability' => $max,
            'options' => $item->options,
        ];
    }

    /**
     * §9.5.7 -- YOUR corpses, at any distance and through any fog.
     *
     * This rides the player state rather than the map, and the split is what
     * makes the two endpoints mean exactly one thing each: the state is what is
     * YOURS and is bounded by nothing, the map is what is AROUND you and is
     * bounded by sight (§5.6). A corpse of yours is a row on a clock four days
     * away -- plainly the first kind, and it was on the wrong endpoint.
     *
     * A debt you cannot find is a fine with extra steps, which is the whole
     * reason this one thing ignores the fog.
     *
     * @return list<array<string,mixed>>
     */
    public function ownCarriers(Character $character): array
    {
        return Carrier::where('owner_character_id', $character->id)
            ->where('expires_at', '>', $this->now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn (Carrier $c) => $this->carrierPayload($c, true))
            ->all();
    }

    /**
     * §9.5.7 -- SOMEBODY ELSE'S corpses, and only the ones inside sight.
     *
     * A stranger's corpse is not owed to you, and a map-wide list of them would
     * be a scanner: every death on the server, live, with the rich ones worth
     * racing to. Finding one is the interesting part, and §5.6's disc is what
     * keeps it that way.
     *
     * On the road sight is zero, so these wink out and your own does not. That
     * asymmetry is the two rules working, not a hole in either.
     *
     * @return list<array<string,mixed>>
     */
    public function carriersInSight(Character $character): array
    {
        $range = $this->sightRadius($character);
        $col = (int) $character->col;
        $row = (int) $character->row;

        return Carrier::where('expires_at', '>', $this->now())
            ->where('owner_character_id', '!=', $character->id)
            // The box is the cheap index scan; the disc is the filter below.
            ->whereBetween('col', [$col - $range, $col + $range])
            ->whereBetween('row', [$row - $range, $row + $range])
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (Carrier $c) => HexGeometry::distance($col, $row, $c->col, $c->row) <= $range)
            ->values()
            ->map(fn (Carrier $c) => $this->carrierPayload($c, false))
            ->all();
    }

    /** @return array<string,mixed> */
    private function carrierPayload(Carrier $carrier, bool $mine): array
    {
        return [
            'col' => $carrier->col,
            'row' => $carrier->row,
            'monster' => $carrier->monster_key,
            'label' => $carrier->label,
            'until' => $carrier->expires_at,
            'mine' => $mine,
            'owner' => $carrier->owner?->name ?? 'Someone',
        ];
    }

    /**
     * §9.5.7 -- the pack takes one row from the bag, truly random.
     *
     * Every strap is a candidate and they are all equally likely: a material
     * stack, a cellar of potions, a spare axe. Worn gear is not carried (§7.6),
     * so what is on your belt is what you die in and what you wake up in.
     *
     * Flat gold loss was the alternative and it teaches nothing -- a number
     * evaporates and the day goes on. A corpse gives death a hook.
     *
     * @return array{label:string,kind:string}|null
     */
    private function takeRowForCarrier(Character $character, string $monsterKey, int $seed, int $now): ?array
    {
        $rows = [];

        foreach ($character->materials()->where('quantity', '>', 0)->get() as $stack) {
            $rows[] = [
                'kind' => 'material',
                'key' => $stack->material_key,
                'quantity' => (int) $stack->quantity,
                'label' => (int) $stack->quantity.' × '
                    .(Catalog::material($stack->material_key)['name'] ?? $stack->material_key),
                'model' => $stack,
            ];
        }

        foreach ($character->consumables()->where('quantity', '>', 0)->get() as $stack) {
            $rows[] = [
                'kind' => 'consumable',
                'key' => $stack->item_key,
                'quantity' => (int) $stack->quantity,
                'label' => (int) $stack->quantity.' × '
                    .(Catalog::item($stack->item_key)['name'] ?? $stack->item_key),
                'model' => $stack,
            ];
        }

        foreach ($character->items()->where('equipped', false)->get() as $item) {
            $rows[] = [
                'kind' => 'item',
                'key' => $item->item_key,
                'durability' => (int) $item->durability,
                'options' => $item->options ?? [],
                'label' => Catalog::item($item->item_key)['name'] ?? $item->item_key,
                'model' => $item,
            ];
        }

        // An empty bag has nothing to take, and nothing to stand over. The
        // walk back is the whole bill in that case, which is the right answer:
        // there is no way to owe more than you were carrying.
        if ($rows === []) {
            return null;
        }

        $taken = $rows[Hash::randInt(Hash::hash2($seed, count($rows), Balance::mapSeed() ^ 0x0DEAD), 0, count($rows) - 1)];
        $model = $taken['model'];
        unset($taken['model']);

        $model->delete();

        Carrier::create([
            'col' => (int) $character->col,
            'row' => (int) $character->row,
            'monster_key' => $monsterKey,
            'owner_character_id' => $character->id,
            'loot' => $taken,
            'label' => $taken['label'],
            'expires_at' => $now + Balance::scaled(Balance::CARRIER_LIFETIME_MS),
        ]);

        return ['label' => $taken['label'], 'kind' => $taken['kind']];
    }

    /**
     * §7.6 -- would the recovered row have a strap to land on?
     *
     * Asked before the fight rather than after it, for the same reason a craft
     * is: the answer is a refusal you can act on from where you stand, and a
     * row taken twice is not something an idle game may do.
     *
     * @param  array<string,mixed>  $loot
     */
    private function requireRoomForLoot(Character $character, array $loot): void
    {
        $joins = match ($loot['kind']) {
            'material' => $this->held($character, (string) $loot['key']) > 0,
            'consumable' => $this->heldConsumable($character, (string) $loot['key']) > 0,
            default => false,
        };

        if (! $joins) {
            $this->requireFreeRow($character, (string) $loot['label']);
        }
    }

    /**
     * §9.5.7 -- the row comes home, exactly as it left.
     *
     * An item keeps the durability and the rolled options it had: this is a
     * recovery, not a reissue, and a repaired-by-dying exploit would be a hole
     * in §11.1's largest sink.
     *
     * @param  array<string,mixed>  $loot
     */
    private function restoreLoot(Character $character, array $loot): string
    {
        match ($loot['kind']) {
            'material' => $this->addMaterial($character, (string) $loot['key'], (int) $loot['quantity']),
            'consumable' => CharacterConsumable::firstOrNew([
                'character_id' => $character->id,
                'item_key' => (string) $loot['key'],
            ])->fill(['quantity' => $this->heldConsumable($character, (string) $loot['key']) + (int) $loot['quantity']])->save(),
            default => CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => (string) $loot['key'],
                'durability' => (int) $loot['durability'],
                'equipped' => false,
                'options' => $loot['options'] ?? [],
            ]),
        };

        return (string) $loot['label'];
    }

    /**
     * §9.5.7 -- you wake at the nearest settlement.
     *
     * Nearest rather than your own or your last, because there is no such thing
     * as either: settlements are shared world locations (§6), and the one that
     * takes you in is simply the closest roof.
     *
     * @return array{name:string,col:int,row:int}|null
     */
    private function wakeAtNearestSettlement(Character $character): ?array
    {
        $col = (int) $character->col;
        $row = (int) $character->row;
        $best = null;
        $bestDistance = PHP_INT_MAX;

        for ($range = 0; $range <= Balance::DEATH_WAKE_RADIUS; $range++) {
            for ($dc = -$range; $dc <= $range; $dc++) {
                for ($dr = -$range; $dr <= $range; $dr++) {
                    if (max(abs($dc), abs($dr)) !== $range) {
                        continue;
                    }

                    $s = WorldGen::settlementAt($col + $dc, $row + $dr);
                    if ($s === null) {
                        continue;
                    }

                    $distance = HexGeometry::distance($col, $row, (int) $s['col'], (int) $s['row']);
                    if ($distance < $bestDistance) {
                        $best = $s;
                        $bestDistance = $distance;
                    }
                }
            }

            // A whole shell further out cannot beat something already this
            // close, so the first shell that finds anything settles it.
            if ($best !== null && $bestDistance <= $range) {
                break;
            }
        }

        if ($best === null) {
            return null;
        }

        $character->col = (int) $best['col'];
        $character->row = (int) $best['row'];

        return ['name' => (string) $best['name'], 'col' => (int) $best['col'], 'row' => (int) $best['row']];
    }

    /**
     * §8.2 -- take the wear, and if it takes the last of it the thing is GONE.
     *
     * Not broken, not inactive: destruction is what moves the repair bill
     * forward, and it is the largest sink in the game (§11.1). The row is
     * deleted and named in the result that killed it, because an idle game may
     * take something expensive from a player but never quietly.
     *
     * @return array{name:string,slot:?string,lost:int,left:int,destroyed:bool}
     */
    private function wearInFight(CharacterItem $item, int $amount): array
    {
        $def = Catalog::item($item->item_key);
        $before = (int) $item->durability;
        $left = max(0, $before - $amount);

        $row = [
            'name' => $def['name'] ?? $item->item_key,
            'slot' => $def['slot'] ?? null,
            'lost' => min($amount, $before),
            'left' => $left,
            'destroyed' => $left <= 0,
        ];

        if ($left <= 0) {
            $item->delete();
        } else {
            $item->durability = $left;
            $item->save();
        }

        return $row;
    }

    /**
     * Server-computed preview of what a mine here would cost and give.
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
                'reason' => $this->isTraveling($character)
                    ? 'You are watching your feet. Nothing is scouted until you stop.'
                    : 'Too far to make out. Walk there and see for yourself.',
                'seconds' => 0,
                'hp' => 0,
                'toolAttack' => 0,
                'skillAttack' => 0,
                'skillBite' => 0,
                'rate' => 0.0,
                'clamped' => false,
                'able' => false,
                'yield' => 0,
                'material' => null,
                'bare' => false,
                'drops' => [],
                'activity' => $activity,
                'skill' => null,
                'scrap' => false,
                'note' => null,
                'unseen' => true,
                'warnings' => [],
                // §9.5.3 -- unknown rather than false, strictly, but the pin is
                // about the ground under your feet and this hex is not it. The
                // key is here because every caller reads it, and a preview
                // missing one of its own fields is worse than a flat answer.
                'pinned' => false,
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
            'hp' => $tile['hp'],
            'toolAttack' => 0,
            'skillAttack' => 0,
            'skillBite' => 0,
            'rate' => 0.0,
            'clamped' => false,
            'able' => false,
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
            'warnings' => [],
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
        // belt, so the line-locked tool and its nodes sit this mine out.
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

        // §4.0 -- bare hands are bare hands. Gathering pays the bare-handed rate
        // even when there is a perfectly good axe on your back, because the
        // whole point of the verb is that you are not using it.
        //
        // §7.3 -- the attack passed here is the WHOLE base rate, not a bonus on
        // top of one. Mining never reads BARE_HAND_ATTACK: without a pick the
        // verb is refused rather than downgraded, so a mine is worked with the
        // tool or with the hands and never with both.
        $mine = Formulas::mineTime(
            $tile['hp'],
            $skillLevel,
            $bonuses['tripReduction'],
            $gathering ? Balance::BARE_HAND_ATTACK : $this->lineToolAttack($character, $skillKey),
            // §7.4.3 -- the line's own tree, in whole points of the same attack
            // the tool carries. It counts bare-handed too: gathering a forest
            // hex is woodcutting whether or not there is an axe on your back,
            // and §4.0 gives up the tool, not what you know about trees.
            (int) $this->jobEffects($character, $skillKey)['bite'],
        );

        // §8.2 -- nothing is destroyed without warning, and a mine wears gear
        // like a fight does. An hour of work that ends in a lost axe has to be
        // a decision the player made rather than one they discovered.
        $warnings = $gathering ? [] : $this->wearWarnings($character, $skillKey);

        // You work the hex you are standing on -- there is no reaching across the
        // map for a seam. Everything above is a fact about the tile and holds
        // wherever it is read from, so a hex you are only scouting still reports
        // its haul and mine time; what changes is whether you may act on it.
        $reason = null;
        $working = $this->miningTrip($character);

        if ($this->isTraveling($character)) {
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
            // stays open: gathering is the same mine on the same ground, and
            // §4.0 is satisfied by the button beside this one, not by quietly
            // handing back scrap from the one that was pressed.
            $reason = $note;
        }

        return [
            'canMine' => $reason === null,
            'reason' => $reason,
            'seconds' => $mine['total'],
            'hp' => $mine['hp'],
            'toolAttack' => $mine['toolAttack'],
            'skillAttack' => $mine['skillAttack'],
            'skillBite' => $mine['skillBite'],
            'rate' => $mine['rate'],
            'clamped' => $mine['clamped'],
            // §8.0 rule 1 -- the verb is refused without its tool, not merely
            // slowed. Skill alone puts a point a second behind an empty hand,
            // which would otherwise print a clock beside a dead button for
            // anybody past level ten of the line.
            'able' => $mine['able'] && ! ($bare && ! $gathering),
            'yield' => Formulas::mineYield(
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
            // often is what the mine is for.
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
            // §8.2 -- what this mine would finish off.
            'warnings' => $warnings,
        ];
    }

    /**
     * §8.2 -- gear a mine on this line would wear out entirely.
     *
     * The same promise the fight preview makes (§9.5.5), kept on the other
     * verb: destruction is the largest sink in the game and it may never be a
     * surprise. Line-locked exactly as the wear is -- the axe on your back is
     * not at risk while you are down a mine.
     *
     * @return list<string>
     */
    private function wearWarnings(Character $character, ?string $line): array
    {
        $out = [];

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

            if ((int) $item->durability <= Balance::DRAIN_PER_MINE) {
                $out[] = $def['name'].' will not survive this mine.';
            }
        }

        return $out;
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
            // §7.3 -- nothing costed until the bow and the line are known, and
            // there is no bare-handed hunt to fall back on.
            'seconds' => 0,
            'hp' => Balance::HERD_HP,
            'toolAttack' => 0,
            'skillAttack' => 0,
            'skillBite' => 0,
            'rate' => 0.0,
            'clamped' => false,
            'able' => false,
            'herdUntil' => null,
            'yield' => 0,
            'material' => null,
            'scrap' => false,
            'note' => null,
            'unseen' => false,
        ];

        if ($distance > $this->sightRadius($character)) {
            $base['reason'] = $this->isTraveling($character)
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
            Hash::hash2($col, $row + $herdUntil, Balance::mapSeed() ^ 0x8EED),
            Balance::HUNT_PELT_MIN,
            Balance::HUNT_PELT_MAX,
        );

        // §7.3 -- a herd is a pile of work like a hex is, and the bow is what
        // gets through it. It was a flat 25 minutes for as long as the floor
        // clamp would have swallowed the difference; it no longer would.
        $mine = Formulas::mineTime(
            Balance::HERD_HP,
            $skillLevel,
            $bonuses['tripReduction'],
            $this->lineToolAttack($character, 'hunting'),
            (int) $this->jobEffects($character, 'hunting')['bite'],
        );

        $base['seconds'] = $mine['total'];
        $base['hp'] = $mine['hp'];
        $base['toolAttack'] = $mine['toolAttack'];
        $base['skillAttack'] = $mine['skillAttack'];
        $base['skillBite'] = $mine['skillBite'];
        $base['rate'] = $mine['rate'];
        $base['clamped'] = $mine['clamped'];
        $base['able'] = $mine['able'];

        $base['material'] = $material;
        $base['scrap'] = $bare;
        $base['yield'] = Formulas::mineYield(
            $rolled,
            $skillLevel,
            $bonuses['yield'],
            WorldGen::ringYield($tile['ring']),
        );

        $base['drops'] = Drops::kinds(Drops::HUNTING, $tile, $reach);

        $working = $this->miningTrip($character);

        $base['reason'] = match (true) {
            $this->isTraveling($character) => 'You are on the road. Stop the journey, or wait until you arrive.',
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
                'ends_at' => $now + Balance::scaled((int) $preview['seconds'] * 1000),
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
     * One method for both verbs, because everything that makes a mine a mine is
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
            // before the mine could start, and stays on it while the timer runs.
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

            // §6, §8.4 -- anything left at a bench is claimed AT that bench.
            //
            // A settlement is a place (§6), and work left in one is work left
            // somewhere. Claiming it from the other side of the map would make
            // the building a mailbox: carry materials in, walk off, collect
            // wherever you happen to be. The walk back is what makes choosing
            // which capital to use a decision rather than a formality.
            if ($job->settlement_id !== null) {
                $bench = $this->settlement((string) $job->settlement_id);
                $away = $this->isTraveling($character)
                    || (int) $character->col !== (int) $bench['col']
                    || (int) $character->row !== (int) $bench['row'];

                if ($away) {
                    throw new GameException(
                        "That is waiting for you at {$bench['name']}.",
                        'not_present',
                    );
                }
            }

            // §9.5.5 -- a fight answers with its own report. Nothing here is a
            // haul: there is no material, no tile and no XP ladder in common
            // with a mine, so it returns before any of that is assembled.
            if ($job->kind === 'battle') {
                return $this->finishBattle($character, $job);
            }

            $gained = [];
            $durabilityLost = 0;
            // §8.2 -- anything the mine wore out entirely, named in the result
            // that killed it.
            $destroyed = [];
            // §7.4 -- the bench trade a run teaches, if it teaches one. A mine
            // never does: a gathering job's level IS its §7.2 skill level, so
            // there is no second number for it to report.
            $jobKey = null;
            $jobXp = 0;

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
                $durabilityLost = $this->drainDurability(
                    $character,
                    $this->tripDrain($character, $job, (string) $job->skill_key),
                    $job->skill_key,
                    $destroyed,
                );

                // §5.1 -- one haul off a hex that holds a known number of them,
                // shared with everybody who works it. Worked-out tiles regrow
                // rather than dying; nothing here is rolled.
                Tiles::take(
                    (int) $job->col,
                    (int) $job->row,
                    WorldGen::tileExtractions(
                        WorldGen::generateTile((int) $job->col, (int) $job->row, $now)['baseYield'],
                    ),
                    $now,
                );

            } elseif ($job->kind === 'hunting') {
                [$gained, $lostToOverflow] = $this->grantHaul($character, $job);

                $bare = Catalog::isScrap($job->material_key);
                $xpAmount = $bare
                    ? max(1, (int) round($job->quantity * 4 * Balance::SCRAP_XP_RATE))
                    : $job->quantity * 4;

                // A bow is drawn, so a bow wears. The other four slots idle,
                // §8.0 rule 2 -- drainDurability already scopes to the line.
                $durabilityLost = $this->drainDurability(
                    $character,
                    $this->tripDrain($character, $job, 'hunting'),
                    'hunting',
                    $destroyed,
                );

                // Nothing comes off the hex: a herd is not a seam, and it
                // leaves on its own clock whatever anybody does here.
            } elseif ($job->kind === 'craft') {
                // §8.4 -- the bench hands over one thing, not a haul. Nothing
                // here goes through the material ledger, so `gained` stays
                // empty and the receipt names the item instead.
                $crafted = $this->finishCraft($character, $job);
                $lostToOverflow = 0;
                $xpAmount = 0;

                $job->delete();
                $character->save();

                return [
                    'gained' => [],
                    'lostToOverflow' => 0,
                    'made' => $crafted['made'],
                    'xp' => ['skill' => null, 'amount' => 0],
                    // §7.4 -- the bench's own trade. A craft teaches no §7.2
                    // skill and no character XP, so without this the receipt
                    // for an hour at the anvil was a row of zeroes.
                    'job' => $crafted['job'],
                    'jobXp' => $crafted['jobXp'],
                    'characterXp' => 0,
                    'levelsGained' => 0,
                    'durabilityLost' => 0,
                    'destroyed' => [],
                ];
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
                    $jobKey = $processJob;
                    $jobXp = $this->grantJobXp(
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
                'job' => $jobKey,
                'jobXp' => $jobXp,
                'characterXp' => $characterXp,
                'levelsGained' => $levelsGained,
                'durabilityLost' => $durabilityLost,
                'destroyed' => $destroyed,
            ];
        });
    }

    /**
     * Turn a finished mine into a haul, §4.
     *
     * The job already records the material its mine resolved to, and that key
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

        // §9.5.5 -- a fight is not one of the things you may walk away from.
        // §9.5.3 offers exactly two exits from a pack, fight it or wait it out,
        // and once the first is chosen there is no third: dropping it would be
        // a way to duck a loss, and losing is a death (§9.5.7).
        if ($job->kind === 'battle') {
            throw new GameException('You are in it now. See it out.', 'blocked');
        }

        // §11.1 -- abandoning mid-progress forfeits the partial yield. This is a
        // sink, and it is meant to sting.
        $job->delete();
    }

    // ------------------------------------------------------------- guilds §10

    /** §10.0 -- the guild this character belongs to, or none. */
    public function guildOf(Character $character): ?Guild
    {
        $row = GuildMember::where('character_id', $character->id)->first();

        return $row?->guild;
    }

    /**
     * §10.0 -- found one, at a city or a capital, for twenty thousand gold.
     *
     * The three refusals are the whole of the rule: a village is not somewhere
     * a guild can stand (§6 -- a guild is a place before it is a roster), you
     * cannot belong to two, and the gold has to actually be in your purse. Each
     * is checked before anything is spent.
     */
    public function foundGuild(Character $character, array $identity): Guild
    {
        return DB::transaction(function () use ($character, $identity) {
            if ($this->guildOf($character) !== null) {
                throw new GameException('You are already in a guild. Leave it first.', 'in_guild');
            }

            // §10.0 -- a city or a capital. Never a village, never open country.
            $settlement = $this->requireSettlement($character, 'found a guild', 'city');

            if ((int) $character->gold < Balance::GUILD_FOUNDING_COST) {
                $short = Balance::GUILD_FOUNDING_COST - (int) $character->gold;

                throw new GameException(
                    'A hall costs '.Balance::GUILD_FOUNDING_COST." gold. You are {$short} short.",
                    'poor',
                );
            }

            $name = $this->cleanGuildName($identity['name'] ?? '');
            $code = $this->cleanGuildCode($identity['code'] ?? '');

            // Checked here as well as by the unique index, because a collision
            // should read as "that name is taken" rather than as a 500.
            if (Guild::where('name', $name)->exists()) {
                throw new GameException("There is already a guild called {$name}.", 'taken');
            }
            if (Guild::where('code', $code)->exists()) {
                throw new GameException("The code {$code} is taken.", 'taken');
            }

            $character->gold -= Balance::GUILD_FOUNDING_COST;
            $character->save();

            $guild = Guild::create([
                'name' => $name,
                'code' => $code,
                'description' => $this->cleanGuildDescription($identity['description'] ?? ''),
                'flag' => $this->cleanGuildFlag($identity['flag'] ?? null),
                'settlement_id' => $settlement['id'],
                'col' => (int) $settlement['col'],
                'row' => (int) $settlement['row'],
                'founder_character_id' => $character->id,
                'recruitment' => Guild::OPEN,
            ]);

            GuildMember::create([
                'guild_id' => $guild->id,
                'character_id' => $character->id,
                'role' => GuildMember::OWNER,
                'joined_at' => $this->now(),
            ]);

            return $guild;
        });
    }

    /**
     * §10.0.1 -- who is recruiting, which is the whole of the join flow.
     *
     * Closed guilds are not listed AT ALL rather than listed and refused: a
     * roster you can see and cannot join is a queue with extra steps, and the
     * flag exists precisely so there is no queue.
     *
     * @return list<array<string,mixed>>
     */
    public function recruitingGuilds(): array
    {
        return Guild::whereIn('recruitment', [Guild::OPEN, Guild::APPROVAL])
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Guild $g) => $this->guildPayload($g))
            ->all();
    }

    /**
     * §10.0.1 -- walk in. No application, no approval, no waiting.
     *
     * An approval flow needs a pending list, a decision and a way to tell
     * somebody the answer, and its only output is a delay. A guild that does
     * not want a prospector removes them in one tap, so the cost of a wrong
     * join is one tap rather than a day.
     */
    public function joinGuild(Character $character, int $guildId): array
    {
        return DB::transaction(function () use ($character, $guildId) {
            if ($this->guildOf($character) !== null) {
                throw new GameException('You are already in a guild. Leave it first.', 'in_guild');
            }

            $guild = Guild::find($guildId);
            if ($guild === null) {
                throw new GameException('That guild no longer exists.', 'not_found');
            }

            if ($guild->recruitment === Guild::CLOSED) {
                throw new GameException("{$guild->name} is not taking anybody on.", 'closed');
            }

            // §10.0.1 -- the third position of the door: listed, but the owner
            // decides who comes through it.
            if ($guild->recruitment === Guild::APPROVAL) {
                if (GuildApplication::where('guild_id', $guild->id)
                    ->where('character_id', $character->id)
                    ->exists()) {
                    throw new GameException("{$guild->name} already has your name down.", 'applied');
                }

                GuildApplication::create([
                    'guild_id' => $guild->id,
                    'character_id' => $character->id,
                    'applied_at' => $this->now(),
                ]);

                return ['guild' => $guild, 'applied' => true];
            }

            $this->admitToGuild($character, $guild);

            return ['guild' => $guild, 'applied' => false];
        });
    }

    /**
     * §10.0.1 -- let somebody in, and tear up every other name they put down.
     *
     * A prospector in a guild is in exactly one (§10.0), so applications
     * elsewhere are answers to a question that is no longer being asked.
     */
    private function admitToGuild(Character $character, Guild $guild): void
    {
        // §10.5 -- the seats. Both doors arrive here, so this is the one place
        // a full hall has to say so.
        $this->requireSeat($guild);

        GuildMember::create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'role' => GuildMember::MEMBER,
            'joined_at' => $this->now(),
        ]);

        GuildApplication::where('character_id', $character->id)->delete();
    }

    /**
     * §10.5 -- the seats a hall has, and whether one is free.
     *
     * A flat base plus what the Hall facility has been built to. Enforced on
     * the way IN rather than as a warning, and in both doors -- walking into an
     * open guild and being let into an approval one are the same arrival.
     */
    public function guildRosterCap(Guild $guild): int
    {
        return Balance::guildRosterCap((int) $guild->hall_level);
    }

    private function requireSeat(Guild $guild): void
    {
        $cap = $this->guildRosterCap($guild);

        if ($guild->members()->count() >= $cap) {
            throw new GameException(
                "{$guild->name} seats {$cap} and is full. The hall has to be built out first.",
                'full',
            );
        }
    }

    /**
     * §10.5 -- how far up §8.0's ladder this guild's own bench reaches.
     *
     * Measured from what the settlement underneath it already reaches, and
     * climbing one rung a level. That is what stops the first levels being
     * money thrown away: a hall in a city starts at uncommon and needs three
     * levels to reach legendary, one in a capital starts at epic and needs one.
     * The gap is the pull inward §5.2 puts on everything else.
     */
    public function guildBenchReach(Guild $guild): string
    {
        $tier = WorldGen::settlementById($guild->settlement_id)['tier'] ?? 'city';
        $base = Balance::STATION_RARITY_CAP[$tier] ?? 'common';

        $rank = Balance::rarityRank($base) + (int) $guild->bench_level;

        return Balance::RARITIES[min($rank, Balance::rarityRank('legendary'))];
    }

    /** §10.5 -- the last Bench level worth buying, which is the one reaching legendary. */
    public function guildBenchMaxLevel(Guild $guild): int
    {
        $tier = WorldGen::settlementById($guild->settlement_id)['tier'] ?? 'city';
        $base = Balance::STATION_RARITY_CAP[$tier] ?? 'common';

        return max(0, Balance::rarityRank('legendary') - Balance::rarityRank($base));
    }

    /** §10.5 -- what the next level of a facility costs, or null when it is finished. */
    public function guildFacilityNextCost(Guild $guild, string $facility): ?int
    {
        $level = $this->guildFacilityLevel($guild, $facility);
        $max = $facility === 'bench'
            ? $this->guildBenchMaxLevel($guild)
            : Balance::GUILD_HALL_MAX_LEVEL;

        return $level >= $max ? null : Balance::guildFacilityCost($level + 1);
    }

    private function guildFacilityLevel(Guild $guild, string $facility): int
    {
        return match ($facility) {
            'hall' => (int) $guild->hall_level,
            'bench' => (int) $guild->bench_level,
            default => throw new GameException('No such facility.', 'not_found'),
        };
    }

    /**
     * §10.5 -- put gold in the treasury. It does not come back out.
     *
     * Non-retractable, exactly as §10.4 requires of a bidding donation and for
     * the same reason: a pot that can be emptied again is a pot whose size can
     * be scouted, and a contribution you can take back is not a contribution.
     * What it buys is a facility, and a facility is the whole roster's.
     *
     * Anybody in the guild may donate. It is the one guild action with no rank
     * on it -- gold going the right way needs no permission.
     */
    public function donateToGuild(Character $character, int $gold): Guild
    {
        return DB::transaction(function () use ($character, $gold) {
            if ($gold < 1) {
                throw new GameException('Donate something.', 'invalid');
            }

            $member = GuildMember::where('character_id', $character->id)->first();
            if ($member === null) {
                throw new GameException('You are not in a guild.', 'no_guild');
            }

            $character->refresh();

            if ((int) $character->gold < $gold) {
                throw new GameException("You do not have {$gold} gold.", 'poor');
            }

            $character->gold -= $gold;
            $character->save();

            $member->donated += $gold;
            $member->save();

            $guild = $member->guild;
            $guild->gold += $gold;
            $guild->save();

            return $guild;
        });
    }

    /**
     * §10.5 -- spend the treasury on a facility level.
     *
     * The owner alone, because §10.0.2 keeps everything irreversible with them
     * and three hundred thousand gold is the most irreversible thing a guild
     * can do. An officer opening a door wrong costs one tap to undo; an officer
     * spending the roster's year of saving costs the year.
     */
    public function upgradeGuildFacility(Character $character, string $facility): Guild
    {
        return DB::transaction(function () use ($character, $facility) {
            $actor = $this->requireGuildRank($character, [GuildMember::OWNER]);

            $guild = Guild::lockForUpdate()->find($actor->guild_id);
            if ($guild === null) {
                throw new GameException('That guild no longer exists.', 'not_found');
            }

            $level = $this->guildFacilityLevel($guild, $facility);
            $cost = $this->guildFacilityNextCost($guild, $facility);

            if ($cost === null) {
                throw new GameException(
                    $facility === 'bench'
                        ? 'The bench already reaches legendary. Nothing is above it.'
                        : 'The hall is built out as far as it goes.',
                    'maxed',
                );
            }

            if ((int) $guild->gold < $cost) {
                $short = $cost - (int) $guild->gold;

                throw new GameException(
                    "That costs {$cost} gold and the treasury is {$short} short.",
                    'poor',
                );
            }

            $guild->gold -= $cost;

            if ($facility === 'bench') {
                $guild->bench_level = $level + 1;
            } else {
                $guild->hall_level = $level + 1;
            }

            $guild->save();

            return $guild;
        });
    }

    /** §10.0.1 -- take a name off the list yourself. */
    public function withdrawApplication(Character $character, int $guildId): void
    {
        GuildApplication::where('guild_id', $guildId)
            ->where('character_id', $character->id)
            ->delete();
    }

    /**
     * §10.0.1 -- answer somebody. Owners and officers, like every other door
     * duty: it is reversible in one action, which is what makes the rank safe.
     */
    public function decideApplication(Character $character, int $characterId, bool $admit): void
    {
        DB::transaction(function () use ($character, $characterId, $admit) {
            $actor = $this->requireGuildRank($character, [GuildMember::OWNER, GuildMember::OFFICER]);

            $application = GuildApplication::where('guild_id', $actor->guild_id)
                ->where('character_id', $characterId)
                ->first();

            if ($application === null) {
                throw new GameException('Nobody by that name has asked.', 'not_found');
            }

            if (! $admit) {
                $application->delete();

                return;
            }

            $applicant = Character::find($characterId);

            // They may have joined somewhere else while the letter sat here.
            if ($applicant === null || $this->guildOf($applicant) !== null) {
                $application->delete();

                throw new GameException('They have found a guild already.', 'gone');
            }

            $this->admitToGuild($applicant, $actor->guild);
        });
    }

    /**
     * §10.0.1 -- who has asked, and where this character has asked.
     *
     * @return list<array<string,mixed>>
     */
    public function guildApplications(Guild $guild): array
    {
        return $guild->applications()
            ->with('character')
            ->orderBy('applied_at')
            ->get()
            ->map(fn (GuildApplication $a) => [
                'characterId' => (string) $a->character_id,
                'name' => $a->character?->name ?? 'Prospector',
                'level' => (int) ($a->character?->level ?? 1),
                'appliedAt' => $a->applied_at,
            ])
            ->all();
    }

    /** @return list<string> guild ids this character is waiting on */
    public function pendingApplicationsOf(Character $character): array
    {
        return GuildApplication::where('character_id', $character->id)
            ->pluck('guild_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * §10.0.2 -- leave. The last owner may not, while anybody is still in.
     *
     * A guild whose owner has walked away is a guild nobody can close, and it
     * would sit on its name and its code forever. Hand it over or disband it.
     */
    public function leaveGuild(Character $character): void
    {
        DB::transaction(function () use ($character) {
            $row = GuildMember::where('character_id', $character->id)->first();
            if ($row === null) {
                throw new GameException('You are not in a guild.', 'no_guild');
            }

            if ($row->role === GuildMember::OWNER) {
                $others = GuildMember::where('guild_id', $row->guild_id)
                    ->where('character_id', '!=', $character->id)
                    ->count();

                if ($others > 0) {
                    throw new GameException(
                        'Hand the guild over before you go, or disband it.',
                        'owner',
                    );
                }

                // Last one out turns the lights off: an empty guild is not a
                // guild, and leaving its name and code reserved would be.
                $row->guild?->delete();

                return;
            }

            $row->delete();
        });
    }

    /**
     * §10.0.2 -- remove somebody. Owners and officers, never a member.
     *
     * Everything an officer may do is reversible in one action, which is what
     * makes the rank safe to hand out: the worst an officer can do is make
     * somebody rejoin.
     */
    public function removeMember(Character $character, int $characterId): void
    {
        DB::transaction(function () use ($character, $characterId) {
            $actor = $this->requireGuildRank($character, [GuildMember::OWNER, GuildMember::OFFICER]);

            if ($characterId === (int) $character->id) {
                throw new GameException('Use leave for that.', 'blocked');
            }

            $target = GuildMember::where('guild_id', $actor->guild_id)
                ->where('character_id', $characterId)
                ->first();

            if ($target === null) {
                throw new GameException('They are not in your guild.', 'not_found');
            }

            // An officer may not remove an owner, and may not remove a peer:
            // two officers removing each other is a coin toss over a guild.
            if ($actor->role !== GuildMember::OWNER && $target->role !== GuildMember::MEMBER) {
                throw new GameException('Only the owner can remove an officer.', 'forbidden');
            }

            $target->delete();
        });
    }

    /**
     * §10.0.2 -- promote, demote, or hand the whole thing over.
     *
     * Only the owner, and handing over is one move rather than two: a guild
     * with two owners for even one request is a guild either of them can
     * disband.
     */
    public function setMemberRole(Character $character, int $characterId, string $role): void
    {
        DB::transaction(function () use ($character, $characterId, $role) {
            $actor = $this->requireGuildRank($character, [GuildMember::OWNER]);

            if (! in_array($role, [GuildMember::OWNER, GuildMember::OFFICER, GuildMember::MEMBER], true)) {
                throw new GameException('No such rank.', 'blocked');
            }

            $target = GuildMember::where('guild_id', $actor->guild_id)
                ->where('character_id', $characterId)
                ->first();

            if ($target === null) {
                throw new GameException('They are not in your guild.', 'not_found');
            }

            if ($role === GuildMember::OWNER) {
                $target->role = GuildMember::OWNER;
                $target->save();

                // Handing over, not sharing.
                $actor->role = GuildMember::OFFICER;
                $actor->save();

                return;
            }

            if ($target->id === $actor->id) {
                throw new GameException('Hand the guild to somebody else instead.', 'blocked');
            }

            $target->role = $role;
            $target->save();
        });
    }

    /**
     * §10.0.3 -- the identity: description, flag, and whether the door is open.
     *
     * The NAME and the CODE are not here. They are how everybody else refers to
     * this guild, and a thing that renames itself is a thing nobody can point
     * at twice.
     */
    public function updateGuild(Character $character, array $changes): Guild
    {
        return DB::transaction(function () use ($character, $changes) {
            $actor = $this->requireGuildRank($character, [GuildMember::OWNER, GuildMember::OFFICER]);
            $guild = $actor->guild;

            // §10.0.2 -- an officer holds the door; the owner owns the face.
            // §10.0.1 -- one setting with three positions. An officer holds it
            // for the same reason they hold everything else on the door: every
            // move of it is reversible in one action.
            if (array_key_exists('recruitment', $changes)) {
                $door = (string) $changes['recruitment'];

                if (! in_array($door, Guild::DOORS, true)) {
                    throw new GameException('No such door.', 'invalid');
                }

                $guild->recruitment = $door;
            }

            if ($actor->role === GuildMember::OWNER) {
                if (array_key_exists('description', $changes)) {
                    $guild->description = $this->cleanGuildDescription((string) $changes['description']);
                }
                if (array_key_exists('flag', $changes)) {
                    $guild->flag = $this->cleanGuildFlag($changes['flag']);
                }
            } elseif (array_key_exists('description', $changes) || array_key_exists('flag', $changes)) {
                throw new GameException('Only the owner can change the guild itself.', 'forbidden');
            }

            $guild->save();

            return $guild;
        });
    }

    /** @param  list<string>  $allowed */
    private function requireGuildRank(Character $character, array $allowed): GuildMember
    {
        $row = GuildMember::where('character_id', $character->id)->first();

        if ($row === null) {
            throw new GameException('You are not in a guild.', 'no_guild');
        }

        if (! in_array($row->role, $allowed, true)) {
            throw new GameException('Your rank does not allow that.', 'forbidden');
        }

        return $row;
    }

    /**
     * §8.0 -- is this character standing at their own guild's hall?
     *
     * The one question the legendary bench asks. Members only, at their own
     * hall: a hall open to passers-by would be a public good rather than a
     * reason to join, and a legendary bench that needed no guild would make
     * §10 optional.
     */
    public function atOwnGuildHall(Character $character, ?Guild $guild = null): bool
    {
        // Handed in where the caller already has it: the player state asks both
        // questions at once, and looking the membership up twice for one
        // response is a query nobody needs.
        $guild ??= $this->guildOf($character);

        return $guild !== null
            && ! $this->isTraveling($character)
            && (int) $character->col === $guild->col
            && (int) $character->row === $guild->row;
    }

    /**
     * §9.5.4 -- what this character is worth in a fight, right now.
     *
     * Flat attack and defense off the gear and the battle job, and the pool
     * their durability adds up to. Not the percentage stats of the same name:
     * `power` and `defense` multiply this and are on `bonuses` with everything
     * else that is a percentage.
     *
     * @return array<string,int|string|null>
     */
    private function combatPayload(Character $character): array
    {
        $items = $this->itemRows($character);
        $job = $this->battleJobLevel($character);
        // §7.4 -- scoped by the family in the slot, so the sheet says what the
        // kit you are actually carrying is worth.
        $bonuses = $this->bonuses($character, 'battle', $job['family']);
        $tree = $this->battleTree($character, $job['family']);

        $pair = Formulas::combatPair(
            $items,
            $job['level'],
            $bonuses['power'],
            $bonuses['defense'],
            $tree['attack'],
            $tree['defense'],
        );

        return [
            'attack' => $pair['attack'],
            'defense' => $pair['defense'],
            'pool' => Formulas::battlePool($items),
            'job' => $job['job'],
            'jobLevel' => $job['level'],
        ];
    }

    /**
     * §10.0.2 -- the summary, plus the one number only your own guild may tell
     * you: how many are waiting at the door. It rides the state so the corner
     * cell can go green over it, and it is nil for anybody but an officer.
     *
     * @return array<string,mixed>
     */
    private function stateGuildPayload(Character $character, ?Guild $guild): ?array
    {
        $payload = $this->guildPayload($guild, false, true);
        if ($payload === null) {
            return null;
        }

        $role = GuildMember::where('guild_id', $guild->id)
            ->where('character_id', $character->id)
            ->value('role');

        $officer = in_array($role, [GuildMember::OWNER, GuildMember::OFFICER], true);

        return $payload + [
            'pending' => $officer
                ? GuildApplication::where('guild_id', $guild->id)->count()
                : 0,
        ];
    }

    /**
     * @param  bool  $own  §10.4 -- the treasury and its prices, which are the
     *                     guild's own business. A pot whose size a rival can
     *                     read is a pot that can be outbid to the coin, which
     *                     is the whole reason donations are non-retractable.
     * @return array<string,mixed>
     */
    public function guildPayload(?Guild $guild, bool $withMembers = false, bool $own = false): ?array
    {
        if ($guild === null) {
            return null;
        }

        $payload = [
            'id' => (string) $guild->id,
            'name' => $guild->name,
            'code' => $guild->code,
            'description' => $guild->description,
            'flag' => $guild->flag,
            'settlementId' => $guild->settlement_id,
            'settlementName' => WorldGen::settlementById($guild->settlement_id)['name'] ?? null,
            'col' => $guild->col,
            'row' => $guild->row,
            // §10.0.1 -- on the summary, because it is the difference between
            // "Join" and "Ask" on a button nobody has pressed yet, and finding
            // out afterwards is a worse answer.
            'recruitment' => $guild->recruitment,
            'members' => $guild->members_count ?? $guild->members()->count(),
            // §10.5 -- the facilities are public, and meant to be: a bench that
            // reaches legendary is the best recruiting line a guild has.
            'hallLevel' => (int) $guild->hall_level,
            'benchLevel' => (int) $guild->bench_level,
            'benchReach' => $this->guildBenchReach($guild),
            'rosterCap' => $this->guildRosterCap($guild),
        ];

        if ($own) {
            $payload += [
                'gold' => (int) $guild->gold,
                'benchMaxLevel' => $this->guildBenchMaxLevel($guild),
                'hallCost' => $this->guildFacilityNextCost($guild, 'hall'),
                'benchCost' => $this->guildFacilityNextCost($guild, 'bench'),
            ];
        }

        if (! $withMembers) {
            return $payload;
        }

        return $payload + [
            'applications' => $this->guildApplications($guild),
            'roster' => $guild->members()
                ->with('character')
                ->get()
                ->map(fn (GuildMember $m) => [
                    'characterId' => (string) $m->character_id,
                    'name' => $m->character?->name ?? 'Prospector',
                    'level' => (int) ($m->character?->level ?? 1),
                    'role' => $m->role,
                    'joinedAt' => $m->joined_at,
                    // §10.2 -- by contribution, never equal share. This is the
                    // number that says who carried the hall.
                    'donated' => (int) $m->donated,
                ])
                ->all(),
        ];
    }

    private function cleanGuildName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $length = mb_strlen($name);

        if ($length < Balance::GUILD_NAME_MIN || $length > Balance::GUILD_NAME_MAX) {
            throw new GameException(
                'A guild name is between '.Balance::GUILD_NAME_MIN.' and '.Balance::GUILD_NAME_MAX.' characters.',
                'invalid',
            );
        }

        return $name;
    }

    private function cleanGuildCode(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9]{'.Balance::GUILD_CODE_MIN.','.Balance::GUILD_CODE_MAX.'}$/', $code) !== 1) {
            throw new GameException(
                'A code is '.Balance::GUILD_CODE_MIN.' to '.Balance::GUILD_CODE_MAX.' letters or digits.',
                'invalid',
            );
        }

        return $code;
    }

    private function cleanGuildDescription(string $text): string
    {
        return mb_substr(trim($text), 0, Balance::GUILD_DESCRIPTION_MAX);
    }

    /**
     * §10.0.3 -- exactly 1024 colors, and the column can hold nothing else.
     *
     * base64 of 3072 raw RGB bytes. Not a data URI, not a file, not a URL: a
     * flag is the one piece of player-drawn content this game carries, and what
     * makes it safe to carry is that its shape is the only shape it can have.
     */
    private function cleanGuildFlag(mixed $flag): ?string
    {
        if ($flag === null || $flag === '') {
            return null;
        }

        if (! is_string($flag)) {
            throw new GameException('That is not a flag.', 'invalid');
        }

        $raw = base64_decode($flag, true);

        if ($raw === false || strlen($raw) !== Balance::GUILD_FLAG_BYTES) {
            $size = Balance::GUILD_FLAG_SIZE;

            throw new GameException("A flag is {$size} by {$size} dots and nothing else.", 'invalid');
        }

        // Re-encoded rather than stored as sent, so whatever comes back out is
        // canonical base64 of exactly those bytes.
        return base64_encode($raw);
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
     * §6.1 + §8.4 -- two queues at one settlement, counted separately.
     *
     * Five open slots per processing feature and five at the benches, both
     * first-come-first-served and both shared by every player. Real congestion,
     * counted from real jobs.
     *
     * Two banks rather than one because they are two buildings: a saw pit and
     * an anvil are not the same queue, and while both were counted off
     * PUBLIC_SLOTS a settlement running four crafts refused a run of planks.
     */
    public function station(Character $character, string $settlementId): array
    {
        $settlement = $this->settlement($settlementId);

        $jobs = GameJob::where('settlement_id', $settlementId)
            ->where('status', 'active')
            ->orderBy('started_at')
            ->get();

        // §6.3 -- and what YOU may have going here, per line, so the panel can
        // refuse before the materials are spent rather than after. The public
        // slots above are everybody's congestion; this is your own allowance,
        // and the two refuse for different reasons.
        $runs = [];
        foreach ($settlement['lines'] as $line) {
            $runs[$line] = $this->runsFor($character, $settlementId, $line);
        }

        return [
            'settlement' => $settlement,
            'slots' => $this->queueSlots($character, $jobs, 'processing', Balance::PUBLIC_SLOTS),
            'bench' => $this->queueSlots($character, $jobs, 'craft', Balance::BENCH_SLOTS),
            'presence' => $character->presence_settlement_id === $settlementId,
            'runs' => $runs,
            // §6.1 + §8.4 -- the ceiling across the whole map, so the panel can
            // say "you have ten lots of work out" rather than only "not here".
            'outstanding' => $this->outstandingWork($character),
            'outstandingCap' => Balance::OUTSTANDING_WORK_CAP,
        ];
    }

    /**
     * One bank of slots: the jobs of that kind, oldest first, then the gaps.
     *
     * @param  Collection<int,GameJob>  $jobs
     * @return list<array<string,mixed>>
     */
    private function queueSlots(Character $character, $jobs, string $kind, int $size): array
    {
        $slots = [];

        foreach ($jobs->where('kind', $kind)->take($size)->values() as $index => $job) {
            $mine = $job->character_id === $character->id;
            $slots[] = [
                'index' => $index,
                'job' => $mine ? $this->jobPayload($job) : null,
                'owner' => $mine ? 'you' : 'another player',
            ];
        }

        for ($i = count($slots); $i < $size; $i++) {
            $slots[] = ['index' => $i, 'job' => null, 'owner' => null];
        }

        return $slots;
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
            if ($this->isTraveling($character) || $character->col !== $settlement['col'] || $character->row !== $settlement['row']) {
                throw new GameException('You have to be at the settlement.', 'not_present');
            }

            // §7.4.3 -- one run of this line at THIS settlement, plus whatever
            // the line's own tree has bought. A `runSlot` node is the capability
            // a processing tree ends on: the reeve who keeps a second pit going
            // while they work the first.
            //
            // Per settlement and per line, not per character. It was per
            // character across the whole map, which meant a run of planks left
            // at a village four days away closed every saw pit in the world --
            // and §8.4 was arguing in the same breath that the real limit on
            // how much you have going at once is the walking. The walking is
            // the limit now, up to Balance::OUTSTANDING_WORK_CAP.
            $runs = $this->runsFor($character, $settlementId, $recipe['skill']);

            if ($runs['going'] >= $runs['allowed']) {
                $line = Catalog::skills()[$recipe['skill']]['name'] ?? $recipe['skill'];
                throw new GameException(
                    $runs['allowed'] === 1
                        ? "You already have {$line} going at {$settlement['name']}. Collect it before starting another."
                        : "You already have {$runs['going']} {$line} runs going at {$settlement['name']}.",
                    'busy',
                );
            }

            $this->requireWorkRoom($character);

            // §6.1 -- the processing bank only. A craft on the anvil is not a
            // slot at the saw pit (§8.4).
            $busy = GameJob::where('settlement_id', $settlementId)
                ->where('status', 'active')
                ->where('kind', 'processing')
                ->count();
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
            $effects = $this->jobEffects($character, $this->jobForLine($line));

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
            $presenceBonus = $this->presenceBonus($character, $line);
            $seconds = Formulas::processingTime(
                $recipe['baseSeconds'] * $count,
                $settlement['tier'],
                $presence,
                // §8.5 -- named, for the same reason travel is. The line comes
                // with it because a processing run has both: this is the action
                // `processing` on the line the recipe belongs to.
                $this->bonuses($character, 'processing', $line)['processingSpeed'],
                $presenceBonus,
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
                'payload' => $presence ? ['presenceBonus' => $presenceBonus] : null,
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
    /**
     * §6.2 + §7.4.3 -- what standing at the bench is worth on this line.
     *
     * The flat bonus every prospector gets, plus whatever that line's own
     * processing tree has bought. It is the one effect a processing node has
     * that is only felt while the player is actually there, which is the whole
     * of §6.2's idle-safe argument: presence produces nothing by itself.
     */
    private function presenceBonus(Character $character, ?string $line): float
    {
        $job = $line === null ? null : $this->jobForLine($line);

        return Balance::PRESENCE_SPEED_BONUS + (float) $this->jobEffects($character, $job)['presence'];
    }

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
            // §7.4.3 -- the bonus that was applied is stored with the run, so
            // leaving gives back exactly what standing there bought. Recomputing
            // it on the way out would let a node bought mid-run change the
            // arithmetic of a run it was not part of.
            $bonus = $this->presenceBonus($character, $job->skill_key);
            $job->presence = true;
            $job->payload = array_merge($job->payload ?? [], ['presenceBonus' => $bonus]);
            $remaining = $job->ends_at - $now;
            if ($remaining > 0) {
                $job->ends_at = $now + (int) round($remaining * (1 - $bonus));
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
            $bonus = (float) ($job->payload['presenceBonus'] ?? Balance::PRESENCE_SPEED_BONUS);
            $job->presence = false;
            $remaining = $job->ends_at - $now;
            if ($remaining > 0) {
                $job->ends_at = $now + (int) round($remaining / (1 - $bonus));
            }
            $job->save();
        }
    }

    public function travelTo(Character $character, int $col, int $row): array
    {
        // A mine pins you to the hex you are working. Dropping it is the way out,
        // and it forfeits the haul (§11.1) -- say so, or the lock reads as a bug.
        $mine = $this->miningTrip($character);
        if ($mine !== null) {
            throw new GameException(
                $mine->isReady($this->now())
                    ? 'Claim your reward before you move on.'
                    : 'You are working this hex. Claim it when it finishes, or drop it.',
                'working',
            );
        }

        if ($this->isTraveling($character)) {
            throw new GameException(
                'You are already on the road. Stop where you are before setting a new course.',
                'traveling',
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
        $character->travel_scanned_hexes = 0;
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
        if (! $this->isTraveling($character)) {
            throw new GameException('You are not going anywhere.', 'not_traveling');
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
    public function isTraveling(Character $character): bool
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
        if (! $this->isTraveling($character)) {
            return null;
        }

        $path = $this->travelPath($character);
        $settlement = WorldGen::settlementAt((int) $character->travel_to_col, (int) $character->travel_to_row);
        $perHex = $this->journeyPerHex($character);
        $started = (int) $character->travel_started_at;
        $hexes = count($path) - 1;

        // §9.5.3 -- where the road ACTUALLY ends, which is not always where it
        // was pointed. A pack ahead stops the journey on its hex, and the
        // client has no other way to know: it was counting down to the
        // destination and landing there, and the correction only arrived on the
        // refresh that followed -- so the walker visibly reached the village
        // and then snapped back down the road. A fast game clock made it
        // obvious rather than causing it.
        //
        // Scanned from the high-water mark rather than from zero: settle() has
        // just walked everything up to here and found nothing, so re-testing it
        // would be the same answer at the same cost.
        $met = Balance::packsEnabled()
            ? $this->packOnRoad($path, $started, $perHex, (int) $character->travel_scanned_hexes + 1, $hexes)
            : null;

        return [
            'toCol' => (int) $character->travel_to_col,
            'toRow' => (int) $character->travel_to_row,
            'startedAt' => (int) $character->travel_started_at,
            'endsAt' => (int) $character->travel_ends_at,
            'perHexMs' => $perHex,
            'hexes' => $hexes,
            'path' => array_map(fn (array $hex) => [$hex['col'], $hex['row']], $path),
            'destinationName' => $settlement['name'] ?? null,
            // A prediction, not a promise: the server still re-decides on the
            // next read (§16), and whoever clears that pack first moves the
            // answer further down the road. Being wrong is self-correcting --
            // the client asks early, is told it is still walking, and is handed
            // the new stop.
            'stopHex' => $met['index'] ?? $hexes,
            'stopCol' => $met['col'] ?? (int) $character->travel_to_col,
            'stopRow' => $met['row'] ?? (int) $character->travel_to_row,
            'stopAt' => $met['at'] ?? (int) $character->travel_ends_at,
            // §5.6 -- what stops you is NOT named. Sight on the road is zero,
            // and a journey that hands over the name of the thing waiting three
            // hexes up it is that rule leaking. The stop itself has to be sent
            // (the marker has to know where to halt, or it visibly arrives and
            // snaps back), but where and when is a fact about the animation;
            // WHO is the surprise, and it stays the server's until you are
            // standing in front of it.
            'blocked' => $met !== null,
        ];
    }

    /**
     * §9.5.3 -- a pack met on the road ends the journey where it stands.
     *
     * "Travel ends at that hex. The rest of the road is not walked." Nothing
     * resumes by itself either: when the hex is free you are standing on it,
     * not at the destination you set out for, and the next move is a decision
     * you make again.
     *
     * LAZY, and it has to be. Simulating a walk hex by hex would mean a job per
     * traveler per ten minutes; instead the road is caught up whenever the
     * character is next read, from the high-water mark to wherever the clock
     * says they have got to. §5.6's promise holds -- a journey of two hundred
     * hexes and one of a single hex still cost the same two requests, because
     * this rides a read that was happening anyway.
     *
     * Each hex is tested at THE TIME YOU WOULD HAVE STEPPED ON IT, not at the
     * time anybody happens to look. Packs are time-bucketed (§9.5.1), so a walk
     * long enough to cross a bucket boundary meets whatever spawned ahead of
     * it, and an hour offline resolves to the same road as an hour watching
     * (§16).
     *
     * The one thing that is not a pure function of the clock is CLEARING, which
     * is shared: a pack somebody else settled before you looked is one you
     * simply walked past. That is the right reading of a shared world rather
     * than a hole in the determinism -- the hazard was gone, and it was gone
     * for everybody.
     */
    private function interceptIfDue(Character $character, int $now): bool
    {
        if (! $this->isTraveling($character) || ! Balance::packsEnabled()) {
            return false;
        }

        $perHex = $this->journeyPerHex($character);
        $path = $this->travelPath($character);
        $started = (int) $character->travel_started_at;

        // Where the clock says they are. The destination hex is included: a
        // pack standing on the far end stops you there just the same, and it is
        // the pin they will find when they look (§9.5.3).
        $reached = min(count($path) - 1, intdiv(max(0, $now - $started), $perHex));
        $scanned = (int) $character->travel_scanned_hexes;

        if ($reached <= $scanned) {
            return false;
        }

        $met = $this->packOnRoad($path, $started, $perHex, $scanned + 1, $reached);

        if ($met !== null) {
            $character->col = $met['col'];
            $character->row = $met['row'];
            $this->clearTravel($character);
            $this->grantExplorerXp($character, $met['index']);

            return true;
        }

        $character->travel_scanned_hexes = $reached;

        return true;
    }

    /**
     * §9.5.3 -- the first hex on this stretch of road that something is standing on.
     *
     * Shared by the two questions that both need it and must never disagree:
     * interceptIfDue() asks about the stretch already WALKED, and travelState()
     * asks about the stretch still AHEAD so the client knows where the road
     * really ends. One scan, one answer.
     *
     * Each hex is tested at the time it would be stepped on, never at the time
     * the question is asked -- so the answer about the road ahead is the same
     * answer that will be given when the walker gets there, and an hour offline
     * resolves to the same road as an hour watching (§16).
     *
     * @param  list<array{col:int,row:int}>  $path
     * @return array{index:int,col:int,row:int,at:int,key:string}|null
     */
    private function packOnRoad(array $path, int $started, int $perHex, int $from, int $to): ?array
    {
        for ($i = max(1, $from); $i <= min(count($path) - 1, $to); $i++) {
            $hex = $path[$i];
            $steppedAt = $started + $i * $perHex;

            $pack = WorldGen::generateTile($hex['col'], $hex['row'], $steppedAt)['pack'] ?? null;
            if ($pack === null) {
                continue;
            }

            // Shared, and the one thing the hash cannot know (§9.5.1). A pack
            // somebody else settled before you got there is one you walk past.
            if (Packs::isCleared($hex['col'], $hex['row'], $pack['bucket'])) {
                continue;
            }

            return [
                'index' => $i,
                'col' => $hex['col'],
                'row' => $hex['row'],
                'at' => $steppedAt,
                'key' => $pack['key'],
            ];
        }

        return null;
    }

    /** Land a journey whose clock has run out. Called from settle, never directly. */
    private function arriveIfDue(Character $character, int $now): bool
    {
        if (! $this->isTraveling($character) || $now < (int) $character->travel_ends_at) {
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
        $character->travel_scanned_hexes = 0;
    }

    // ------------------------------------------------------------- location

    /** The settlement the character is standing on, if any. */
    public function currentSettlement(Character $character): ?array
    {
        // Mid-journey you are between hexes, so you are at nothing: the trader
        // you left is behind you and the one ahead is not in earshot yet.
        if ($this->isTraveling($character)) {
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
                // §8.0.1 -- gold buys a plain item, at every shelf including a
                // capital's. An option is what a BENCH puts on a thing: it is
                // the difference between a piece somebody made and a piece
                // somebody stocked, and a shop that sold rolled goods would
                // make crafting the slower way to buy one.
                'options' => [],
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
            // a settlement, not a traveling buyer.
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
            // quest about the trader is about the rate rather than the mines.
            $this->fireQuest($character, 'sell', $gold);
            $character->save();

            return $gold;
        });
    }

    /**
     * §4.0 -- empty the pack of everything that reaches no tier, in one trade.
     *
     * Tier zero is the whole test, so it takes the five biome scrap and the five
     * junk together. They are two different arguments (§4.0 keeps them apart
     * because one is what a MISSING TOOL costs you and the other is rubbish
     * carried out alongside), but they are one chore: a copper each, wanted by
     * no recipe anywhere, and taking up straps §7.6 charges for.
     *
     * One transaction and one figure rather than ten sales, because the player
     * is doing one thing. Ten calls would also be ten quest fires and ten full
     * state payloads for a decision made once.
     *
     * @return array{gold:int,rows:int,units:int}
     */
    public function sellScrap(Character $character): array
    {
        return DB::transaction(function () use ($character) {
            $this->requireSettlement($character, 'trade');

            $gold = 0;
            $rows = 0;
            $units = 0;

            foreach ($character->materials()->where('quantity', '>', 0)->get() as $stack) {
                $def = Catalog::material($stack->material_key);
                if ($def === null || ($def['tier'] ?? 0) !== 0 || ($def['npcPrice'] ?? 0) <= 0) {
                    continue;
                }

                $count = (int) $stack->quantity;
                $this->takeMaterial($character, $stack->material_key, $count);

                $gold += (int) $def['npcPrice'] * $count;
                $units += $count;
                $rows++;
            }

            if ($rows === 0) {
                throw new GameException('Nothing in the pack the trader would call scrap.', 'not_sellable');
            }

            $character->gold += $gold;
            $this->fireQuest($character, 'sell', $gold);
            $character->save();

            return ['gold' => $gold, 'rows' => $rows, 'units' => $units];
        });
    }

    // ------------------------------------------------------------------- craft

    /** @return CharacterItem|CharacterConsumable a new object, or the grown stack */
    /**
     * §8.4 -- put a thing on a bench. It is not made until it is collected.
     *
     * Everything that can refuse does so HERE, before a single material is
     * spent: the bench's reach, the strap the output will need (§7.6), and the
     * stock itself. What happens later is only the clock.
     *
     * One craft per settlement, and no cap beyond that. A bench is a place, not
     * a queue you can stack five deep -- and since the claim needs you standing
     * where you left it (§8.4), the real limit is how far apart the benches are
     * and how much walking you are prepared to do.
     */
    public function startCraft(Character $character, string $itemKey): GameJob
    {
        return DB::transaction(function () use ($character, $itemKey) {
            $def = Catalog::item($itemKey);
            if ($def === null || ! isset($def['inputs'])) {
                throw new GameException('Not craftable.', 'not_found');
            }

            $here = $this->requireSettlement($character, 'craft');

            // §8.0 -- nothing makes a unique. Asked first because it is the one
            // refusal that is about the item rather than about where you stand.
            if (Balance::stationForRarity($def['rarity']) === null) {
                throw new GameException(
                    "{$def['name']} is never crafted. It only drops.",
                    'station',
                );
            }

            // §8.0 / §10.0 -- the guild hall is not a settlement tier, it is a
            // building a guild put inside one. So it is asked first and asked
            // differently: not "is this place big enough" but "is this YOUR
            // hall". Members only, at their own -- a hall open to passers-by
            // would be a public good rather than a reason to join.
            $guild = $this->guildOf($character);
            $atOwnHall = $this->atOwnGuildHall($character, $guild);

            $needsHall = ($def['station'] ?? null) === 'guild'
                || Balance::rarityRank($def['rarity']) >= Balance::rarityRank('legendary');

            if ($needsHall && ! $atOwnHall) {
                throw new GameException(
                    $guild === null
                        ? "{$def['name']} is guild work. You would need a guild, and a hall to make it in."
                        : "{$def['name']} is made at {$guild->name}'s own hall.",
                    'station',
                );
            }

            // §10.5 -- and then the reach, which at your own hall is your own
            // guild's rather than the settlement's. A hall is built out one rung
            // at a time from whatever the ground underneath it already reached,
            // so a guild in a city climbs three levels to legendary and one in a
            // capital climbs one.
            $reach = $atOwnHall && $guild !== null
                ? $this->guildBenchReach($guild)
                : (Balance::STATION_RARITY_CAP[$here['tier']] ?? 'common');

            if (Balance::rarityRank($def['rarity']) > Balance::rarityRank($reach)) {
                // §8.0 -- checked against rarity rather than the item's own
                // `station`. Rarity goes first because it can say *why*, and
                // because it still holds when somebody adds a recipe and forgets
                // to set a station.
                throw new GameException(
                    $atOwnHall && $guild !== null
                        ? "{$guild->name}'s bench reaches {$reach}. {$def['rarity']} work needs it built out further."
                        : "A {$here['tier']} bench cannot make {$def['rarity']} work. That needs a "
                            .Balance::stationForRarity($def['rarity']).'.',
                    'station',
                );
            }

            if (isset($def['station']) && $def['station'] !== 'guild') {
                $this->requireSettlement($character, 'craft', $def['station']);
            }

            if ($this->craftJobAt($character, $here['id']) !== null) {
                throw new GameException(
                    "You already have something on the bench at {$here['name']}.",
                    'busy',
                );
            }

            // §6.1 + §8.4 -- and the ceiling on how much may be out anywhere.
            // A craft and a processing run count against one number, because to
            // the player they are one thing: something left behind that has to
            // be walked back to.
            $this->requireWorkRoom($character);

            // §8.4 + §6.1 -- the benches queue like the lines do: five slots,
            // first-come-first-served, shared by everybody standing here. It is
            // their own bank, so a busy saw pit never closes the forge.
            $onBenches = GameJob::where('settlement_id', $here['id'])
                ->where('status', 'active')
                ->where('kind', 'craft')
                ->count();

            if ($onBenches >= Balance::BENCH_SLOTS) {
                throw new GameException(
                    "Every bench at {$here['name']} is busy. Wait, or try another settlement.",
                    'queue_full',
                );
            }

            // §7.6 -- a potion joins a shelf it may already have; anything with
            // a slot is a new row every time, because gear does not stack. Asked
            // now AND again on collection: an hour is long enough to fill a bag.
            if (empty($def['consumable']) || $this->heldConsumable($character, $itemKey) <= 0) {
                $this->requireFreeRow($character, $def['name']);
            }

            // §7.4.3 -- a Smith's cheaper crafts do not make an Armorer's
            // cheaper, so the discount is read from the job whose bench this is.
            // Never below one of anything: a free craft is not a discount, it is
            // a hole in the §11 materials sink.
            $effects = $this->jobEffects($character, $this->jobForItem($def));
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

            $now = $this->now();
            $presence = $character->presence_settlement_id === $here['id'];

            // The same clock processing runs on (§6): the bench's tier, whether
            // you are standing over it, and whatever the gloves are worth. One
            // model for both, because they are the same building.
            $seconds = Formulas::processingTime(
                Balance::CRAFT_BASE_SECONDS[$def['rarity']] ?? Balance::CRAFT_BASE_SECONDS['common'],
                $here['tier'],
                $presence,
                // §7.4 -- the bench category, so a Smith's tree speeds the
                // weapon bench and leaves the tannery alone.
                $this->bonuses($character, 'processing', Catalog::category($def))['processingSpeed'],
            );

            return GameJob::create([
                'character_id' => $character->id,
                'kind' => 'craft',
                'status' => 'active',
                'settlement_id' => $here['id'],
                'output_key' => $itemKey,
                'presence' => $presence,
                'quantity' => 1,
                // §8.4 -- the bench, not a gathering line: a craft belongs to
                // the smith, the armorer or the alchemist, and that is the job
                // it will teach when it comes off (§7.4).
                'skill_key' => $this->jobForItem($def) ?? 'smith',
                'started_at' => $now,
                'ends_at' => $now + Balance::scaled($seconds * 1000),
            ]);
        });
    }

    /**
     * §6.1 + §8.4 -- how much unclaimed work this character has out, everywhere.
     *
     * Processing runs and bench crafts together, because they are the same
     * thing to a player: something left in a building that has to be walked
     * back to. A mine, a hunt and a fight are not counted -- those are on your
     * own body and are already one at a time.
     */
    public function outstandingWork(Character $character): int
    {
        return $character->jobs()
            ->whereIn('kind', ['processing', 'craft'])
            ->count();
    }

    /**
     * §6.3 -- how many runs of one line this character may keep going at ONE
     * settlement, and how many are going there now.
     *
     * Per settlement and per line, which is what makes `runSlot` mean the
     * sentence §6.3 writes: the reeve who keeps a second pit going has earned it
     * on that line and on no other. A capital running all five lines therefore
     * lets one prospector have five runs in it, one to a bench, which is most of
     * what a capital is for (§6).
     *
     * @return array{going:int,allowed:int}
     */
    public function runsFor(Character $character, string $settlementId, string $line): array
    {
        return [
            'going' => $character->jobs()
                ->where('kind', 'processing')
                ->where('settlement_id', $settlementId)
                ->where('skill_key', $line)
                ->count(),
            'allowed' => 1 + (int) $this->jobEffects($character, $this->jobForLine($line))['runSlot'],
        ];
    }

    /**
     * §6.1 + §8.4 -- the one refusal both banks share.
     *
     * Said before anything is spent, like every other refusal at a bench, and
     * said with the number in it: "ten" is actionable where "too much work out"
     * is a shrug.
     */
    private function requireWorkRoom(Character $character): void
    {
        $out = $this->outstandingWork($character);

        if ($out >= Balance::OUTSTANDING_WORK_CAP) {
            throw new GameException(
                "You have {$out} lots of work out already. Collect one before leaving another behind.",
                'busy',
            );
        }
    }

    /** §8.4 -- what this character has on the bench at one settlement. */
    public function craftJobAt(Character $character, string $settlementId): ?GameJob
    {
        return $character->jobs()
            ->where('kind', 'craft')
            ->where('settlement_id', $settlementId)
            ->first();
    }

    /**
     * §8.4 -- take the finished thing off the bench.
     *
     * The rolls happen HERE rather than when the work started: an option is a
     * property of the thing that came out, and reading the tree at the moment
     * it is handed over means a node bought while it cooled still counts.
     */
    private function finishCraft(Character $character, GameJob $job): array
    {
        $itemKey = (string) $job->output_key;
        $def = Catalog::item($itemKey);

        if ($def === null) {
            throw new GameException('Whatever this was, it is not in the catalog any more.', 'not_found');
        }

        // §7.6 -- asked again, because the bag it has to land in is an hour
        // older than the one that was checked when the work started. Refused
        // rather than dropped: the thing is finished and waiting, and the way
        // out is a strap, which is always in reach.
        if (empty($def['consumable']) || $this->heldConsumable($character, $itemKey) <= 0) {
            $this->requireFreeRow($character, $def['name']);
        }

        // §12 -- the item and its bench, so a quest may name either. Fired on
        // collection: a craft walked away from made nothing.
        $this->fireQuest($character, 'craft', 1, $itemKey);
        $this->fireQuest($character, 'craft', 1, Catalog::category($def));
        $character->save();

        // §7.4 -- the bench that made it is the job that learns from it, and
        // a better piece teaches more: common 10 through epic 40.
        // §7.4 -- the bench that made it is the job that learns from it, and a
        // better piece teaches more: common 10 through epic 40. What was
        // granted is carried back so the receipt can say so; it was being
        // awarded silently, which made the plate report a flat zero for work
        // that had just levelled a trade.
        $jobKey = $this->jobForItem($def);
        $jobXp = $jobKey === null ? 0 : $this->grantJobXp(
            $character,
            $jobKey,
            Balance::JOB_XP_PER_RARITY_RANK * (Balance::rarityRank($def['rarity']) + 1),
        );

        $effects = $this->jobEffects($character, $jobKey);

        // §8.5 -- a potion stacks on a shelf. It has no durability to track
        // and no slot to sit in, so it never becomes a CharacterItem.
        if (! empty($def['consumable'])) {
            $row = CharacterConsumable::firstOrNew([
                'character_id' => $character->id,
                'item_key' => $itemKey,
            ]);

            // §7.4.3 -- the three things a consumable bench owns. A potion has
            // no durability and no rolled line, so an Alchemist's tree deals in
            // how many come off the rack and how many the shelf holds instead.
            $cap = Balance::CONSUMABLE_STACK_CAP + (int) $effects['stackCap'];
            $made = 1
                + (int) $effects['batch']
                + $this->extraRoll($character, (float) $effects['brewExtra'], 0xB2E3);

            if ($row->quantity >= $cap) {
                throw new GameException(
                    "You cannot carry more than {$row->quantity} {$def['name']}.",
                    'at_cap',
                );
            }

            $made = min($made, $cap - (int) $row->quantity);
            $row->quantity = (int) $row->quantity + $made;
            $row->save();

            return [
                'made' => [
                    'key' => $itemKey,
                    'name' => $def['name'],
                    'consumable' => true,
                    'quantity' => $made,
                ],
                'job' => $jobKey,
                'jobXp' => $jobXp,
            ];
        }

        // §7.4.3 -- a better-made thing lasts longer. Capped, because
        // durability is the repair sink and this thins it.
        $durability = (int) round($def['maxDurability'] * (1 + $effects['craftDurability']));

        $item = CharacterItem::create([
            'character_id' => $character->id,
            'item_key' => $itemKey,
            'durability' => $durability,
            'equipped' => false,
            'options' => $this->rollFor(
                $character,
                $def,
                $this->extraRoll($character, $effects['craftOption'], 0x5C11),
                (float) $effects['optionTier'],
            ),
        ]);

        return [
            'made' => [
                'key' => $itemKey,
                'name' => $def['name'],
                'consumable' => false,
                'itemId' => (string) $item->id,
                'durability' => $durability,
                'options' => $item->options ?? [],
            ],
            'job' => $jobKey,
            'jobXp' => $jobXp,
        ];
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

        // §7.5 -- the wayfaring tree is free, so its rows are not spending.
        // Counting every row was safe while those nodes were never written
        // down; now that they are claimed, the ledger has to tell the two kinds
        // apart or a long walk would quietly eat the hundred-point cap.
        $spent = $character->nodes()
            ->pluck('node_key')
            ->reject(fn (string $key) => Jobs::isAutomatic(Jobs::node($key)['job'] ?? ''))
            ->count();

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
     * the same number that takes time off a mine (§7.3). Reusing it means a
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
     * One kind is deliberately unreachable from here: gathering jobs read their
     * CharacterSkill level instead (§7.2), so writing a row for one would
     * create a second opinion about a number that already exists.
     *
     * Battle jobs used to sit beside them, because nothing in the game fought
     * anything. §9.5 is what changed that -- they level on the road now, on a
     * win and on nothing else (§9.5.3), and which of the three earns it is
     * decided by the weapon family in the slot (§9.5.4). The rule they were
     * fenced off for still holds: no gathering or bench work may ever reach
     * them, or combat becomes optional.
     */
    private function grantJobXp(Character $character, string $jobKey, int $amount): int
    {
        $def = Jobs::JOBS[$jobKey] ?? null;
        if ($def === null || $amount <= 0) {
            return 0;
        }
        if ($def['kind'] === Jobs::GATHERING) {
            return 0;
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

        return $amount;
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
     * §7.4 -- which bucket a job's `stat` nodes are locked to, or null when the
     * job has no work of its own to lock them to.
     *
     * The key is the same shape `bonuses()` builds when it is asked about an
     * action: the action, and the thing under it when there is one.
     *
     *   gathering    woodcutting        the mine, on that line
     *   processing   processing:wood…   the run, at that line's bench
     *   craft        processing:weapon  the craft clock, at that bench only
     *   battle       battle:sword       the fight, and only with that in hand
     *
     * Craft files under `processing` because a bench clock IS the processing
     * clock (§8.4) -- the two are the same building and the same stat. The
     * three categories cannot collide with the five lines.
     */
    private function nodeBucket(string $job): ?string
    {
        $source = Jobs::JOBS[$job]['source'] ?? null;

        return match (Jobs::JOBS[$job]['kind']) {
            Jobs::GATHERING => $source,
            Jobs::PROCESSING, Jobs::CRAFT => Jobs::PROCESSING.':'.$source,
            // §9.5.4 -- the WEAPON FAMILY, not the job's role word. A
            // Swordhand's nodes are worth nothing while a shield is in the
            // slot: the family you carry is your class, and a tree bought for
            // one of them must not pay out through another.
            Jobs::BATTLE => 'battle:'.(
                array_search($job, Catalog::BATTLE_JOB_FOR_FAMILY, true) ?: $source
            ),
            default => null,
        };
    }

    /**
     * §7.4.3 -- what this character's bought nodes add up to.
     *
     * Returned as a bundle rather than applied here, because the pieces land in
     * different places: `stat` joins the gear aggregate inside its clamp, the
     * rest apply at the craft site. Each non-stat total is clamped to its own
     * cap, which is what keeps a maxed tree from switching off a §11 sink.
     *
     * @return array{stats:array<string,float>,byJob:array<string,array<string,float>>,sight:int,bagUnits:int,bagRows:int}
     */
    public function nodeEffects(Character $character): array
    {
        return $this->effectsOf($this->ownedNodes($character));
    }

    /**
     * The same aggregate, from a bare list of node keys.
     *
     * Split out for the battle bench (BattleSimController), which has no
     * character to own anything and still has to answer "what would THIS set of
     * nodes be worth". Everything the caps and the bucketing do is here rather
     * than in nodeEffects(), so the bench and the game cannot come to different
     * conclusions about the same six nodes.
     *
     * @param  list<string>  $keys
     */
    public function effectsOf(array $keys): array
    {
        $stats = [];
        $byLine = [];
        $byJob = [];
        $pair = [];
        $battleWear = [];
        $sight = 0;
        $bagUnits = 0;
        $bagRows = 0;

        foreach ($keys as $key) {
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
                    // gathering trees must not stack yield on every mine.
                    //
                    // §6 -- a processing node is line-locked by the same rule
                    // and for the same reason: a Sawyer is faster at a saw pit,
                    // not at a tannery. It files under `processing:<line>`
                    // rather than the bare line, because a saw pit and a forest
                    // are two different pieces of work on the same word --
                    // filing both under `woodcutting` would pay a Sawyer out on
                    // a felling mine.
                    // §7.4 -- EVERY stat node is locked to the work its job is
                    // about. A tree makes you better at its own class and at
                    // nothing else; the alternative is a character taking three
                    // trees and stacking all of them on one mine.
                    //
                    // The bucket is the ACTION and the thing under it, because
                    // a forest and a saw pit are two different pieces of work
                    // on the same word: filing both under `woodcutting` would
                    // pay a Sawyer out on a felling mine.
                    $bucket = $this->nodeBucket($job);
                    if ($bucket !== null) {
                        $byLine[$bucket][$effect['stat']] =
                            ($byLine[$bucket][$effect['stat']] ?? 0) + $effect['value'];
                        break;
                    }

                    $stats[$effect['stat']] = ($stats[$effect['stat']] ?? 0) + $effect['value'];
                    break;
                case 'pair':
                    // §9.5.4 -- solid numbers, so they add and no percentage
                    // ceiling applies. Bucketed by the weapon family like every
                    // other battle effect: a Swordhand's nodes are worth
                    // nothing with a shield on the arm.
                    $bucket = $this->nodeBucket($job) ?? 'battle';
                    $pair[$bucket][$effect['stat']] =
                        ($pair[$bucket][$effect['stat']] ?? 0) + (int) $effect['value'];
                    break;
                case 'battleWear':
                    // §9.5.6 -- what a fight takes off the kit, spared. The one
                    // effect a battle tree has that is felt every single time.
                    $bucket = $this->nodeBucket($job) ?? 'battle';
                    $battleWear[$bucket] = ($battleWear[$bucket] ?? 0) + $effect['value'];
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
            'optionTier' => Balance::SKILL_OPTION_TIER_CAP,
            'brewExtra' => Balance::SKILL_BREW_EXTRA_CAP,
            'stackCap' => Balance::SKILL_STACK_CAP,
            'batch' => Balance::SKILL_BATCH_CAP,
            'toolWear' => Balance::SKILL_TOOL_WEAR_CAP,
            'bite' => Balance::SKILL_BITE_CAP,
            'seamGrade' => Balance::SKILL_SEAM_GRADE_CAP,
            'presence' => Balance::SKILL_PRESENCE_CAP,
            'runSlot' => Balance::SKILL_RUN_SLOT_CAP,
            'weaponWear' => Balance::SKILL_WEAPON_WEAR_CAP,
            'goldFind' => Balance::SKILL_GOLD_FIND_CAP,
            'lootOption' => Balance::SKILL_LOOT_OPTION_CAP,
            'skillPower' => Balance::SKILL_BATTLE_POWER_CAP,
            'skillCooldown' => Balance::SKILL_BATTLE_COOLDOWN_CAP,
            'skillStun' => Balance::SKILL_BATTLE_STUN_CAP,
        ];
        foreach ($byJob as $job => $kinds) {
            foreach ($kinds as $kind => $value) {
                $byJob[$job][$kind] = min($value, $caps[$kind] ?? $value);
            }
        }

        foreach ($pair as $bucket => $stats2) {
            foreach ($stats2 as $stat => $value) {
                $pair[$bucket][$stat] = min($value, Balance::SKILL_PAIR_CAP);
            }
        }

        foreach ($battleWear as $bucket => $value) {
            $battleWear[$bucket] = min($value, Balance::SKILL_BATTLE_WEAR_CAP);
        }

        return [
            'stats' => $stats,
            'byLine' => $byLine,
            'byJob' => $byJob,
            'pair' => $pair,
            'battleWear' => $battleWear,
            'sight' => min($sight, Balance::SKILL_SIGHT_CAP),
            'bagUnits' => min($bagUnits, Balance::SKILL_BAG_UNITS_CAP),
            'bagRows' => min($bagRows, Balance::SKILL_BAG_ROWS_CAP),
        ];
    }

    /**
     * §7.4 + §7.5 -- every node this character has, and there is one place to
     * look.
     *
     * Wayfaring nodes used to be derived from the job level instead of stored,
     * so that a free skill could never cost a point by accident. They are
     * claimed now (see buyNode), which makes them rows like everything else --
     * and the point ledger stays honest by asking what KIND a row is rather
     * than by keeping some rows out of the table.
     *
     * @return array<int,string>
     */
    public function ownedNodes(Character $character): array
    {
        return $character->nodes()->pluck('node_key')->all();
    }

    /** One job's capped non-stat effects, or zeroes. */
    private function jobEffects(Character $character, ?string $jobKey): array
    {
        $zero = [
            'costReduction' => 0.0,
            'craftDurability' => 0.0,
            'craftOption' => 0.0,
            'optionTier' => 0.0,
            'brewExtra' => 0.0,
            'stackCap' => 0.0,
            'batch' => 0.0,
            'toolWear' => 0.0,
            'bite' => 0.0,
            'seamGrade' => 0.0,
            'presence' => 0.0,
            'runSlot' => 0.0,
            'weaponWear' => 0.0,
            'goldFind' => 0.0,
            'lootOption' => 0.0,
            'skillPower' => 0.0,
            'skillCooldown' => 0.0,
            'skillStun' => 0.0,
        ];
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
     * (character, node) is the last line of defense against a doubled request.
     */
    public function buyNode(Character $character, string $nodeKey): array
    {
        return DB::transaction(function () use ($character, $nodeKey) {
            $node = Jobs::node($nodeKey);
            if ($node === null) {
                throw new GameException('No such skill.', 'not_found');
            }

            if ($character->nodes()->where('node_key', $nodeKey)->exists()) {
                throw new GameException("You already have {$node['name']}.", 'owned');
            }

            // §7.5 -- a wayfaring skill is CLAIMED, not bought. The walking is
            // its price and the job level is the receipt, so no point is spent;
            // everything else about taking it is the same as any other node.
            //
            // It used to arrive on its own the moment the level did. Nothing
            // announced it, so the reward for a thousand hexes was a panel that
            // had quietly changed since you last looked at it. Pressing for it
            // is the difference between being paid and finding money.
            if (! Jobs::isAutomatic($node['job'])) {
                $points = $this->skillPoints($character);
                if ($points['available'] < 1) {
                    throw new GameException('No skill points left. Level up first.', 'no_points');
                }
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

    /**
     * §8.2 -- sell a piece of gear back to the trader.
     *
     * The third exit a piece of equipment has, and the three are deliberately
     * different: **repair** keeps it, **salvage** returns a fraction of what
     * went into it, and this returns gold scaled by what is left of it.
     *
     * Four refusals, and each one closes a hole rather than being a nicety:
     *
     *  - Not at a settlement. The trader is an NPC who stands somewhere; there
     *    is nobody in the middle of a forest to sell an axe to (§6).
     *  - Not while it is worn. A sale is a trade, and losing the tool off your
     *    own belt to a mistap is worse than losing one out of the pack. Stow it
     *    first, which is one tap and says what you are about to do.
     *  - Not what the trader does not stock. Gold buys the bottom two rungs and
     *    never the top (§3.2), so a crafted or NFT piece has no shelf price to
     *    halve -- and §8.2 already gives that gear an exit in salvage.
     *  - Not for nothing. A piece worn down past the point where half its price
     *    still rounds to a coin is refused rather than taken for zero.
     *
     * §7.6 -- selling frees a row, and it works from wherever you are standing,
     * which is what makes it one of the ways out of a full bag.
     *
     * @return array{gold:int,name:string}
     */
    public function sellItem(Character $character, int $itemId): array
    {
        return DB::transaction(function () use ($character, $itemId) {
            $item = $this->ownedItem($character, $itemId);
            $def = Catalog::item($item->item_key);

            $this->requireSettlement($character, 'trade');

            if ($item->equipped) {
                throw new GameException(
                    "{$def['name']} is on your belt. Stow it before you sell it.",
                    'equipped',
                );
            }

            $gold = Formulas::resaleValue($def, (int) $item->durability);

            if (($def['goldPrice'] ?? 0) <= 0) {
                throw new GameException(
                    "The trader does not deal in {$def['name']}. Scrap it for materials instead.",
                    'not_sellable',
                );
            }

            if ($gold <= 0) {
                throw new GameException(
                    "{$def['name']} is too far gone to be worth a coin. Repair it, or scrap it for materials.",
                    'worthless',
                );
            }

            $character->gold += $gold;
            $item->delete();
            $character->save();

            return ['gold' => $gold, 'name' => $def['name']];
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

        // §9.5.5 -- a fight is on a hex like a mine, and names what it is
        // swinging at. It also carries the exchange, round by round, because
        // the screen draws the FIGHT rather than a countdown to it.
        //
        // That hands the client the outcome early, and it is fine: the fight is
        // settled the moment you close (§9.5.5) and cannot be abandoned
        // (§9.5.3), so there is no decision left for foreknowledge to spoil.
        // Reading ahead buys a few seconds of knowing and nothing else.
        if ($job->kind === 'battle') {
            return $payload + [
                'col' => $job->col,
                'row' => $job->row,
                'slot' => null,
                'monster' => $job->payload['monster'] ?? null,
                'pool' => $job->payload['pool'] ?? 0,
                'monsterHp' => $job->payload['monsterHp'] ?? 0,
                'roundMs' => Balance::BATTLE_ROUND_MS,
                'log' => $job->payload['log'] ?? [],
                'skills' => $job->payload['skills'] ?? [],
            ];
        }

        if ($job->kind === 'mining' || $job->kind === 'hunting') {
            return $payload + [
                'col' => $job->col,
                'row' => $job->row,
                'slot' => $job->slot,
                'material' => $job->material_key,
            ];
        }

        $bench = $job->settlement_id !== null
            ? $this->settlement((string) $job->settlement_id)
            : null;

        return $payload + [
            'settlementId' => $job->settlement_id,
            'recipeKey' => $job->recipe_key,
            'input' => $job->material_key,
            'output' => $job->output_key,
            'presence' => $job->presence,
            // §8.4 -- where it is waiting, because a claim now needs you
            // standing there and "ready" on its own would be a cruel word.
            'settlementName' => $bench['name'] ?? null,
            'col' => $bench['col'] ?? null,
            'row' => $bench['row'] ?? null,
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
                // §7 -- NULL is unnamed, and the label is applied here, at the
                // one place the client reads it from. Storing the label would
                // make it a name somebody holds (see renameCharacter).
                'name' => $character->name ?? 'Prospector',
                // Whether that is a name or the label standing in for one. The
                // screen offers "Take a name" against one and "Change" against
                // the other, and cannot tell them apart from the string alone.
                'named' => $character->name !== null,
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
            // §10 -- the guild this character belongs to, if any. On the state
            // rather than fetched, because membership decides what a bench will
            // make (§8.0's legendary rung) and the two must never disagree.
            // §9.5.4 -- the flat pair, and the pool that IS the health bar
            // (§9.5.5). On the state rather than only on a fight preview: it is
            // what the kit is worth, and a player should not have to find a
            // pack standing on their hex to be told.
            'combat' => $this->combatPayload($character),
            'guild' => $this->stateGuildPayload($character, $guild = $this->guildOf($character)),
            // §10.0 -- and whether they are standing in their own hall, which
            // is the one question the legendary bench asks.
            'atGuildHall' => $this->atOwnGuildHall($character, $guild),
            // §9.5.7 -- your own corpses, through any fog and at any distance.
            // Here rather than on the map because the split is what makes the
            // two endpoints mean one thing each: this is what is YOURS and is
            // bounded by nothing, the map is what is AROUND you and is bounded
            // by sight (§5.6).
            'carriers' => $this->ownCarriers($character),
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
