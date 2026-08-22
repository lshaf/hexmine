"""Emit app/Game/Variants.php and resources/js/game/variants.ts from one spec.

§5.3 -- a biome is not one kind of ground. Each of the five carries four tile
variants, one per equipment rung, and a variant is what decides which grade of
material the hex gives up. Twenty variants, twenty materials, ten processing
recipes and twenty tints, all of which have to agree across PHP, TypeScript and
the map renderer -- so none of them is written by hand.

    python3 scripts/gen_variants.py

The ladder is chosen to be physically sensible, not just numerically ordered.
Gold is deliberately absent: it is soft, and a gold pick would be worse than
the iron one below it. Hematite and meteoric iron are the mountain's answer,
both of which really do make a better tool than bog ore.

Read the diff afterwards, then regenerate the worldgen fixture -- the variant
roll changes what every hex on the map gives up.
"""
import io

# ------------------------------------------------------------------- weights
# Which rings a grade may spawn in, and how often. §5.2 -- the outer rim is
# safe and poor, the contested inner ring is where the good ground is. The
# `epic` column is Balance::RARE_SPAWN_CHANCE and must stay 0.18, or §5.3's
# rare density moves.
#
# Each ring's column sums to 1.0. The walk that picks a variant relies on it.
WEIGHTS = {
    #           outer   mid    inner
    'common':   (1.00,  0.70,  0.42),
    'uncommon': (0.00,  0.30,  0.25),
    'rare':     (0.00,  0.00,  0.15),
    'epic':     (0.00,  0.00,  0.18),
}

GRADES = ['common', 'uncommon', 'rare', 'epic']
RINGS = ['outer', 'mid', 'inner']

PALETTE = {
    'forest': 'wood', 'mountain': 'iron', 'plains': 'pelt',
    'badlands': 'stone', 'grassland': 'fiber',
}

# --------------------------------------------------------------------- spec
# Per biome, four variants in grade order. Each carries:
#
#   tile      the ground's own name, what the map calls it
#   tint      §13.2 fill for the hex, a shift of the base biome colour
#   raw       tier 1 material the hex gives up  (key, Name, npcPrice)
#   refined   tier 2 it processes into          (key, Name, npcPrice, minutes)
#   props     which prop treatment props.ts draws
#
# The epic row is the §4 Tier 3 rare that already exists: capped per wallet,
# never refined, and used raw by the NFT recipes. It gets a variant here so it
# stops borrowing the base biome's sprite, which is the whole point.
SPEC = {
    'forest': [
        ('common',   'Forest',         '#5f8058', ('wood',           'Wood',            2), ('planks',          'Planks',           7, 12), 'conifers'),
        ('uncommon', 'Hardwood Stand', '#6b8a4e', ('hardwood',       'Hardwood',        4), ('beams',           'Beams',           14, 16), 'broadleaf'),
        ('rare',     'Old Growth',     '#46654a', ('heartoak',       'Heartoak',        7), ('bentwood',        'Bentwood',        26, 22), 'giants'),
        ('epic',     'Ironwood Grove', '#55705f', ('ironwood',       'Ironwood',        0), None,                                            'ironwood'),
    ],
    'mountain': [
        ('common',   'Mountain',       '#6d8399', ('iron_ore',       'Iron Ore',        3), ('ingots',          'Ingots',           9, 15), 'peaks'),
        ('uncommon', 'Hematite Ridge', '#8a7a72', ('hematite',       'Hematite',        5), ('steel_ingots',    'Steel Ingots',    18, 19), 'banded'),
        ('rare',     'Crater Field',   '#5c6b7d', ('meteoric_iron',  'Meteoric Iron',   9), ('skysteel',        'Skysteel',        32, 26), 'crater'),
        ('epic',     'Mythril Seam',   '#7d93a8', ('mythril_ore',    'Mythril Ore',     0), None,                                            'mythril'),
    ],
    'plains': [
        ('common',   'Plains',         '#b08a5a', ('pelt',           'Pelt',            3), ('leather',         'Leather',          8, 13), 'tufts'),
        ('uncommon', 'Herd Range',     '#bb9a63', ('thick_pelt',     'Thick Pelt',      5), ('boiled_leather',  'Boiled Leather',  16, 17), 'grazed'),
        ('rare',     'Dire Range',     '#96703f', ('dire_pelt',      'Dire Pelt',       9), ('lacquered_hide',  'Lacquered Hide',  30, 24), 'bones'),
        ('epic',     'Beastfang Reach','#a37452', ('beastfang_hide', 'Beastfang Hide',  0), None,                                            'fangs'),
    ],
    'badlands': [
        ('common',   'Badlands',       '#96604c', ('stone',          'Stone',           2), ('cut_stone',       'Cut Stone',        7, 12), 'shards'),
        ('uncommon', 'Basalt Flats',   '#7a5347', ('basalt',         'Basalt',          4), ('dressed_basalt',  'Dressed Basalt',  15, 16), 'columns'),
        ('rare',     'Granite Shelf',  '#a1756a', ('granite',        'Granite',         8), ('polished_granite','Polished Granite',28, 23), 'shelf'),
        ('epic',     'Obsidian Flow',  '#5f4a52', ('obsidian_shard', 'Obsidian Shard',  0), None,                                            'glass'),
    ],
    'grassland': [
        ('common',   'Grassland',      '#a8a05c', ('fiber',          'Fiber',           2), ('cloth',           'Cloth',            6, 11), 'tufts'),
        ('uncommon', 'Flax Meadow',    '#b6b073', ('flax',           'Flax',            4), ('linen',           'Linen',           13, 15), 'flowering'),
        ('rare',     'Hemp Field',     '#8f9552', ('hemp',           'Hemp',            7), ('canvas',          'Canvas',          25, 21), 'tall'),
        ('epic',     'Silkweave Fen',  '#9fa878', ('silkweave_fiber','Silkweave Fiber', 0), None,                                            'silk'),
    ],
}

