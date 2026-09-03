"""Emit the §5.5 hunting roster from one spec.

    python3 scripts/gen_hunts.py

Writes app/Game/Hunts.php and resources/js/game/hunts.ts.

An animal stands on forest and grassland ground, always, and moves on the pack
bucket. Hunting it is what mining a plains hex used to be: the hunting line's
whole ladder, carried by the creature instead of by the country.
"""
import io

# ------------------------------------------------------------ the hunting line
#
# §5.5 -- the whole ladder, and it lives HERE rather than in gen_variants.py
# with the other four. The other four are worked off ground, so their materials
# belong with the country that carries them; hunting is worked off an animal, so
# its materials belong with the creature. Leaving it among the biomes was what
# made "remove the plains biome" mean "delete the tanner".
#
# Same shape as a biome's line: four grades, each a tier-1 raw and (except the
# contested rung) the tier-2 it processes into.
#
# grade, raw (key, Name, npcPrice), refined (key, Name, npcPrice, minutes)
LINE = [
    ('common',    ('pelt',           'Pelt',           3), ('leather',        'Leather',         8, 13)),
    ('uncommon',  ('thick_pelt',     'Thick Pelt',     5), ('boiled_leather', 'Boiled Leather', 16, 17)),
    ('rare',      ('dire_pelt',      'Dire Pelt',      9), ('lacquered_hide', 'Lacquered Hide', 30, 24)),
    ('contested', ('beastfang_hide', 'Beastfang Hide', 0), None),
]

DESCRIPTIONS = {
    'pelt': 'Rough hide, taken off something that was using it.',
    'thick_pelt': 'Winter coat off a full-grown animal. Heavy, and it keeps its shape.',
    'dire_pelt': 'Off something that had no natural enemies until you turned up.',
    'beastfang_hide': 'Taken off something that fought back.',
    'leather': 'Scraped, soaked and worked soft. The first thing a tannery is for.',
    'boiled_leather': 'Boiled hard and molded wet. Sets like a shell and weighs nothing.',
    'lacquered_hide': 'Layered, lacquered, and left in the dark to cure. Turns a blade.',
}

# The grade ladder, lifted off the plains variants it replaces.
#
# A hex used to carry this -- Plains, Herd Range, Dire Range, Beastfang Reach --
# and the animal carries it now. The weights are the variant table's, unchanged,
# which is what keeps Beastfang Hide contested-only (§2: a Tier 3 on the safe
# rim would be the grind->NFT path the threat model exists to close).
#
# grade, material, weights by ring
WEIGHTS = {
    'common':    {'outer': 0.975, 'mid': 0.68, 'inner': 0.42},
    'uncommon':  {'outer': 0.02,  'mid': 0.3,  'inner': 0.25},
    'rare':      {'outer': 0.005, 'mid': 0.02, 'inner': 0.15},
    'contested': {'outer': 0.0,   'mid': 0.0,  'inner': 0.18},
}

GRADES = [(grade, raw[0], WEIGHTS[grade]) for grade, raw, _ in LINE]

# §5.5 -- what a kill gives up BESIDE the hide, and it lives here for the same
# reason the ladder does: it is the hunt's table, not a hex's.
#
# Two components, two reagents and the critter that used to come off plains
# ground. Drops.php reads these rather than keeping a second copy, and the
# almanac reads them so a player can find out what a hunt pays before taking
# one -- which is the whole of what that screen is for.
PARTS = ['horn', 'sinew', 'bitterroot', 'yarrow', 'dustleveret']

# §5.5 -- and one part the grade alone pays for.
#
# It came off plains ground as that country's §9.5.8 stock and had nowhere to
# go when the country did; the sentence on it was always about an ANIMAL rather
# than about a kind of dirt -- "a thing that ran every day of its life" -- so
# the hunt is where it belonged all along. Restoring it here is what keeps
# folding a biome into a line from costing the game a material.
#
# Off the common rung deliberately: a Roe Deer never carries it, so the grade
# ladder pays in a KIND of drop as well as in a rung of hide, and the almanac's
# eight entries differ by something a player can act on.
GRADED_PART = 'braided_sinew'
GRADED_FROM = 'uncommon'

# §4 -- the tier-0 rubbish carried out alongside, every time.
JUNK = 'bone_splinter'

# §9.5.8's other half, kept: what says WHERE, rather than what.
#
# The fight has a trophy and a leaving for exactly this reason -- one names what
# you fought and one names the ground it happened on -- and a hunt is the same
# two questions. The hide answers the first and this answers the second.
LEAVING = 'matted_turf'

