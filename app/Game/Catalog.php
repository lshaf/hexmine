<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Static game data, §4 / §6 / §7.2 / §8.3. Port of
 * `frontend/src/game/catalog.ts`.
 *
 * This is deliberately code rather than seeded rows: it is definition, not
 * state. Nothing here ever changes at runtime, a migration to edit a drop table
 * would be absurd, and keeping it in PHP means a balance change is a diff, not a
 * data fix on production.
 */
final class Catalog
{
    /**
     * §5.3 -- the four kinds of ground.
     *
     * Four rather than five because hunting is not worked off ground (§5.5):
     * its line is an animal standing on forest and grassland, so it has a
     * roster and a ladder of its own (Hunts) instead of a country.
     */
    public const BIOMES = ['forest', 'mountain', 'badlands', 'grassland'];

    /**
     * The 20 economy materials of §4, plus the 5 scrap of §4.0.
     * tier 0 scrap (vendor trash) / 1 raw (biome-locked) / 2 refined /
     * 3 rare (capped) / 4 raid.
     *
     * Scrap sits outside the 20 on purpose: it feeds no recipe and reaches no
     * other tier, so it is not part of the economy the §11 sinks balance.
     */
    public static function materials(): array
    {
        static $materials = null;

        return $materials ??= [
            // Tier 0 -- Scrap, §4.0. What bare hands bring back when you have no
            // tool for the line. Sells for a copper, feeds no recipe, and exists
            // only to make the first tool obviously worth buying.
            'branch' => ['name' => 'Branch', 'tier' => 0, 'biome' => 'forest', 'palette' => 'wood', 'npcPrice' => 1, 'description' => 'Snapped off by hand. The trader gives you a copper and looks away.'],
            'ore_chips' => ['name' => 'Ore Chips', 'tier' => 0, 'biome' => 'mountain', 'palette' => 'iron', 'npcPrice' => 1, 'description' => 'Loose flakes off the seam face. Barely worth carrying down.'],
            'torn_hide' => ['name' => 'Torn Hide', 'tier' => 0, 'source' => 'hunt', 'palette' => 'pelt', 'npcPrice' => 1, 'description' => 'Scavenged, not hunted. Half of it is unusable.'],
            'gravel' => ['name' => 'Gravel', 'tier' => 0, 'biome' => 'badlands', 'palette' => 'stone', 'npcPrice' => 1, 'description' => 'Kicked loose from the scree. Nobody dresses this into anything.'],
            'chaff' => ['name' => 'Chaff', 'tier' => 0, 'biome' => 'grassland', 'palette' => 'fiber', 'npcPrice' => 1, 'description' => 'Pulled up by the root and mostly broken. The trader takes it by the sack.'],

            // Tier 1 -- Raw, biome-locked, decays over cap
            'wood' => ['name' => 'Wood', 'tier' => 1, 'biome' => 'forest', 'palette' => 'wood', 'npcPrice' => 2, 'description' => 'Green timber from the forest belt.'],
            'iron_ore' => ['name' => 'Iron Ore', 'tier' => 1, 'biome' => 'mountain', 'palette' => 'iron', 'npcPrice' => 3, 'description' => 'Raw ore hacked from mountain seams.'],
            'stone' => ['name' => 'Stone', 'tier' => 1, 'biome' => 'badlands', 'palette' => 'stone', 'npcPrice' => 2, 'description' => 'Blasted rubble from the badlands.'],
            'fiber' => ['name' => 'Fiber', 'tier' => 1, 'biome' => 'grassland', 'palette' => 'fiber', 'npcPrice' => 2, 'description' => 'Tough grassland stalks, retted for spinning.'],

            // Tier 2 -- Refined
            'planks' => ['name' => 'Planks', 'tier' => 2, 'palette' => 'wood', 'npcPrice' => 7, 'description' => 'Sawn and seasoned. The backbone of crafting.'],
            'ingots' => ['name' => 'Ingots', 'tier' => 2, 'palette' => 'iron', 'npcPrice' => 9, 'description' => 'Smelted iron, poured into bar molds.'],
            'leather' => ['name' => 'Leather', 'tier' => 2, 'palette' => 'pelt', 'npcPrice' => 8, 'description' => 'Tanned hide, supple enough to work.'],
            'cut_stone' => ['name' => 'Cut Stone', 'tier' => 2, 'palette' => 'stone', 'npcPrice' => 7, 'description' => 'Dressed blocks, square and true.'],
            'cloth' => ['name' => 'Cloth', 'tier' => 2, 'palette' => 'fiber', 'npcPrice' => 6, 'description' => 'Spun and woven fiber bolts.'],
            'reinforced_frame' => ['name' => 'Reinforced Frame', 'tier' => 2, 'palette' => 'iron', 'npcPrice' => 26, 'description' => 'Planks banded with iron. A cross-line combo.'],

            // Tier 3 -- Rare, contested ring only, capped per wallet
            'ironwood' => ['name' => 'Ironwood', 'tier' => 3, 'biome' => 'forest', 'palette' => 'wood', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Heartwood so dense it turns an axe. Contested ring only.'],
            'mythril_ore' => ['name' => 'Mythril Ore', 'tier' => 3, 'biome' => 'mountain', 'palette' => 'iron', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'A pale seam that hums under the pick.'],
            'obsidian_shard' => ['name' => 'Obsidian Shard', 'tier' => 3, 'biome' => 'badlands', 'palette' => 'stone', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Volcanic glass, edged sharper than steel.'],
            'silkweave_fiber' => ['name' => 'Silkweave Fiber', 'tier' => 3, 'biome' => 'grassland', 'palette' => 'fiber', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Spun by something in the tall grass. Nobody asks what.'],

            // Tier 4 -- Raid materials
            'essence' => ['name' => 'Essence', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Common residue. Drops from every monster tier.'],
            'shard_verdant' => ['name' => 'Verdant Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Rootvault signature drop.'],
            'shard_ferrous' => ['name' => 'Ferrous Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Deepshaft signature drop.'],
            'shard_sanguine' => ['name' => 'Sanguine Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Beastwarren signature drop.'],
            'shard_cinder' => ['name' => 'Cinder Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Ashpit signature drop.'],
            'shard_zephyr' => ['name' => 'Zephyr Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Windhollow signature drop.'],
            'relic' => ['name' => 'Relic', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Deep-floor rarity. Pity-timer protected.'],
            'core' => ['name' => 'Core', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Boss-only. Gates the best equipment tier.'],
        ]
            // §4 -- the alchemist's raw stock, two per biome so a recipe can
            // want two different things off one kind of ground. Generated, not
            // typed: see scripts/gen_alchemy.py.
            + Alchemy::REAGENTS
            // §4 -- the smith's and the armorer's raw stock, on the same model:
            // two per biome, gathered off a hex, and wanted by the weapon and
            // armor benches the way a reagent is wanted by the consumable one.
            // Generated, not typed: see scripts/gen_components.py.
            + Components::CRAFT
            // §5.3 -- the grades above the base raw and what they refine into.
            // A biome is four kinds of ground, and each kind gives up its own
            // material. Generated, not typed: see scripts/gen_variants.py.
            + Variants::RAW
            + Variants::REFINED
            // §5.5 -- the hunting line's own ladder. Same four rungs as a
            // biome's, off a creature rather than off a country, so it lives
            // with the animal that carries it. See scripts/gen_hunts.py.
            + Hunts::RAW
            + Hunts::REFINED
            // §5.5 -- and the two a kill gives up that are neither hide nor
            // ladder: the part only an uncommon animal or better carries, and
            // the leaving that says where it was standing. Both came off
            // plains ground and had nowhere to go when the country did.
            + Hunts::EXTRA
            // §4 -- the alchemist's second stock: what LIVES on a kind of
            // ground, as against what grows on it. Hunted, never gathered.
            // Generated, not typed: see scripts/gen_critters.py.
            + Critters::STOCK
            // §9.5.8 -- what comes off a monster. Biome-free, dropped by nothing
            // else, and wanted only by the two benches combat feeds.
            // Generated, not typed: see scripts/gen_monsters.py.
            + Spoils::STOCK
            // §4.0 -- junk. Sells for a copper and reaches no tier, exactly as
            // the bare-hands scrap does; it is simply never what a hex gives up.
            + Alchemy::JUNK;
    }

    public static function material(string $key): ?array
    {
        return self::materials()[$key] ?? null;
    }

    public static function materialTier(string $key): int
    {
        return self::materials()[$key]['tier'] ?? 0;
    }

    public static function walletCap(string $key): ?int
    {
        return self::materials()[$key]['walletCap'] ?? null;
    }

    /** Biome -> raw material, §4 tier 1. */
    public const BIOME_MATERIAL = [
        'forest' => 'wood',
        'mountain' => 'iron_ore',
        'badlands' => 'stone',
        'grassland' => 'fiber',
    ];

    /**
     * §8.5 -- the action a charge is waiting on, said as a place in a sentence.
     *
     * "Drank a Forest Draft. It holds until you work a forest hex." A draft
     * is bought for one thing you do, so the message that confirms it has to
     * name that thing rather than a number of minutes it no longer has.
     */
    public const SCOPE_PHRASE = [
        'woodcutting' => 'a forest hex',
        'mining' => 'a mountain hex',
        'hunting' => 'a hunt',
        'quarrying' => 'a badlands hex',
        'harvesting' => 'a grassland hex',
        'travel' => 'the road',
        'processing' => 'a bench',
        'battle' => 'a monster',
        'global' => 'anything at all',
    ];

    /**
     * §4.0/§5.5 -- what a hunt pays somebody with no bow.
     *
     * The hunting line's scrap. It sat in BIOME_SCRAP while the line was worked
     * off plains ground; the line is the animal now, so its scrap is keyed to
     * the hunt rather than to a country.
     */
    public const HUNT_SCRAP = 'torn_hide';

    /**
     * Biome -> scrap, §4.0. What the hex gives up to bare hands: worked without
     * the line's tool, a hex yields this instead of its real material. Same haul
     * size, a fraction of the worth, and no recipe will take it.
     */
    public const BIOME_SCRAP = [
        'forest' => 'branch',
        'mountain' => 'ore_chips',
        'badlands' => 'gravel',
        'grassland' => 'chaff',
    ];

    /** Biome -> rare variant, spawned in the contested inner ring, §5.3. */
    public const BIOME_RARE = [
        'forest' => 'ironwood',
        'mountain' => 'mythril_ore',
        'badlands' => 'obsidian_shard',
        'grassland' => 'silkweave_fiber',
    ];

    /** The five skill lines, §7.2. Ordered -- settlement line picks rely on it. */
    public const SKILLS = ['woodcutting', 'mining', 'hunting', 'quarrying', 'harvesting'];

    public static function skills(): array
    {
        return [
            'woodcutting' => ['name' => 'Woodcutting', 'material' => 'wood', 'rare' => 'ironwood', 'scrap' => 'branch', 'description' => 'Faster mining and better yield in forest hexes.'],
            'mining' => ['name' => 'Mining', 'material' => 'iron_ore', 'rare' => 'mythril_ore', 'scrap' => 'ore_chips', 'description' => 'Faster mining and better yield in mountain hexes.'],
            'hunting' => ['name' => 'Hunting', 'material' => 'pelt', 'rare' => 'beastfang_hide', 'scrap' => 'torn_hide', 'description' => 'Faster hunting and a better haul off what you take.'],
            'quarrying' => ['name' => 'Quarrying', 'material' => 'stone', 'rare' => 'obsidian_shard', 'scrap' => 'gravel', 'description' => 'Faster mining and better yield in the badlands.'],
            'harvesting' => ['name' => 'Harvesting', 'material' => 'fiber', 'rare' => 'silkweave_fiber', 'scrap' => 'chaff', 'description' => 'Faster mining and better yield in grassland hexes.'],
        ];
    }

    public static function skillForMaterial(string $materialKey): string
    {
        // §5.3 -- a grade belongs to the same line its base raw does. Without
        // this the fallback below would credit a hematite haul to woodcutting.
        if (isset(Hunts::SKILL_FOR_MATERIAL[$materialKey])) {
            return Hunts::SKILL_FOR_MATERIAL[$materialKey];
        }

        if (isset(Variants::SKILL_FOR_MATERIAL[$materialKey])) {
            return Variants::SKILL_FOR_MATERIAL[$materialKey];
        }

        foreach (self::skills() as $key => $skill) {
            if (
                $skill['material'] === $materialKey
                || $skill['rare'] === $materialKey
                || $skill['scrap'] === $materialKey
            ) {
                return $key;
            }
        }

        return 'woodcutting';
    }

    /**
     * §8.4 -- the three craft benches.
     *
     * Derived from the slot rather than stored on each item: a thing's category
     * is already implied by where it is worn, and a second field would only be
     * somewhere for the two to disagree. Consumables have no slot at all, which
     * is exactly what makes them the third category.
     */
    public const CATEGORIES = ['weapon', 'armor', 'consumable'];

    public static function categoryForSlot(?string $slot): string
    {
        if ($slot === null) {
            return 'consumable';
        }

        return in_array($slot, ['armor', 'boots', 'gloves'], true) ? 'armor' : 'weapon';
    }

    public static function category(array $def): string
    {
        return self::categoryForSlot($def['slot'] ?? null);
    }

    /**
     * §8.0.1 -- what a rolled line may land on.
     *
     * **A line is a solid number, never a `StatKey` percentage.** A percentage
     * climbs toward §8.1's ceiling, which is one invisible number every line on
     * every piece is already climbing toward, so a good roll and a bad one read
     * the same on the plate. §9.5.4 makes that argument about the percentage
     * twins already; the pool is that argument carried out.
     *
     * Which lines a piece is eligible for is §8's usual question -- what is the
     * piece FOR:
     *
     * - **the pair**, `attack` and `defense`, on everything that fights. A tool
     *   rolls attack alone, and on a tool that is §7.3's MINING attack: it
     *   bites deeper into a hex and is worth nothing in a fight (§8 rule 5). It
     *   never rolls a guard, because there is nothing on a hex for one to keep
     *   off you.
     * - **`durability`** on everything, because whatever a piece is for it is a
     *   thing that wears out (§8.2).
     * - **`haul`** on everything, and §8 rule 5 decides what it means rather
     *   than whether it is allowed. A tool's haul is ITS LINE's haul; a
     *   weapon's haul is THE FIGHT's -- more comes off the pack (§9.5.8). The
     *   rule was never "combat gear has no work", it is that combat gear may
     *   not stand in for a gathering tool, and neither of these does: a sword
     *   is worth nothing down a mine and an axe is worth nothing in a fight,
     *   which is the same sentence pointed both ways.
     * - **`travel`** on boots and nowhere else. A coat does not walk faster.
     * - **`cooldown`** on the weapon and nowhere else. The family in that slot
     *   is what decides which three skills you carry (§9.5.9), so it is the
     *   only piece with any business shortening them.
     *
     * `indestructible` is deliberately NOT here: it is rolled apart from the
     * lines at its own low chance (Balance::OPTION_INDESTRUCTIBLE_CHANCE), so
     * it never takes a slot from them and never dilutes a pool.
     */
    public const OPTION_FLAT_TOOL = ['attack'];

    public const OPTION_FLAT_WORN = ['attack', 'defense'];

    /** The line every piece may roll, whatever it is for. */
    public const OPTION_DURABILITY = 'durability';

    /** Every piece that goes down a mine, which is every piece but the weapon. */
    public const OPTION_HAUL = 'haul';

    /** Boots, and nothing else. */
    public const OPTION_TRAVEL = 'travel';

    /** The weapon, and nothing else (§9.5.9). */
    public const OPTION_COOLDOWN = 'cooldown';

    /** Rolled apart from the lines, at its own chance (§8.2). */
    public const OPTION_INDESTRUCTIBLE = 'indestructible';

    /**
     * §5.3 -- a share more of ONE material off the ground, and a gathering tool
     * is the only piece that may carry it.
     *
     * Its `stat` is the material key rather than a word, which is what makes
     * §8.0.1's "one line per stat" mean one line per MATERIAL: an axe may come
     * out favouring hardwood and ironwood at once, and never hardwood twice.
     *
     * Only the three grades ABOVE the base one (§5.3), because the base grade
     * is what a hex mostly gives anyway -- a line promising more of the thing
     * you already get most of would be a line that reads as luck and is not.
     */
    public const OPTION_SEAM = 'seam';

    /**
     * The non-common materials a tool's own line can take out of the ground.
     *
     * Four grades a biome (§5.3) and the first is the base, so this is always
     * the other three. The hunting line reads the same ladder off the creature
     * rather than off a hex (§5.5), which is the whole reason it is asked for
     * by SLOT rather than by biome.
     *
     * @return list<string>
     */
    public static function seamMaterialsForSlot(?string $slot): array
    {
        $line = self::skillForSlot((string) $slot);
        if ($line === null) {
            return [];
        }

        if ($line === 'hunting') {
            // §5.5 -- the rungs above the common hide, and everything else a
            // kill gives up. The parts are on the list for the same reason the
            // herbs are on the mining one: they are what the line actually
            // brings home, and a bow that favours sinew is as real a piece of
            // luck as one that favours a better hide.
            return array_values(array_diff(
                array_merge(
                    array_slice(array_column(Hunts::GRADES, 'material'), 1),
                    Hunts::PARTS,
                    [Hunts::GRADED_PART],
                ),
                self::SEAM_NEVER,
            ));
        }

        // Which country this line works is a fact the variant table already
        // holds -- every grade of it names this skill -- so it is read off
        // there rather than kept as a fifth copy of the same pairing.
        foreach (Variants::BIOME_VARIANTS as $biome => $grades) {
            $materials = array_column($grades, 'material');
            // Asked of the SECOND grade rather than the base: the base raws
            // are listed on the skill itself, and this map covers the grades
            // above them.
            if ((Variants::SKILL_FOR_MATERIAL[$materials[1] ?? ''] ?? null) === $line) {
                return self::seamSet(array_slice($materials, 1), $biome);
            }
        }

        return [];
    }

    /**
     * §8.0.1 -- what a rolled seam line may never name.
     *
     * Junk and scrap. §4 gives both the same sentence -- a gold apiece, no
     * recipe anywhere takes them, they reach no tier -- so a line promising
     * more of one is a bonus to nothing. That is not an unlucky roll, which is
     * a thing this pool is meant to have; it is a dud, and a rung of luck a
     * player can read as broken is worse than no rung.
     *
     * Kept as a rule rather than a filter on price, because §4.0 fixes scrap at
     * a gold and a raw above it: the two lists are the same list.
     */
    public const SEAM_NEVER = [
        'deadfall', 'slag', 'bone_splinter', 'cinder', 'thistle',
        'branch', 'ore_chips', 'torn_hide', 'gravel', 'chaff',
        'matted_turf',
    ];

    /**
     * §8.0.1 -- every material a line's ground gives up, bar the commonest.
     *
     * The grades above the base one, the two herbs, the two components and the
     * critter -- which is the whole of what Drops::mining() puts on the table
     * beside the seam itself.
     *
     * **Never the base grade**, which is the one rule that survived the pool
     * widening: it is what a hex mostly hands you anyway, so a line promising
     * more of it would read as luck and would not be. That is the same
     * sentence as "except the commonest thing on the table", and it stays true
     * of every one of the five lines.
     *
     * *(It was the three grades and nothing else. That made a tool's luck a
     * fact about the ladder alone, when most of what a mine actually brings
     * home is the bench stock standing beside the seam -- and an axe that runs
     * to toadstool is a thing a player has a use for.)*
     *
     * @param  list<string>  $grades
     * @return list<string>
     */
    private static function seamSet(array $grades, string $biome): array
    {
        return array_values(array_diff(
            array_merge(
                $grades,
                Drops::herbsOf($biome),
                Drops::componentsOf($biome),
                [Critters::BY_BIOME[$biome] ?? ''],
            ),
            self::SEAM_NEVER,
            [''],
        ));
    }

    /**
     * §9.5.8/§8.0.1 -- what a WEAPON may favour, which is what comes off a body.
     *
     * The third of the same idea. A tool works a hex and favours what the
     * ground gives up; a glove works one bare-handed and favours what hands
     * pick up; a weapon works a monster and favours what a monster drops. In
     * each case it is the piece that **does that work** which carries the line,
     * which is why this is the weapon rather than the coat: §8.0.1 already
     * singles the weapon out as the only slot that shortens a cooldown, and for
     * the same reason -- what is in that slot is what the fight is fought with.
     *
     * The plate line, the ichor line and the four countries' own stock, which
     * is every tier-1 thing §9.5.8 pays. Not the trophies and not the leavings:
     * those are tier 0, which is the same exclusion SEAM_NEVER makes about junk
     * and scrap and for the same reason -- a line promising more of a thing
     * worth a gold that feeds no recipe is a bonus to nothing.
     *
     * Not the gold either, which needs no line: §3.2's faucet is its own thing
     * and `goldFind` (§7.4.3) already owns it. Nor the looted gear, because §2
     * stops loot at rare whatever anybody is wearing.
     *
     * @return list<string>
     */
    public static function battleSeamMaterials(): array
    {
        $out = [];

        foreach (Spoils::BY_GRADE as $lines) {
            $out[] = $lines['plate'];
            $out[] = $lines['ichor'];
        }

        foreach (Spoils::BIOME_SPOIL as $spoil) {
            $out[] = $spoil;
        }

        return array_values(array_filter(
            array_unique($out),
            static fn (string $key) => ((int) (Spoils::STOCK[$key]['tier'] ?? 0)) > 0,
        ));
    }

    /**
     * §4.0/§8.0.1 -- what a GLOVE may favour, which is what hands pick up.
     *
     * Gathering has no tool: §7.3 works it "with your hands in the tool's
     * place", so there is nothing on the belt for a seam line to sit on. The
     * glove is the tool, and this is the only place in the game where a worn
     * piece answers a question a tool answers everywhere else.
     *
     * The gather table is scrap, junk, a little of the base raw and the two
     * herbs (Drops::gathering). Scrap is both the commonest thing on it and on
     * SEAM_NEVER, so it goes twice over; junk goes with it. What is left is the
     * three worth having -- and the base raw is on this list where it is off
     * the mining one, because bare-handed it is the rare find rather than the
     * usual one.
     *
     * Not line-locked, because a glove is not a tool (§8 rule 1 binds tools).
     * A glove favouring toadstool favours it wherever toadstool is picked up.
     *
     * @return list<string>
     */
    public static function gatherSeamMaterials(): array
    {
        $out = [];

        foreach (Variants::BIOME_VARIANTS as $biome => $grades) {
            $out = array_merge(
                $out,
                [$grades[0]['material']],
                Drops::herbsOf($biome),
            );
        }

        return array_values(array_diff(array_unique($out), self::SEAM_NEVER));
    }

    /**
     * §9.5.4 -- a pair of knives keeps nothing off you, of either kind.
     *
     * "Defense belongs to armor, to the shield, and to the sword. A pair of
     * knives has none at all -- a pair that also held a little of it would be the
     * balanced one twice, and the glass cannon is the point." A rolled line is
     * luck rather than budget, but a wand that can come out of the bench
     * guarding says the same wrong thing about what a wand is.
     */
    public const OPTION_FAMILY_NO_DEFENSE = ['dagger'];

    /**
     * §8.0.1 -- every line a roll on this piece may land on.
     *
     * Takes the whole def rather than the slot, because the `weapon` slot holds
     * three families and one of them (§9.5.4) may not roll a guard.
     *
     * @param  array<string,mixed>  $def
     * @return array<int,array{stat:string,kind:string}>
     */
    public static function optionRollsFor(array $def): array
    {
        // §8.5 -- no slot is a consumable, and a potion has no rolled line at
        // all: it is drunk once and gone, so there is nothing for an option to
        // sit on. Falling through to the worn pool would have put a rolled
        // guard on a flask the moment anything asked.
        $slot = (string) ($def['slot'] ?? '');
        if ($slot === '') {
            return [];
        }

        $line = static fn (string $stat, string $kind = 'flat') => ['stat' => $stat, 'kind' => $kind];
        $pool = [];

        if (self::skillForSlot($slot) !== null) {
            // A tool guards nothing: there is no blow on a hex to keep off you.
            $pool[] = $line(self::OPTION_FLAT_TOOL[0]);

            // §5.3 -- and a tool may favour any material its own ground gives
            // up bar the commonest, which is the only rolled line in the game
            // that names a material.
            foreach (self::seamMaterialsForSlot($slot) as $material) {
                $pool[] = $line($material, self::OPTION_SEAM);
            }
        } else {
            // §4.0 -- and the glove is the gatherer's tool, so it carries the
            // same kind of line for the one verb that has no tool at all
            // (§7.3: hands in the tool's place). Not line-locked, because a
            // glove is worn rather than held: one favouring toadstool favours
            // it wherever toadstool is picked up.
            if ($slot === 'gloves') {
                foreach (self::gatherSeamMaterials() as $material) {
                    $pool[] = $line($material, self::OPTION_SEAM);
                }
            }

            foreach (self::OPTION_FLAT_WORN as $stat) {
                // §9.5.4 -- a pair of knives keeps nothing off you, of either kind.
                if ($stat === 'defense' && $slot === 'weapon'
                    && in_array($def['family'] ?? null, self::OPTION_FAMILY_NO_DEFENSE, true)) {
                    continue;
                }

                $pool[] = $line($stat);
            }
        }

        $pool[] = $line(self::OPTION_DURABILITY, 'durability');

        // §8.0.1 -- what hauls, and it is not everything.
        //
        // A tool's haul is its own line's (§7.3), a weapon's is the fight's
        // (§9.5.8), and of the three worn pieces only the GLOVES have one --
        // because the glove is the hand, and the hand is what picks a thing up.
        // The same argument that gave the glove the gather's seam line (§4.0:
        // gathering has no tool, so the glove is the tool) gives it the haul,
        // and it is the only one of the worn three that argument reaches.
        //
        // A coat and a pair of boots used to haul as well, on the reasoning
        // that they are what carries it home. That was carrying doing the work
        // of taking: three of the four worn slots asking the same question, and
        // a glove kept on a shorter ladder to stop it out-hauling a coat --
        // which is the tell that the coat was the odd one, not the glove.
        if ($slot !== 'armor' && $slot !== 'boots') {
            $pool[] = $line(self::OPTION_HAUL, 'gain');
        }

        if ($slot === 'boots') {
            $pool[] = $line(self::OPTION_TRAVEL, 'gain');
        }

        if ($slot === 'weapon') {
            $pool[] = $line(self::OPTION_COOLDOWN, 'cooldown');

            // §9.5.8 -- and what it favours off a body, which is the fight's
            // own version of a tool's seam. The weapon rather than the worn
            // set, because the weapon is what the fight is fought with.
            foreach (self::battleSeamMaterials() as $material) {
                $pool[] = $line($material, self::OPTION_SEAM);
            }
        }

        return $pool;
    }

    /**
     * @param  array<string,mixed>  $def
     * @return array<int,string>
     */
    public static function optionStatsFor(array $def): array
    {
        return array_values(array_unique(array_column(self::optionRollsFor($def), 'stat')));
    }

    /**
     * §9.5.4 -- which battle job a weapon family levels.
     *
     * One slot holds all three, and the family you carry is your class: §7.4 has
     * always said a battle job levels by fighting with a shield, a sword or a
     * daggers, and this is the line that finally makes that true.
     */
    public const BATTLE_JOB_FOR_FAMILY = [
        'shield' => 'shieldbearer',
        'sword' => 'swordhand',
        'dagger' => 'knifedancer',
    ];

    /**
     * §7.1 -- the job whose level gates this piece, or null where none does.
     *
     * One rule, and it is §8's usual one: **the piece that does the work
     * answers to the job that does it.** A tool reads its own line off its slot
     * (§8 rule 1) and a weapon reads its family's battle job (§9.5.4, the
     * family in the slot is your class). Armor, boots and gloves answer to
     * neither, so nothing here reaches them and the character's own level is
     * their gate (Balance::EQUIP_LEVEL).
     *
     * Derived, never stored: a `gateJob` column on a hundred catalog rows would
     * be a hundred chances to disagree with the two maps above, which already
     * say the same thing for the two other questions they are asked.
     *
     * @param  array<string,mixed>  $def
     */
    public static function equipGateJob(array $def): ?string
    {
        $slot = (string) ($def['slot'] ?? '');

        if ($slot === 'weapon') {
            return self::BATTLE_JOB_FOR_FAMILY[(string) ($def['family'] ?? '')] ?? null;
        }

        // A gathering job's level IS its §7.2 skill level, so the line key and
        // the job key are one key (§7.4).
        return self::TOOL_SLOT_SKILL[$slot] ?? null;
    }

    /** §4.0 -- scrap is what a hex gives up to bare hands. It feeds no recipe. */
    public static function isScrap(string $materialKey): bool
    {
        return in_array($materialKey, self::BIOME_SCRAP, true);
    }

    /** Processing recipes, §4 tier 2 / §6. */
    public static function recipes(): array
    {
        return [
            'planks' => ['name' => 'Saw Planks', 'input' => 'wood', 'inputQty' => 3, 'output' => 'planks', 'outputQty' => 1, 'baseSeconds' => 12 * 60, 'skill' => 'woodcutting'],
            'ingots' => ['name' => 'Smelt Ingots', 'input' => 'iron_ore', 'inputQty' => 3, 'output' => 'ingots', 'outputQty' => 1, 'baseSeconds' => 15 * 60, 'skill' => 'mining'],
            'leather' => ['name' => 'Tan Leather', 'input' => 'pelt', 'inputQty' => 3, 'output' => 'leather', 'outputQty' => 1, 'baseSeconds' => 13 * 60, 'skill' => 'hunting'],
            'cut_stone' => ['name' => 'Dress Stone', 'input' => 'stone', 'inputQty' => 3, 'output' => 'cut_stone', 'outputQty' => 1, 'baseSeconds' => 12 * 60, 'skill' => 'quarrying'],
            'cloth' => ['name' => 'Weave Cloth', 'input' => 'fiber', 'inputQty' => 3, 'output' => 'cloth', 'outputQty' => 1, 'baseSeconds' => 11 * 60, 'skill' => 'harvesting'],
            'reinforced_frame' => ['name' => 'Band a Frame', 'input' => 'planks', 'inputQty' => 2, 'secondInput' => 'ingots', 'secondInputQty' => 2, 'output' => 'reinforced_frame', 'outputQty' => 1, 'baseSeconds' => 26 * 60, 'skill' => 'mining'],
        ]
            // §5.3 -- one line per grade, on the same 3:1 the five base lines
            // run. A better grade is a better material, never a better ratio:
            // making the good ore also process cheaper would turn one ladder
            // into two.
            + Variants::PROCESSING
            + Hunts::PROCESSING;
    }

    public static function recipe(string $key): ?array
    {
        return self::recipes()[$key] ?? null;
    }

    /**
     * Gathering tool slots, §8. One implement per skill line -- an axe is no use
     * on a seam and a bow is no use on a tree, so each line has its own slot and
     * its own ladder. A tool contributes its stat *only* on mines for its own
     * line, and only that tool takes durability for the mine.
     *
     * `weapon` is deliberately not in here: that slot is raid combat, and combat
     * gear must never be able to stand in for a gathering tool.
     */
    public const TOOL_SLOT_SKILL = [
        'axe' => 'woodcutting',
        'pickaxe' => 'mining',
        'bow' => 'hunting',
        'hammer' => 'quarrying',
        'sickle' => 'harvesting',
    ];

    /** The skill a gathering slot serves, or null for gear that works anywhere. */
    public static function skillForSlot(string $slot): ?string
    {
        return self::TOOL_SLOT_SKILL[$slot] ?? null;
    }

    /** The slot a skill line draws its tool from. */
    public static function slotForSkill(string $skill): ?string
    {
        $slot = array_search($skill, self::TOOL_SLOT_SKILL, true);

        return $slot === false ? null : $slot;
    }

    /**
     * Equipment, §8.3. `stat` values are the item's own contribution before the
     * §8.1 stacking falloff and per-tier cap are applied.
     *
     * Every gathering line carries the same five-step ladder -- village basic,
     * city basic, crafted starter, crafted, NFT -- so no line is quietly weaker
     * than another. The specialisation §7.2 asks for comes from the skill point
     * cap, never from one line having better tools available than the rest.
     */
    public static function items(): array
    {
        static $items = null;

        return $items ??= [
            // -------------------------------------------- Basic -- gold shop, +3-5%
            // `station` on a shop item is the smallest settlement that stocks it.
            // Villages carry the basics; the better gear is a reason to walk to a
            // city, which is the same tier pressure §6 puts on processing lines.
            'stone_axe' => ['name' => 'Stone Axe', 'slot' => 'axe', 'rarity' => 'common', 'tradeable' => false, 'attack' => 300, 'palette' => 'stone', 'goldPrice' => 17, 'maxDurability' => 4000, 'station' => 'village', 'description' => 'A chipped edge lashed to a handle. Better than bare hands.'],
            'chipped_pick' => ['name' => 'Chipped Pick', 'slot' => 'pickaxe', 'rarity' => 'common', 'tradeable' => false, 'attack' => 300, 'palette' => 'stone', 'goldPrice' => 17, 'maxDurability' => 4000, 'station' => 'village', 'description' => 'Second-hand, and shorter than it started. Still bites ore.'],
            'crude_bow' => ['name' => 'Crude Bow', 'slot' => 'bow', 'rarity' => 'common', 'tradeable' => false, 'attack' => 300, 'palette' => 'wood', 'goldPrice' => 17, 'maxDurability' => 4000, 'station' => 'village', 'description' => 'Green stave, gut string. Close range or nothing.'],
            'stone_mallet' => ['name' => 'Stone Mallet', 'slot' => 'hammer', 'rarity' => 'common', 'tradeable' => false, 'attack' => 300, 'palette' => 'stone', 'goldPrice' => 17, 'maxDurability' => 4000, 'station' => 'village', 'description' => 'A rock on a stick. It still splits badlands shale.'],
            'bent_sickle' => ['name' => 'Bent Sickle', 'slot' => 'sickle', 'rarity' => 'common', 'tradeable' => false, 'attack' => 300, 'palette' => 'fiber', 'goldPrice' => 17, 'maxDurability' => 4000, 'station' => 'village', 'description' => 'Someone straightened it once. It did not take.'],

            'iron_hatchet' => ['name' => 'Iron Hatchet', 'slot' => 'axe', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 600, 'palette' => 'iron', 'maxDurability' => 7000, 'station' => 'city', 'inputs' => ['iron_ore' => 4, 'ingots' => 3, 'planks' => 2, 'heartknot' => 2], 'description' => 'Plain iron on a plain haft. Reliable, unremarkable.'],
            'miners_pick' => ['name' => "Miner's Pick", 'slot' => 'pickaxe', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 600, 'palette' => 'iron', 'maxDurability' => 7000, 'station' => 'city', 'inputs' => ['iron_ore' => 4, 'ingots' => 3, 'planks' => 2, 'flux_salt' => 2], 'description' => 'Guild pattern, and every seam in the range has met one.'],
            'recurve_bow' => ['name' => 'Recurve Bow', 'slot' => 'bow', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 600, 'palette' => 'pelt', 'maxDurability' => 7000, 'station' => 'city', 'inputs' => ['pelt' => 4, 'leather' => 3, 'planks' => 2, 'horn' => 2], 'description' => 'Backed and glued. Drops a buck without the chase.'],
            'iron_sledge' => ['name' => 'Iron Sledge', 'slot' => 'hammer', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 600, 'palette' => 'iron', 'maxDurability' => 7000, 'station' => 'city', 'inputs' => ['iron_ore' => 4, 'ingots' => 3, 'cut_stone' => 2, 'whetgrit' => 2], 'description' => 'Heavy enough that the stone does most of the arguing.'],
            'steel_sickle' => ['name' => 'Steel Sickle', 'slot' => 'sickle', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 600, 'palette' => 'iron', 'maxDurability' => 7000, 'station' => 'city', 'inputs' => ['iron_ore' => 4, 'ingots' => 3, 'cloth' => 2, 'quench_reed' => 2], 'description' => 'Holds an edge through a full field, then wants a stone.'],

            'travel_cloak' => ['name' => 'Travel Cloak', 'slot' => 'armor', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.02, 'attack' => 0, 'defense' => 200, 'palette' => 'fiber', 'goldPrice' => 26, 'maxDurability' => 6000, 'station' => 'village', 'description' => 'Keeps the weather off, and the pockets hold more than they look like they should.'],
            'hide_shoes' => ['name' => 'Hide Shoes', 'slot' => 'boots', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'travelSpeed', 'value' => 0.04, 'attack' => 0, 'defense' => 200, 'palette' => 'pelt', 'maxDurability' => 5000, 'station' => 'city', 'inputs' => ['pelt' => 4, 'leather' => 3, 'cloth' => 2, 'tar_seep' => 2], 'description' => 'Soft-soled and quiet. Not built for the badlands.'],

            // ------------------------- Crafted starter -- raw + one refined, +4%
            // The first thing a player makes on a line. Cheap, short-lived, and
            // deliberately weaker than the city shop tool: it is what you can
            // build before you can afford to buy, §12 step 7.
            //
            // Two kinds, and the rung widens by one at every step above: two,
            // three, four, five. A recipe reaching further up the ladder reaches
            // wider across the map as well, so a top-tier craft is a project
            // rather than a purchase.
            //
            // Raw sits in every rung beside the refined. Wood is worth carrying
            // home as wood, not only as something to feed the saw, and the
            // gathering lines get a sink that does not run through a queue.
            'hewn_axe' => ['name' => 'Hewn Axe', 'slot' => 'axe', 'rarity' => 'common', 'tradeable' => false, 'attack' => 400, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 6000, 'inputs' => ['wood' => 6, 'planks' => 2, 'heartknot' => 2], 'description' => 'Your first real tool. It will not last, but it will teach.'],
            'wood_pickaxe' => ['name' => 'Wood Pickaxe', 'slot' => 'pickaxe', 'rarity' => 'common', 'tradeable' => false, 'attack' => 400, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 6000, 'inputs' => ['wood' => 6, 'planks' => 2, 'flux_salt' => 2], 'description' => 'Wood against rock. It lasts exactly as long as you would expect.'],
            'shortbow' => ['name' => 'Shortbow', 'slot' => 'bow', 'rarity' => 'common', 'tradeable' => false, 'attack' => 400, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 6000, 'inputs' => ['wood' => 6, 'cloth' => 2, 'horn' => 2], 'description' => 'Straight stave, woven string. Quiet, and quick to redraw.'],
            'stone_maul' => ['name' => 'Stone Maul', 'slot' => 'hammer', 'rarity' => 'common', 'tradeable' => false, 'attack' => 400, 'palette' => 'stone', 'station' => 'village', 'maxDurability' => 6000, 'inputs' => ['stone' => 6, 'cut_stone' => 2, 'whetgrit' => 2], 'description' => 'Dressed head, seated cold. Stone breaks stone.'],
            'reed_sickle' => ['name' => 'Reed Sickle', 'slot' => 'sickle', 'rarity' => 'common', 'tradeable' => false, 'attack' => 400, 'palette' => 'fiber', 'station' => 'village', 'maxDurability' => 6000, 'inputs' => ['fiber' => 6, 'cloth' => 2, 'quench_reed' => 2], 'description' => 'Bound at the grip so it stops turning in a wet hand.'],

            // -------------- Crafted -- the uncommon grade, +6-8%, city bench
            // §5.3 -- this rung wants hardwood rather than wood, hematite rather
            // than iron ore. That ground only turns up from the middle ring in,
            // so the city bench is not the only thing making a player walk.
            //
            // The partner is the other half of the thing: iron for an axe head,
            // wood for a pick haft, cloth for a bowstring.
            'ironbound_axe' => ['name' => 'Ironbound Axe', 'slot' => 'axe', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 800, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 12000, 'inputs' => ['hardwood' => 6, 'beams' => 3, 'ingots' => 2, 'heartknot' => 3], 'description' => 'Wedged head, banded eye. Fells clean and comes back out.'],
            'iron_pickaxe' => ['name' => 'Iron Pickaxe', 'slot' => 'pickaxe', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 800, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 12000, 'inputs' => ['hematite' => 6, 'steel_ingots' => 3, 'planks' => 2, 'flux_salt' => 3], 'description' => 'Balanced head, seasoned haft. The workhorse tool.'],
            'sinew_longbow' => ['name' => 'Sinew Longbow', 'slot' => 'bow', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 800, 'palette' => 'pelt', 'station' => 'city', 'maxDurability' => 12000, 'inputs' => ['thick_pelt' => 6, 'boiled_leather' => 3, 'cloth' => 2, 'horn' => 3], 'description' => 'Sinew-backed and heavy to draw. The herd never hears it.'],
            'banded_sledge' => ['name' => 'Banded Sledge', 'slot' => 'hammer', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 800, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 12000, 'inputs' => ['basalt' => 6, 'dressed_basalt' => 3, 'ingots' => 2, 'whetgrit' => 3], 'description' => 'Iron banding over a stone core. It takes the shock instead of you.'],
            'toothed_sickle' => ['name' => 'Toothed Sickle', 'slot' => 'sickle', 'rarity' => 'uncommon', 'tradeable' => false, 'attack' => 800, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 12000, 'inputs' => ['flax' => 6, 'linen' => 3, 'ingots' => 2, 'quench_reed' => 3], 'description' => 'Serrated inside the curve. It saws where a plain edge slides.'],

            'leather_armor' => ['name' => 'Leather Armor', 'slot' => 'armor', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'attack' => 0, 'defense' => 300, 'palette' => 'pelt', 'station' => 'city', 'maxDurability' => 13000, 'inputs' => ['thick_pelt' => 8, 'boiled_leather' => 4, 'cloth' => 2, 'sinew' => 3], 'description' => 'Light enough to walk in all day.'],
            'reinforced_boots' => ['name' => 'Reinforced Boots', 'slot' => 'boots', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'travelSpeed', 'value' => 0.05, 'attack' => 0, 'defense' => 200, 'palette' => 'stone', 'station' => 'city', 'maxDurability' => 14000, 'inputs' => ['basalt' => 8, 'dressed_basalt' => 3, 'leather' => 2, 'slate_scale' => 3], 'description' => 'Stone-shod. Ugly, and you will stop caring by noon.'],
            'work_gloves' => ['name' => 'Work Gloves', 'slot' => 'gloves', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'processingSpeed', 'value' => 0.03, 'attack' => 100, 'defense' => 0, 'palette' => 'fiber', 'station' => 'village', 'maxDurability' => 9000, 'inputs' => ['fiber' => 6, 'cloth' => 2, 'beeswax' => 2], 'description' => 'Doubled at the palm. Speeds work on the settlement lines.'],

            // ------------------ Rare -- the rare grade, +8%, capital bench
            // §5.3 -- heartoak, meteoric iron, dire pelt: contested ring only.
            // Reinforced Frame gates the rung on top of that, being the one
            // tier-2 that needs two processing lines, so rare gear implies both
            // a settled player and one willing to work the middle of the map.
            'broadaxe' => ['name' => 'Broadaxe', 'slot' => 'axe', 'rarity' => 'rare', 'tradeable' => false, 'attack' => 1000, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['heartoak' => 8, 'bentwood' => 4, 'ingots' => 3, 'heartknot' => 4, 'reinforced_frame' => 1], 'description' => 'Two hands, a long haul, and a tree down in three swings.'],
            'deep_pick' => ['name' => 'Deep Pick', 'slot' => 'pickaxe', 'rarity' => 'rare', 'tradeable' => false, 'attack' => 1000, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['meteoric_iron' => 8, 'skysteel' => 4, 'planks' => 3, 'flux_salt' => 4, 'reinforced_frame' => 1], 'description' => 'Long in the head, for seams that do not start at the surface.'],
            'warbow' => ['name' => 'Warbow', 'slot' => 'bow', 'rarity' => 'rare', 'tradeable' => false, 'attack' => 1000, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['dire_pelt' => 8, 'lacquered_hide' => 4, 'canvas' => 3, 'horn' => 4, 'reinforced_frame' => 1], 'description' => 'A draw weight most people cannot hold. It does not need a second shot.'],
            'splitting_maul' => ['name' => 'Splitting Maul', 'slot' => 'hammer', 'rarity' => 'rare', 'tradeable' => false, 'attack' => 1000, 'palette' => 'stone', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['granite' => 8, 'polished_granite' => 4, 'ingots' => 3, 'whetgrit' => 4, 'reinforced_frame' => 1], 'description' => 'Wedge-headed. It does not crush the rock, it opens it.'],
            'threshing_scythe' => ['name' => 'Threshing Scythe', 'slot' => 'sickle', 'rarity' => 'rare', 'tradeable' => false, 'attack' => 1000, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['hemp' => 8, 'canvas' => 4, 'skysteel' => 3, 'quench_reed' => 4, 'reinforced_frame' => 1], 'description' => 'Long snath, long blade. A field goes down in rows, not handfuls.'],
            'banded_mail' => ['name' => 'Banded Mail', 'slot' => 'armor', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.08, 'attack' => 100, 'defense' => 500, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['dire_pelt' => 8, 'lacquered_hide' => 4, 'steel_ingots' => 3, 'sinew' => 4, 'reinforced_frame' => 1], 'description' => 'Iron bands over tanned hide. Heavy, and worth every pound of it.'],
            'marching_boots' => ['name' => 'Marching Boots', 'slot' => 'boots', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'travelSpeed', 'value' => 0.08, 'attack' => 0, 'defense' => 300, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['dire_pelt' => 8, 'lacquered_hide' => 4, 'polished_granite' => 3, 'tar_seep' => 4, 'reinforced_frame' => 1], 'description' => 'Built for the road between rings, not the walk to the next hex.'],
            'tanners_gloves' => ['name' => "Tanner's Gloves", 'slot' => 'gloves', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'processingSpeed', 'value' => 0.08, 'attack' => 300, 'defense' => 100, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 16000, 'inputs' => ['hemp' => 8, 'canvas' => 4, 'lacquered_hide' => 3, 'beeswax' => 4, 'reinforced_frame' => 1], 'description' => 'Cut for the settlement lines. The work goes faster and the hands last.'],

            // ------------ NFT -- six kinds across four tiers, +12-15% hard cap
            // Each line's top tool wants its own rare material and its own dungeon
            // shard, so kitting out a second line means crossing the map, §4. The
            // rare grade underneath them is what makes it a haul as well as a
            // raid: tier 1, 2, 3 and 4 in one recipe.
            'ironwood_axe' => ['name' => 'Ironwood Axe', 'slot' => 'axe', 'rarity' => 'epic', 'tradeable' => true, 'attack' => 1400, 'palette' => 'wood', 'station' => 'guild', 'maxDurability' => 20000, 'inputs' => ['ironwood' => 3, 'heartoak' => 8, 'bentwood' => 4, 'heartknot' => 4, 'reinforced_frame' => 2, 'shard_verdant' => 1], 'description' => 'Cut from the thing it is meant to cut. Marketplace-tradeable.'],
            'mythril_pickaxe' => ['name' => 'Mythril Pickaxe', 'slot' => 'pickaxe', 'rarity' => 'epic', 'tradeable' => true, 'attack' => 1400, 'palette' => 'iron', 'station' => 'guild', 'maxDurability' => 20000, 'inputs' => ['mythril_ore' => 3, 'meteoric_iron' => 8, 'skysteel' => 4, 'flux_salt' => 4, 'reinforced_frame' => 2, 'essence' => 1], 'description' => 'Rings like a bell on ore. Marketplace-tradeable.'],
            'beastfang_bow' => ['name' => 'Beastfang Bow', 'slot' => 'bow', 'rarity' => 'epic', 'tradeable' => true, 'attack' => 1400, 'palette' => 'pelt', 'station' => 'guild', 'maxDurability' => 20000, 'inputs' => ['beastfang_hide' => 3, 'dire_pelt' => 8, 'lacquered_hide' => 4, 'horn' => 4, 'reinforced_frame' => 2, 'shard_sanguine' => 1], 'description' => 'Strung with something that used to run. Marketplace-tradeable.'],
            'obsidian_sledge' => ['name' => 'Obsidian Sledge', 'slot' => 'hammer', 'rarity' => 'epic', 'tradeable' => true, 'attack' => 1400, 'palette' => 'stone', 'station' => 'guild', 'maxDurability' => 20000, 'inputs' => ['obsidian_shard' => 3, 'granite' => 8, 'polished_granite' => 4, 'whetgrit' => 4, 'reinforced_frame' => 2, 'shard_cinder' => 1], 'description' => 'Glass that lands like iron. Marketplace-tradeable.'],
            'silkweave_sickle' => ['name' => 'Silkweave Sickle', 'slot' => 'sickle', 'rarity' => 'epic', 'tradeable' => true, 'attack' => 1400, 'palette' => 'fiber', 'station' => 'guild', 'maxDurability' => 20000, 'inputs' => ['silkweave_fiber' => 3, 'hemp' => 8, 'canvas' => 4, 'quench_reed' => 4, 'reinforced_frame' => 2, 'shard_zephyr' => 1], 'description' => 'The grass parts before it arrives. Marketplace-tradeable.'],

            'ironwood_armor' => ['name' => 'Ironwood Armor', 'slot' => 'armor', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'yield', 'value' => 0.11, 'attack' => 100, 'defense' => 700, 'palette' => 'wood', 'station' => 'guild', 'maxDurability' => 21000, 'inputs' => ['ironwood' => 3, 'heartoak' => 8, 'bentwood' => 4, 'pine_pitch' => 4, 'reinforced_frame' => 2, 'shard_verdant' => 1], 'description' => 'Grown, not forged. Marketplace-tradeable.'],
            'beastfang_boots' => ['name' => 'Beastfang Boots', 'slot' => 'boots', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'travelSpeed', 'value' => 0.11, 'attack' => 0, 'defense' => 400, 'palette' => 'pelt', 'station' => 'guild', 'maxDurability' => 19000, 'inputs' => ['beastfang_hide' => 3, 'dire_pelt' => 8, 'lacquered_hide' => 4, 'sinew' => 4, 'reinforced_frame' => 2, 'relic' => 1], 'description' => 'Something fast died for these. Marketplace-tradeable.'],
            'silkweave_gloves' => ['name' => 'Silkweave Gloves', 'slot' => 'gloves', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'processingSpeed', 'value' => 0.11, 'attack' => 400, 'defense' => 100, 'palette' => 'fiber', 'station' => 'guild', 'maxDurability' => 19500, 'inputs' => ['silkweave_fiber' => 3, 'hemp' => 8, 'canvas' => 4, 'beeswax' => 4, 'reinforced_frame' => 2, 'shard_zephyr' => 1], 'description' => 'Spun so fine the work goes quicker for feeling less. Marketplace-tradeable.'],

        ]
            // §9.5.4 -- the six combat groups, three grades at every rung. The
            // grade is a materials ladder inside the rung: cheap, more, and
            // more plus whatever is rare for that rung. Generated, not typed:
            // see scripts/gen_battlegear.py.
            + BattleGear::ITEMS
            // §8.0 -- the two rungs above epic, so the ladder can be read whole.
            // Neither is reachable: legendary needs a guild hall and there are
            // none, unique has no bench at all and drops soulbound. Generated,
            // not typed: see scripts/gen_toptier.py.
            + TopTier::ITEMS
            // §8.5 -- seventy potions, fourteen a rung. No slot and no durability:
            // a potion is spent, it starts a timed buff on ONE ACTION, and the
            // buff expiring is the sink (§11.1). Generated, not typed.
            + Alchemy::CONSUMABLES;
    }

    public static function item(string $key): ?array
    {
        return self::items()[$key] ?? null;
    }

    /** §9.1 -- five dungeons, one per biome. */
    public const DUNGEONS = [
        ['key' => 'rootvault', 'name' => 'Rootvault', 'biome' => 'forest', 'drop' => 'shard_verdant'],
        ['key' => 'deepshaft', 'name' => 'Deepshaft', 'biome' => 'mountain', 'drop' => 'shard_ferrous'],
        // §9.1 -- the beast dungeon, and the one that belongs to no country:
        // it is where the things you hunt den, which is the whole of its name.
        ['key' => 'beastwarren', 'name' => 'Beastwarren', 'biome' => null, 'drop' => 'shard_sanguine'],
        ['key' => 'ashpit', 'name' => 'Ashpit', 'biome' => 'badlands', 'drop' => 'shard_cinder'],
        ['key' => 'windhollow', 'name' => 'Windhollow', 'biome' => 'grassland', 'drop' => 'shard_zephyr'],
    ];

    /**
     * Settlement names are built from a prefix and a suffix rather than picked
     * from a flat list. §5.3 wants a map players can navigate by memory, and a
     * flat list of 18 put two identically-named villages on screen at once --
     * "meet me at Millgate" stops meaning anything. This gives 22 x 16 = 352.
     */
    public const NAME_PREFIXES = [
        'Ash', 'Kel', 'Thorn', 'Dun', 'Red', 'Stone', 'Var', 'Mill', 'Black',
        'High', 'Ember', 'Gray', 'Oak', 'Iron', 'Cold', 'Sable', 'Wren', 'Marrow',
        'Elder', 'Fern', 'Hollow', 'Brack',
    ];

    public const NAME_SUFFIXES = [
        'ford', 'grave', 'well', 'moor', 'hollow', 'brook', 'row', 'gate',
        'fen', 'cross', 'ton', 'march', 'hurst', 'vale', 'water', 'ridge',
    ];

    public const STATION_RANK = ['village' => 1, 'city' => 2, 'capital' => 3, 'guild' => 4];
}