# Which gathering skill each biome's line belongs to, for the processing recipe.
SKILL = {
    'forest': 'woodcutting', 'mountain': 'mining', 'plains': 'hunting',
    'badlands': 'quarrying', 'grassland': 'harvesting',
}

# What a processing run is called, per biome. The verb does not change with
# grade -- sawing is sawing, whatever went on the bench.
VERB = {
    'forest': 'Saw', 'mountain': 'Smelt', 'plains': 'Tan',
    'badlands': 'Dress', 'grassland': 'Weave',
}

DESCRIPTIONS = {
    'hardwood': 'Broadleaf, felled standing. Twice the weight of green pine and worth carrying.',
    'heartoak': 'The dark core of something that outlived everyone who saw it planted.',
    'hematite': 'Ore rich enough that the slag heap is barely worth picking over.',
    'meteoric_iron': 'Came down in the crater field. Takes an edge nothing smelted can hold.',
    'thick_pelt': 'Winter coat off a full-grown animal. Heavy, and it keeps its shape.',
    'dire_pelt': 'Off something that had no natural enemies until you turned up.',
    'basalt': 'Cooled in columns, and it splits along them. Harder than the shale next door.',
    'granite': 'Speckled and stubborn. It blunts what you dress it with.',
    'flax': 'Retted in the ditch for a fortnight. Spins finer than field grass ever will.',
    'hemp': 'Stems taller than a man. Nothing ordinary breaks a rope of it.',

    'beams': 'Squared off the log rather than sawn thin. Takes a load without complaining.',
    'bentwood': 'Steamed, bent round a form, and left to set. It holds the curve forever.',
    'steel_ingots': 'Carbon worked through the iron. Springs back where plain bar stays bent.',
    'skysteel': 'Folded out of what fell. Pale, and it rings a long time after the strike.',
    'boiled_leather': 'Boiled hard and moulded wet. Sets like a shell and weighs nothing.',
    'lacquered_hide': 'Layered, lacquered, and left in the dark to cure. Turns a blade.',
    'dressed_basalt': 'Squared out of the column. Every face is already flat; you only true it.',
    'polished_granite': 'Ground down through four grits. The finish is what makes it last.',
    'linen': 'Woven off the flax line. Cool, strong, and it takes a dye.',
    'canvas': 'Hemp beaten flat and woven close. Rain runs off it and rope does not cut it.',
}