# The two the plains roster carried, re-described for the creature they now come
# off rather than for the country that is gone. Merged into Catalog beside the
# rest of the hunting line.
#
# key, Name, tier, palette, npcPrice, description
EXTRA = [
    (GRADED_PART, 'Braided Sinew', 1, 'pelt', 8,
     'Laid down in cords by a thing that ran every day of its life. It will not part.'),
    (LEAVING, 'Matted Turf', 0, 'stone', 1,
     'Torn up where it was braced against you. Roots, dirt, and nothing else.'),
]

# key, name, biome, grade, description
#
# Two countries of four, one per grade, because the grade is what decides the
# haul and a country that shared its animals with the other would make the two
# the same walk.
ANIMALS = [
    ('roe_deer', 'Roe Deer', 'forest', 'common',
     'Feeding at the edge of the trees, and gone into them the moment you are seen.'),
    ('wood_boar', 'Wood Boar', 'forest', 'uncommon',
     'Rooting under the mast. It has no reason to run and knows it.'),
    ('bracken_elk', 'Bracken Elk', 'forest', 'rare',
     'Shoulder-high in the fern with a rack it has to turn sideways to walk.'),
    ('ironhide_stag', 'Ironhide Stag', 'forest', 'contested',
     'Nothing in the wood has taken one down in living memory. You may try.'),

    ('field_doe', 'Field Doe', 'grassland', 'common',
     'Standing in the seed heads with its ears up, which is how you find it.'),
    ('horned_ram', 'Horned Ram', 'grassland', 'uncommon',
     'Comes down the slope at you rather than away, every time.'),
    ('sedge_auroch', 'Sedge Auroch', 'grassland', 'rare',
     'Older than the settlement it grazes past, and heavier than the gate.'),
    ('beastfang_sire', 'Beastfang Sire', 'grassland', 'contested',
     'The hide is named for it. So is most of what it has eaten.'),
]

BIOMES = ['forest', 'grassland']

HEADER = """§5.5 -- the hunt. What mining a plains hex used to be.

Animals stand on forest and grassland, on **a share** of it, and move on the
pack's own two-hour bucket. Killing one clears it exactly as fighting a pack
clears that.

**A share rather than all of it.** One on every workable hex made the hunt a
property of the ground -- walk onto forest, hunt -- which is the plains biome
again under another name. A chance is what makes finding game a thing you do
rather than a thing that is true. It is a higher share than a pack's, because a
pack is a hazard the map is better for being sparse with and this is a whole
gathering line's faucet.

**It does not pin.** A pack owns the hex it stands on (§9.5.3), which is a
hazard a player walks around; an animal doing the same would fence off the
country its line is worked in. It is a hook on a hex that otherwise works
normally -- the standing §9.5.7 gives a corpse.

**The GRADE is the ladder, and it is the plains variant table moved onto the
creature.** A hex used to carry Plains / Herd Range / Dire Range / Beastfang
Reach; the animal carries it now, on the same weights -- which is what keeps
Beastfang Hide contested-only without a rule of its own (§2: a Tier 3 on the
safe rim is the grind->NFT path the threat model exists to close).

**Two countries of four**, one animal per grade. A roster shared between them
would make forest and grassland the same walk, and §9.5.2 has just finished
making that argument about monsters."""


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


ts_str = php_str


def doc(o, header, prefix=' * '):
    o.write('/**\n')
    for line in header.split('\n'):
        o.write((f'{prefix}{line}'.rstrip() + '\n') if line else ' *\n')
    o.write(' */\n')