TILE_DESCRIPTIONS = {
    'Forest': 'Close conifer, worked since anybody got here. Where the line starts.',
    'Mountain': 'Bare rock and scree above the treeline. Every range begins as this.',
    'Plains': 'Open grazing. The herds cross it and so does everybody else.',
    'Badlands': 'Broken shale on burnt ground. It gives up stone and very little else.',
    'Grassland': 'Waist-high and going nowhere. The cheapest fibre there is.',
    'Hardwood Stand': 'Broadleaf, close-grown, and dark underneath.',
    'Old Growth': 'Nothing here has been cut in living memory.',
    'Ironwood Grove': 'The trunks ring when struck. Contested ground.',
    'Hematite Ridge': 'The scree runs red where the rain has been at it.',
    'Crater Field': 'Something landed here, and the ground has not closed over it.',
    'Mythril Seam': 'A pale line through the rock that hums. Contested ground.',
    'Herd Range': 'Cropped short and trodden through. They come back to it.',
    'Dire Range': 'The herds keep off this stretch, and they are right to.',
    'Beastfang Reach': 'Bones at the treeline, none of them small. Contested ground.',
    'Basalt Flats': 'Cooled into columns and cracked square along them.',
    'Granite Shelf': 'A speckled slab the weather has never got under.',
    'Obsidian Flow': 'Black glass, still sharp where it broke. Contested ground.',
    'Flax Meadow': 'Blue at the top for a week a year, then worth cutting.',
    'Hemp Field': 'Stems over head height. You lose sight of the next hex in it.',
    'Silkweave Fen': 'Something has been spinning in the tall grass. Contested ground.',
}


def rows():
    """Flatten the spec into (biome, grade, tile, tint, raw, refined, props)."""
    for biome, variants in SPEC.items():
        for grade, tile, tint, raw, refined, props in variants:
            yield biome, grade, tile, tint, raw, refined, props


def variant_key(biome, grade):
    return biome if grade == 'common' else f'{biome}_{grade}'


def new_raws():
    """Tier 1 the spec introduces -- everything but the common and epic rows,
    which the §4 catalog already carries."""
    for biome, grade, _, _, raw, _, _ in rows():
        if grade in ('uncommon', 'rare'):
            yield biome, raw


def new_refined():
    """Tier 2 the spec introduces, same argument."""
    for biome, grade, _, _, _, refined, _ in rows():
        if grade in ('uncommon', 'rare'):
            yield biome, refined


def processing():
    """One recipe per new Tier 2. Three raw in, one refined out, exactly as the
    five §4 lines already work -- a better grade is a better material, never a
    better ratio, or the grade ladder would double as a yield ladder."""
    for biome, grade, _, _, raw, refined, _ in rows():
        if grade not in ('uncommon', 'rare'):
            continue
        yield {
            'key': refined[0],
            'name': f'{VERB[biome]} {refined[1]}',
            'input': raw[0],
            'output': refined[0],
            'minutes': refined[3],
            'skill': SKILL[biome],
        }


# ------------------------------------------------------------------ emitters

def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def ts_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


HEADER = """Generated by scripts/gen_variants.py -- do not edit by hand.

§5.3 -- four tile variants per biome, one per equipment rung, and the variant
is what decides which grade of material the hex gives up. The Tier 3 rare is
one of the four, so contested ground stops borrowing the base biome's sprite."""


def emit_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(' */\nfinal class Variants\n{\n')

    o.write("    /** The four grades, in ladder order. A variant's grade is its rung. */\n")
    o.write('    public const GRADES = [' + ', '.join(php_str(g) for g in GRADES) + "];\n\n")

    o.write('    /**\n')
    o.write('     * Biome -> its four variants, in grade order.\n')
    o.write('     *\n')
    o.write("     * `weights` is the chance this variant is what a hex turns out to be,\n")
    o.write('     * per ring. Each ring column sums to 1 across a biome, and the walk in\n')
    o.write('     * WorldGen::variantOf() depends on that.\n')
    o.write('     */\n')
    o.write('    public const BIOME_VARIANTS = [\n')
    for biome, variants in SPEC.items():
        o.write(f"        '{biome}' => [\n")
        for grade, tile, tint, raw, refined, props in variants:
            w = WEIGHTS[grade]
            o.write(
                f"            ['key' => {php_str(variant_key(biome, grade))}, "
                f"'grade' => '{grade}', 'name' => {php_str(tile)}, "
                f"'material' => '{raw[0]}', 'tint' => '{tint}', 'props' => '{props}', "
                f"'weights' => ['outer' => {w[0]}, 'mid' => {w[1]}, 'inner' => {w[2]}]],\n"
            )
        o.write('        ],\n')
    o.write('    ];\n\n')

    o.write('    /** §4 Tier 1 -- the grades above the base raw, biome-locked like it. */\n')
    o.write('    public const RAW = [\n')
    for biome, (key, name, price) in new_raws():
        o.write(f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 1, 'biome' => '{biome}', "
                f"'palette' => '{PALETTE[biome]}', 'npcPrice' => {price}, "
                f"'description' => {php_str(DESCRIPTIONS[key])}],\n")
    o.write('    ];\n\n')

    o.write('    /** §4 Tier 2 -- what each grade refines into. Not biome-locked. */\n')
    o.write('    public const REFINED = [\n')
    for biome, (key, name, price, _) in new_refined():
        o.write(f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 2, "
                f"'palette' => '{PALETTE[biome]}', 'npcPrice' => {price}, "
                f"'description' => {php_str(DESCRIPTIONS[key])}],\n")
    o.write('    ];\n\n')

    o.write('    /** §6 -- one processing recipe per grade, same 3:1 as the base line. */\n')
    o.write('    public const PROCESSING = [\n')
    for r in processing():
        o.write(f"        '{r['key']}' => ['name' => {php_str(r['name'])}, 'input' => '{r['input']}', "
                f"'inputQty' => 3, 'output' => '{r['output']}', 'outputQty' => 1, "
                f"'baseSeconds' => {r['minutes']} * 60, 'skill' => '{r['skill']}'],\n")
    o.write('    ];\n\n')

    o.write('    /**\n')
    o.write('     * Which gathering line a grade belongs to, §7.2.\n')
    o.write('     *\n')
    o.write('     * Catalog::skillForMaterial() falls back to woodcutting for anything it\n')
    o.write('     * does not know, which would credit a hematite haul to the wrong tree.\n')
    o.write('     */\n')
    o.write('    public const SKILL_FOR_MATERIAL = [\n')
    for biome, (key, _, _) in new_raws():
        o.write(f"        '{key}' => '{SKILL[biome]}',\n")
    o.write('    ];\n}\n')

    return o.getvalue()