def emit_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    doc(o, HEADER)
    o.write('final class Hunts\n{\n')
    o.write('    /** §5.5 -- the countries an animal stands on, and no others. */\n')
    o.write("    public const BIOMES = ['" + "', '".join(BIOMES) + "'];\n\n")
    o.write('    /** §5.3 -- the grade ladder, and what each rung gives up. */\n')
    o.write('    public const GRADES = [\n')
    for grade, material, weights in GRADES:
        w = ', '.join(f"'{r}' => {v}" for r, v in weights.items())
        o.write(f"        '{grade}' => ['material' => '{material}', 'weights' => [{w}]],\n")
    o.write('    ];\n\n')
    o.write('    /** §5.5 -- the roster. Keyed by animal, one per (biome, grade). */\n')
    o.write('    public const ROSTER = [\n')
    for key, name, biome, grade, desc in ANIMALS:
        material = next(m for g, m, _ in GRADES if g == grade)
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'biome' => '{biome}', "
            f"'grade' => '{grade}', 'material' => '{material}', "
            f"'description' => {php_str(desc)}],\n"
        )
    o.write('    ];\n\n')
    o.write('    /**\n')
    o.write('     * §4 Tier 1 -- the hunting line\'s own raw ladder.\n')
    o.write('     *\n')
    o.write('     * `source` rather than `biome`, and that is the whole of what\n')
    o.write('     * makes hunting different from the other four lines: these come\n')
    o.write('     * off a creature rather than off a country, so nothing here is\n')
    o.write('     * locked to ground and nothing reads a biome to find it.\n')
    o.write('     */\n')
    o.write('    public const RAW = [\n')
    for grade, raw, _ in LINE:
        key, name, price = raw
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 1, "
            f"'source' => 'hunt', 'grade' => '{grade}', 'palette' => 'pelt', "
            f"'npcPrice' => {price}, 'description' => {php_str(DESCRIPTIONS[key])}],\n"
        )
    o.write('    ];\n\n')

    o.write('    /** §4 Tier 2 -- what each rung tans into. Not locked to anything. */\n')
    o.write('    public const REFINED = [\n')
    for grade, _, refined in LINE:
        if refined is None:
            continue
        key, name, price, _minutes = refined
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 2, "
            f"'palette' => 'pelt', 'npcPrice' => {price}, "
            f"'description' => {php_str(DESCRIPTIONS[key])}],\n"
        )
    o.write('    ];\n\n')

    o.write('    /** §6 -- the Tanner\'s line, one recipe per rung. */\n')
    o.write('    public const PROCESSING = [\n')
    for grade, raw, refined in LINE:
        if refined is None:
            continue
        key, name, _price, minutes = refined
        o.write(
            f"        'tan_{key}' => ['name' => 'Tan {name}', 'input' => '{raw[0]}', "
            f"'inputQty' => 3, 'output' => '{key}', 'outputQty' => 1, "
            f"'baseSeconds' => {minutes} * 60, 'skill' => 'hunting'],\n"
        )
    o.write('    ];\n\n')

    o.write('    /** §7.2 -- every rung of this ladder belongs to the hunting line. */\n')
    o.write('    public const SKILL_FOR_MATERIAL = [\n')
    for _grade, raw, _ in LINE:
        o.write(f"        '{raw[0]}' => 'hunting',\n")
    o.write('    ];\n\n')

    o.write('    /** §5.5 -- what a kill gives up beside the hide. */\n')
    o.write('    public const PARTS = [' + ', '.join(f"'{k}'" for k in PARTS) + '];\n\n')
    o.write('    /** §5.5 -- the part only an uncommon animal or better gives up. */\n')
    o.write(f"    public const GRADED_PART = '{GRADED_PART}';\n\n")
    o.write(f"    public const GRADED_FROM = '{GRADED_FROM}';\n\n")
    o.write('    /** §4 -- the tier-0 rubbish carried out alongside, every time. */\n')
    o.write(f"    public const JUNK = '{JUNK}';\n\n")
    o.write('    /** §9.5.8 -- the tier-0 leaving that says where the kill happened. */\n')
    o.write(f"    public const LEAVING = '{LEAVING}';\n\n")
    o.write('    /** The two that are neither hide nor ladder: a graded part and a leaving. */\n')
    o.write('    public const EXTRA = [\n')
    for key, name, tier, palette, price, desc in EXTRA:
        o.write(f"        '{key}' => ['name' => {php_str(name)}, 'tier' => {tier}, "
                f"'source' => 'hunt', 'palette' => '{palette}', 'npcPrice' => {price}, "
                f"'description' => {php_str(desc)}],\n")
    o.write('    ];\n\n')
    o.write('    /** Biome -> grade -> which animal that is. */\n')
    o.write('    public const BY_BIOME_GRADE = [\n')
    for biome in BIOMES:
        o.write(f"        '{biome}' => [\n")
        for grade, _, _ in GRADES:
            key = next(a[0] for a in ANIMALS if a[2] == biome and a[3] == grade)
            o.write(f"            '{grade}' => '{key}',\n")
        o.write('        ],\n')
    o.write('    ];\n}\n')
    return o.getvalue()