def emit_ts():
    o = io.StringIO()
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(" */\nimport type { Biome, Material, Recipe, SkillKey, VariantKey } from './types'\n\n")

    o.write('export interface VariantDef {\n')
    o.write('  key: VariantKey\n')
    o.write("  grade: 'common' | 'uncommon' | 'rare' | 'epic'\n")
    o.write('  name: string\n')
    o.write('  material: string\n')
    o.write('  tint: string\n')
    o.write('  props: string\n')
    o.write('  weights: { outer: number; mid: number; inner: number }\n')
    o.write('}\n\n')

    o.write('export const BIOME_VARIANTS: Record<Biome, VariantDef[]> = {\n')
    for biome, variants in SPEC.items():
        o.write(f'  {biome}: [\n')
        for grade, tile, tint, raw, refined, props in variants:
            w = WEIGHTS[grade]
            o.write(
                f"    {{ key: {ts_str(variant_key(biome, grade))}, grade: '{grade}', "
                f"name: {ts_str(tile)}, material: '{raw[0]}', tint: '{tint}', props: '{props}', "
                f"weights: {{ outer: {w[0]}, mid: {w[1]}, inner: {w[2]} }} }},\n"
            )
        o.write('  ],\n')
    o.write('}\n\n')

    o.write('/** §13.2 -- the hex fill for a variant. Solid, never alpha. */\n')
    o.write('export const VARIANT_TINT: Record<VariantKey, string> = {\n')
    for biome, grade, tile, tint, raw, refined, props in rows():
        o.write(f"  {variant_key(biome, grade)}: '{tint}',\n")
    o.write('}\n\n')

    o.write('/** What the map calls the ground under your feet. */\n')
    o.write('export const VARIANT_LABEL: Record<VariantKey, string> = {\n')
    for biome, grade, tile, tint, raw, refined, props in rows():
        o.write(f'  {variant_key(biome, grade)}: {ts_str(tile)},\n')
    o.write('}\n\n')

    o.write('export const VARIANT_DESCRIPTION: Record<VariantKey, string> = {\n')
    for biome, grade, tile, tint, raw, refined, props in rows():
        desc = TILE_DESCRIPTIONS.get(tile, '')
        o.write(f'  {variant_key(biome, grade)}: {ts_str(desc)},\n')
    o.write('}\n\n')

    o.write('export const VARIANT_RAW: Material[] = [\n')
    for biome, (key, name, price) in new_raws():
        o.write(f"  {{ key: '{key}', name: {ts_str(name)}, tier: 1, biome: '{biome}', "
                f"palette: '{PALETTE[biome]}', npcPrice: {price}, "
                f"description: {ts_str(DESCRIPTIONS[key])} }},\n")
    o.write(']\n\n')

    o.write('export const VARIANT_REFINED: Material[] = [\n')
    for biome, (key, name, price, _) in new_refined():
        o.write(f"  {{ key: '{key}', name: {ts_str(name)}, tier: 2, "
                f"palette: '{PALETTE[biome]}', npcPrice: {price}, "
                f"description: {ts_str(DESCRIPTIONS[key])} }},\n")
    o.write(']\n\n')

    o.write('/** §6 -- one line per grade, same 3:1 as the five base lines. */\n')
    o.write('export const VARIANT_PROCESSING: Recipe[] = [\n')
    for r in processing():
        o.write(f"  {{ key: '{r['key']}', name: {ts_str(r['name'])}, input: '{r['input']}', "
                f"inputQty: 3, output: '{r['output']}', outputQty: 1, "
                f"baseSeconds: {r['minutes']} * 60, skill: '{r['skill']}' }},\n")
    o.write(']\n\n')

    o.write('/** §13.2 -- which prop treatment props.ts draws for a variant. */\n')
    o.write('export const VARIANT_PROPS: Record<VariantKey, string> = {\n')
    for biome, grade, tile, tint, raw, refined, props in rows():
        o.write(f"  {variant_key(biome, grade)}: '{props}',\n")
    o.write('}\n\n')

    o.write('/** §5.3 -- which ground a material comes off, for the almanac. */\n')
    o.write('export const VARIANT_BY_MATERIAL: Record<string, VariantKey> = {\n')
    for biome, grade, tile, tint, raw, refined, props in rows():
        o.write(f"  {raw[0]}: '{variant_key(biome, grade)}',\n")
    o.write('}\n\n')

    o.write('/**\n')
    o.write(' * §5.2 -- how far in you have to walk before a grade turns up at all.\n')
    o.write(' * Derived from the weight table, so it cannot disagree with the roll.\n')
    o.write(' */\n')
    o.write('export const VARIANT_RINGS: Record<VariantKey, string[]> = {\n')
    for biome, grade, tile, tint, raw, refined, props in rows():
        rings = [r for i, r in enumerate(RINGS) if WEIGHTS[grade][i] > 0]
        o.write(f"  {variant_key(biome, grade)}: [{', '.join(ts_str(r) for r in rings)}],\n")
    o.write('}\n\n')

    o.write('/** §7.2 -- which gathering line a grade belongs to. */\n')
    o.write('export const VARIANT_SKILL: Record<string, SkillKey> = {\n')
    for biome, (key, _, _) in new_raws():
        o.write(f"  {key}: '{SKILL[biome]}',\n")
    o.write('}\n')

    return o.getvalue()


def emit_keys():
    """The VariantKey union, for hand-pasting into types.ts if it ever drifts."""
    return '\n'.join(f"  | '{variant_key(b, g)}'" for b, g, *_ in rows())


if __name__ == '__main__':
    seen = set()
    for biome, grade, tile, tint, raw, refined, props in rows():
        assert raw[0] not in seen, f'duplicate material {raw[0]}'
        seen.add(raw[0])
        if grade == 'epic':
            assert refined is None, f'{raw[0]} is Tier 3 and must not refine'
        else:
            assert refined is not None, f'{raw[0]} has nothing to refine into'
            assert refined[0] not in seen, f'duplicate material {refined[0]}'
            seen.add(refined[0])

    for biome in SPEC:
        assert len(SPEC[biome]) == 4, f'{biome} does not have four variants'
        assert [v[0] for v in SPEC[biome]] == GRADES, f'{biome} is out of grade order'

    for i, ring in enumerate(RINGS):
        total = round(sum(WEIGHTS[g][i] for g in GRADES), 6)
        assert total == 1.0, f'{ring} weights sum to {total}, not 1'

    assert WEIGHTS['epic'][2] == 0.18, 'the Tier 3 rate moved off RARE_SPAWN_CHANCE'

    open('app/Game/Variants.php', 'w').write(emit_php())
    open('resources/js/game/variants.ts', 'w').write(emit_ts())
    print(f'20 variants, {len(list(new_raws()))} raw, {len(list(new_refined()))} refined, '
          f'{len(list(processing()))} processing recipes')
    print('\nVariantKey union for types.ts:\n' + emit_keys())