def emit_ts():
    o = io.StringIO()
    doc(o, HEADER)
    o.write("import type { Animal, Material, Recipe } from './types'\n\n")
    o.write('export const HUNT_BIOMES = [' + ', '.join(f"'{b}'" for b in BIOMES) + '] as const\n\n')
    o.write('export const HUNT_GRADES: Array<{ grade: string; material: string; weights: Record<string, number> }> = [\n')
    for grade, material, weights in GRADES:
        w = ', '.join(f"{r}: {v}" for r, v in weights.items())
        o.write(f"  {{ grade: '{grade}', material: '{material}', weights: {{ {w} }} }},\n")
    o.write(']\n\n')
    o.write('export const ANIMALS: Record<string, Animal> = {\n')
    for key, name, biome, grade, desc in ANIMALS:
        material = next(m for g, m, _ in GRADES if g == grade)
        o.write(
            f"  {key}: {{ key: '{key}', name: {php_str(name)}, biome: '{biome}', "
            f"grade: '{grade}', material: '{material}', description: {php_str(desc)} }},\n"
        )
    o.write('}\n\n')
    o.write("import type { Material, Recipe } from './types'\n" if False else '')
    o.write('export const HUNT_RAW: Material[] = [\n')
    for grade, raw, _ in LINE:
        key, name, price = raw
        o.write(
            f"  {{ key: '{key}', name: {php_str(name)}, tier: 1, source: 'hunt', "
            f"palette: 'pelt', npcPrice: {price}, "
            f"description: {php_str(DESCRIPTIONS[key])} }},\n"
        )
    o.write(']\n\n')
    o.write('export const HUNT_REFINED: Material[] = [\n')
    for grade, _, refined in LINE:
        if refined is None:
            continue
        key, name, price, _m = refined
        o.write(
            f"  {{ key: '{key}', name: {php_str(name)}, tier: 2, palette: 'pelt', "
            f"npcPrice: {price}, description: {php_str(DESCRIPTIONS[key])} }},\n"
        )
    o.write(']\n\n')
    o.write('export const HUNT_PROCESSING: Recipe[] = [\n')
    for grade, raw, refined in LINE:
        if refined is None:
            continue
        key, name, _price, minutes = refined
        o.write(
            f"  {{ key: 'tan_{key}', name: 'Tan {name}', input: '{raw[0]}', inputQty: 3, "
            f"output: '{key}', outputQty: 1, baseSeconds: {minutes} * 60, skill: 'hunting' }},\n"
        )
    o.write(']\n\n')
    o.write('/** §5.5 -- what a kill gives up beside the hide. */\n')
    o.write('export const HUNT_PARTS = [' + ', '.join(f"'{k}'" for k in PARTS) + '] as const\n\n')
    o.write('/** §5.5 -- the part only an uncommon animal or better gives up. */\n')
    o.write(f"export const HUNT_GRADED_PART = '{GRADED_PART}'\n\n")
    o.write(f"export const HUNT_GRADED_FROM = '{GRADED_FROM}'\n\n")
    o.write('/** §4 -- the tier-0 rubbish carried out alongside, every time. */\n')
    o.write(f"export const HUNT_JUNK = '{JUNK}'\n\n")
    o.write('/** §9.5.8 -- the tier-0 leaving that says where the kill happened. */\n')
    o.write(f"export const HUNT_LEAVING = '{LEAVING}'\n\n")
    o.write('/** The two that are neither hide nor ladder: a graded part and a leaving. */\n')
    o.write('export const HUNT_EXTRA: Material[] = [\n')
    for key, name, tier, palette, price, desc in EXTRA:
        o.write(f"  {{ key: '{key}', name: {ts_str(name)}, tier: {tier}, source: 'hunt', "
                f"palette: '{palette}', npcPrice: {price}, description: {ts_str(desc)} }},\n")
    o.write(']\n\n')
    o.write('export const ANIMAL_BY_BIOME_GRADE: Record<string, Record<string, string>> = {\n')
    for biome in BIOMES:
        o.write(f"  {biome}: {{\n")
        for grade, _, _ in GRADES:
            key = next(a[0] for a in ANIMALS if a[2] == biome and a[3] == grade)
            o.write(f"    {grade}: '{key}',\n")
        o.write('  },\n')
    o.write('}\n')
    return o.getvalue()


if __name__ == '__main__':
    keys = [a[0] for a in ANIMALS]
    assert len(keys) == len(set(keys)), 'duplicate animal key'
    assert len(ANIMALS) == len(BIOMES) * len(GRADES), 'every country needs one per grade'

    for biome in BIOMES:
        mine = [a for a in ANIMALS if a[2] == biome]
        assert {a[3] for a in mine} == {g[0] for g in GRADES}, f'{biome} is missing a grade'

    # §5.3 -- the weights are a distribution over the grades, per ring, and the
    # generator is where that is checked rather than at runtime.
    for ring in ('outer', 'mid', 'inner'):
        total = sum(w[ring] for _, _, w in GRADES)
        assert abs(total - 1.0) < 1e-9, f'{ring} weights sum to {total}'

    # §2 -- and the contested rung is reachable from the contested ring alone.
    contested = next(w for g, _, w in GRADES if g == 'contested')
    assert contested['outer'] == 0.0 and contested['mid'] == 0.0, \
        'a Tier 3 leaked onto the safe rings'

    open('app/Game/Hunts.php', 'w').write(emit_php())
    open('resources/js/game/hunts.ts', 'w').write(emit_ts())
    print(f'{len(ANIMALS)} animals across {len(BIOMES)} biomes, {len(GRADES)} grades')
