"""Emit app/Game/Alchemy.php and resources/js/game/alchemy.ts from one spec.

Seventy consumables and fifteen materials, hand-mirrored across PHP and
TypeScript, is precisely the drift the catalog has no parity test for -- so
neither side is written by hand. Same argument as gen_jobs.py: the shape is
regular, only the names and the flavour are not.

    python3 scripts/gen_alchemy.py

Read the diff afterwards. Every changed line is a recipe or a price somebody's
character is already holding.
"""
import io

# ---------------------------------------------------------------- vocabulary

BIOMES = ['forest', 'mountain', 'plains', 'badlands', 'grassland']

LINE = {
    'forest': 'woodcutting', 'mountain': 'mining', 'plains': 'hunting',
    'badlands': 'quarrying', 'grassland': 'harvesting',
}
PALETTE = {
    'forest': 'wood', 'mountain': 'iron', 'plains': 'pelt',
    'badlands': 'stone', 'grassland': 'fiber',
}
# ------------------------------------------------------------------ reagents
# §4 Tier 1: raw, biome-locked. Two per biome so a recipe can want two different
# things from one place. Every one sells for MORE than the 1-gold scrap floor,
# which §4.0 makes a rule rather than a tuning value.
#
# All ten are BOTANICAL, and that is a rule rather than flavour. The consumable
# bench is the herbalist's, so its whole stock is something that grows: a shelf
# with a mineral and a bone on it reads as a smithy, and the three benches are
# only legible apart because their stocks are.
REAGENTS = [
    ('toadstool',   'Toadstool',   'forest',    3, 'Pulled from the shaded side of a stump. Bitter enough to work.'),
    ('birch_sap',   'Birch Sap',   'forest',    2, 'Tapped in the cold hour. Runs slow and keeps well.'),
    ('lichen',      'Lichen',      'mountain',  3, 'Scraped off north-facing rock. It grows a finger a decade.'),
    ('stonewort',   'Stonewort',   'mountain',  4, 'Grows out of bare rock on nothing at all. Bitter, and it keeps.'),
    ('bitterroot',  'Bitterroot',  'plains',    3, 'Dug where the herds will not graze. They know why.'),
    ('yarrow',      'Yarrow',      'plains',    4, 'Flat white heads over the whole range. Every field surgeon carries it.'),
    ('ashcap',      'Ashcap',      'badlands',  3, 'Comes up grey on burnt ground, a season after the fire.'),
    ('sagebrush',   'Sagebrush',   'badlands',  4, 'Silver-leaved and shin-high. Burns sweet and steeps sweeter.'),
    ('blue_nettle', 'Blue Nettle', 'grassland', 2, 'Stings through leather. Worth the hands it costs.'),
    ('clover',      'Clover',      'grassland', 2, 'Common as dirt, and half the shelf starts here.'),
]

# ---------------------------------------------------------------------- junk
# §4.0 Tier 0. Sells for one gold, feeds no recipe, reaches no tier. It exists
# to be sold and nothing else, which is why it is outside the material count.
JUNK = [
    ('deadfall',      'Deadfall',      'forest',    'A rotted limb off the floor. Too far gone to plank.'),
    ('slag',          'Slag',          'mountain',  'Spoil from an old working. Somebody already took the iron.'),
    ('bone_splinter', 'Bone Splinter', 'plains',    'Picked clean long before you got there.'),
    ('cinder',        'Cinder',        'badlands',  'Scraped off a burn scar. The trader takes it by weight.'),
    ('thistle',       'Thistle',       'grassland', 'Cut and bundled. Nothing spins it.'),
]

# --------------------------------------------------------------------- ranks
# value == that rarity's §8.0 stat ceiling, so a potion climbs the same ladder
# equipment does and stops where §8.1 rule 1 stops everything.
#
# `mats` is how many DIFFERENT materials the recipe wants, and it never rises
# with rank: a common draught is a muddle of four cheap things, a legendary
# philtre is two perfect ones.
#
# Nothing here is tradeable. §2 lets an item be an NFT only when a per-wallet
# cap stands behind its inputs, and the bench now runs on reagents alone --
# Tier 1, uncapped, and mined by anyone. A tradeable rung on top of that would
# be the grind-to-external-value path the threat model exists to close, so the
# potion shelf stops at prestige.
RANKS = [
    # rarity,      value, station,   tradeable, mats
    ('common',     0.03,  'village', False,     4),
    ('uncommon',   0.05,  'city',    False,     3),
    ('rare',       0.08,  'capital', False,     3),
    ('epic',       0.11,  'capital', False,     2),
    ('legendary',  0.14,  'guild',   False,     2),
]

VESSEL = {
    'common': 'Draught', 'uncommon': 'Tonic', 'rare': 'Flask',
    'epic': 'Elixir', 'legendary': 'Philtre',
}

# ------------------------------------------------------------------- effects
# Every buff names an ACTION, not just a stat. That is what lets sixty potions
# exist without sixty of them stacking: two are only rivals when they buff the
# same stat on the same action, and the unique index says so.
YIELD_WORD = {
    'woodcutting': 'Forest', 'mining': 'Deepseam', 'hunting': 'Beastcall',
    'quarrying': 'Stonecut', 'harvesting': 'Fieldwise',
}
SPEED_WORD = {
    'woodcutting': 'Swiftaxe', 'mining': 'Quickpick', 'hunting': 'Lightfoot',
    'quarrying': 'Quarry', 'harvesting': 'Sicklehand',
}

# Where a scope's ingredients come from, for scopes that are not a biome line.
# `battle` is the odd one: its recipe runs on the ichor line (§9.5.8) rather
# than on a biome's herbs, and the biome named here only decides its palette.
SCOPE_BIOME = dict(
    {LINE[b]: b for b in BIOMES},
    **{'travel': 'grassland', 'processing': 'plains', 'battle': 'plains'},
)

# §9.5.8 -- the ichor grade each rung is brewed from. A battle draught is the
# one thing on the shelf that cannot be gathered: every rung waits on a fight,
# and the top two wait on a fight in the barren centre.
BATTLE_ICHOR = {
    'common': 'thin_ichor', 'uncommon': 'black_blood', 'rare': 'bile_sac',
    'epic': 'ember_gland', 'legendary': 'grave_heart',
}

# Keys already in the catalog and already referenced by tests, the demo seeder
# and the almanac. Pinned to the slot they naturally fall in so nothing that
# points at them breaks.
PINNED = {
    ('common', 'yield', 'woodcutting'): ('forest_draught', 'Forest Draught',
        'Bitter, resinous, and it keeps your arms swinging through the next stand of trees.'),
    ('common', 'travelSpeed', 'travel'): ('road_tonic', 'Road Tonic',
        'Drunk at the gate, not on the road. Your legs stop asking questions.'),
    ('uncommon', 'tripReduction', 'quarrying'): ('quarry_salts', 'Quarry Salts',
        'Tastes like the inside of a mine. You work faster to be done sooner.'),
    ('uncommon', 'processingSpeed', 'processing'): ('guild_cordial', 'Guild Cordial',
        'What the line foremen drink. The queue does not move faster; you do.'),
    ('rare', 'yield', 'mining'): ('prospectors_flask', "Prospector's Flask",
        'Capital-blended and priced like it. Every seam gives up a little more.'),
}

FLAVOUR_YIELD = {
    'woodcutting': 'The timber comes off the stump cleaner and there is more of it.',
    'mining': 'You start seeing the seam where you were seeing rock.',
    'hunting': 'The herd holds still a half-second longer than it should.',
    'quarrying': 'The face splits where you meant it to, and gives up the whole block.',
    'harvesting': 'You crop at the root and lose nothing to the stubble.',
}
FLAVOUR_SPEED = {
    'woodcutting': 'Fewer swings, the same tree, and you are on the road sooner.',
    'mining': 'The pick finds the fault first go and the shift ends early.',
    'hunting': 'You cut the tracking short because you already know where it went.',
    'quarrying': 'No wasted blows on a face you have already read.',
    'harvesting': 'The blade does not snag and the row is done before you notice.',
}


def consumables():
    """Fourteen per rank: yield and trip time on each of the five lines, plus the
    road, the bench and the fight. Seventy in all, every one action-locked."""
    out = []

    for rarity, value, station, tradeable, mats in RANKS:
        effects = []
        for b in BIOMES:
            effects.append(('yield', LINE[b], YIELD_WORD[LINE[b]], FLAVOUR_YIELD[LINE[b]]))
        for b in BIOMES:
            effects.append(('tripReduction', LINE[b], SPEED_WORD[LINE[b]], FLAVOUR_SPEED[LINE[b]]))
        # 'Wayfarer', not 'Road': Road Tonic is pinned at common, and the
        # legacy names do not use their own rank's vessel, so a generated
        # "Road Tonic" at uncommon would collide with it.
        effects.append(('travelSpeed', 'travel', 'Wayfarer',
                        'The miles stop counting themselves at you.'))
        effects.append(('processingSpeed', 'processing', 'Guild',
                        'You are more use at the bench than you were an hour ago.'))
        # §9.5 -- the two dormant stats, and the only scope that is not work.
        effects.append(('power', 'battle', 'Warcry',
                        'You hit first, and you hit like you meant it.'))
        effects.append(('defence', 'battle', 'Ironhide',
                        'Whatever lands, lands somewhere else.'))

        for stat, scope, word, flavour in effects:
            pin = PINNED.get((rarity, stat, scope))
            if pin:
                key, name, desc = pin
            else:
                name = f'{word} {VESSEL[rarity]}'
                key = name.lower().replace(' ', '_').replace("'", '')
                desc = flavour

            out.append({
                'key': key, 'name': name, 'rarity': rarity, 'tradeable': tradeable,
                'stat': stat, 'scope': scope, 'value': value, 'station': station,
                'palette': PALETTE[SCOPE_BIOME[scope]],
                'inputs': inputs_for(rarity, scope, mats),
                'description': desc,
            })

    return out


def inputs_for(rarity, scope, mats):
    """Reagents and nothing else. Recipes get shorter and sharper as they climb.

    The bench runs on its own stock (§4): ten herbs and five critters feed
    potions, and nothing a smith or an armorer would want is on the list. Two
    crafters never bid against each other for the same pile, so an alchemist's
    demand cannot price a gathering line out of its own equipment ladder.

    The top three rungs want the biome's critter, and that is a gate rather than
    a flavour note. A herb is gathered -- no tool, any hex, whenever you like. A
    critter is hunted, which needs a bow AND a live herd, and §5.5 puts herds on
    a four-hour clock. So the cheap end of the shelf is something you can always
    top up and the dear end waits on an animal turning up.

    Rank is carried by depth instead of by breadth. A common draught is a
    muddle of four cheap things pulled off two kinds of ground; a legendary
    philtre is the local pair, in quantity, and nothing else.

    NOTE: epic and legendary no longer want a Tier 3 rare, so the per-wallet
    cap that §8.5 used to gate the two tradeable rungs is not behind them any
    more. Their inputs are uncapped.
    """
    # §9.5.8 -- a battle draught is brewed off a monster, not off a hex. The
    # herbs are still in it, but the ichor is what makes the rung: the top two
    # cannot be brewed at all without a kill in the barren centre.
    if scope == 'battle':
        ichor = BATTLE_ICHOR[rarity]
        if rarity == 'common':
            return {ichor: 2, 'yarrow': 3, 'bitterroot': 2, 'clover': 2}
        if rarity == 'uncommon':
            return {ichor: 2, 'yarrow': 3, 'bitterroot': 2}
        if rarity == 'rare':
            return {ichor: 2, 'yarrow': 3, 'sagebrush': 2}
        if rarity == 'epic':
            return {ichor: 2, 'yarrow': 3}
        return {ichor: 2, 'yarrow': 4}

    b = SCOPE_BIOME[scope]
    r = [k for k, _, bio, _, _ in REAGENTS if bio == b]
    s = [k for k, _, bio, _, _ in REAGENTS if bio == SECOND[b]]
    critter = CRITTER[b]

    if rarity == 'common':
        return {r[0]: 3, r[1]: 2, s[0]: 2, s[1]: 2}
    if rarity == 'uncommon':
        return {r[0]: 3, r[1]: 3, s[0]: 2}
    if rarity == 'rare':
        return {r[0]: 4, r[1]: 3, critter: 2}
    if rarity == 'epic':
        return {r[0]: 6, critter: 3}

    return {r[0]: 8, critter: 5}


# The neighbouring biome a common recipe reaches into, so the cheapest tier is
# the one that makes you travel and the dear ones are local.
# Biome -> the animal that lives on it, §4. Mirrors gen_critters.py.
CRITTER = {
    'forest': 'glimmermoth', 'mountain': 'rockmite', 'plains': 'dustleveret',
    'badlands': 'ashnewt', 'grassland': 'fenlark',
}

SECOND = {
    'forest': 'grassland', 'mountain': 'badlands', 'plains': 'forest',
    'badlands': 'mountain', 'grassland': 'plains',
}


# ------------------------------------------------------------------- emitters

def php_map(d):
    return '[' + ', '.join(f"'{k}' => {v}" for k, v in d.items()) + ']'


def ts_map(d):
    return '{ ' + ', '.join(f'{k}: {v}' for k, v in d.items()) + ' }'


def esc_php(s):
    return s.replace('\\', '\\\\').replace("'", "\\'")


def esc_ts(s):
    return s.replace('\\', '\\\\').replace("'", "\\'")


HEADER = """Generated by scripts/gen_alchemy.py -- do not edit by hand.

Sixty consumables (§8.5) and fifteen materials (§4), emitted into PHP and
TypeScript from one spec so the two catalogs cannot drift."""


def emit_php(items):
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(' */\nfinal class Alchemy\n{\n')

    o.write('    /** §4 Tier 1 -- reagents, biome-locked, the alchemist\'s raw stock. */\n')
    o.write('    public const REAGENTS = [\n')
    for key, name, biome, price, desc in REAGENTS:
        o.write(f"        '{key}' => ['name' => '{esc_php(name)}', 'tier' => 1, 'biome' => '{biome}', "
                f"'palette' => '{PALETTE[biome]}', 'npcPrice' => {price}, 'description' => '{esc_php(desc)}'],\n")
    o.write('    ];\n\n')

    o.write('    /** §4.0 Tier 0 -- junk. Sells for a copper and feeds nothing. */\n')
    o.write('    public const JUNK = [\n')
    for key, name, biome, desc in JUNK:
        o.write(f"        '{key}' => ['name' => '{esc_php(name)}', 'tier' => 0, 'biome' => '{biome}', "
                f"'palette' => '{PALETTE[biome]}', 'npcPrice' => 1, 'description' => '{esc_php(desc)}'],\n")
    o.write('    ];\n\n')

    o.write('    /** §8.5 -- every buff names the action it applies to. */\n')
    o.write('    public const CONSUMABLES = [\n')
    for i in items:
        o.write(
            f"        '{i['key']}' => ['name' => '{esc_php(i['name'])}', 'rarity' => '{i['rarity']}', "
            f"'tradeable' => {'true' if i['tradeable'] else 'false'}, 'stat' => '{i['stat']}', "
            f"'scope' => '{i['scope']}', 'value' => {i['value']}, 'palette' => '{i['palette']}', "
            f"'station' => '{i['station']}', 'consumable' => true, 'inputs' => {php_map(i['inputs'])}, "
            f"'description' => '{esc_php(i['description'])}'],\n"
        )
    o.write('    ];\n}\n')

    return o.getvalue()


def emit_ts(items):
    o = io.StringIO()
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(" */\nimport type { ItemDef, Material } from './types'\n\n")

    o.write('export const REAGENTS: Material[] = [\n')
    for key, name, biome, price, desc in REAGENTS:
        o.write(f"  {{ key: '{key}', name: '{esc_ts(name)}', tier: 1, biome: '{biome}', "
                f"palette: '{PALETTE[biome]}', npcPrice: {price}, description: '{esc_ts(desc)}' }},\n")
    o.write(']\n\n')

    o.write('export const JUNK: Material[] = [\n')
    for key, name, biome, desc in JUNK:
        o.write(f"  {{ key: '{key}', name: '{esc_ts(name)}', tier: 0, biome: '{biome}', "
                f"palette: '{PALETTE[biome]}', npcPrice: 1, description: '{esc_ts(desc)}' }},\n")
    o.write(']\n\n')

    o.write('export const CONSUMABLES: ItemDef[] = [\n')
    for i in items:
        o.write(
            f"  {{ key: '{i['key']}', name: '{esc_ts(i['name'])}', rarity: '{i['rarity']}', "
            f"tradeable: {'true' if i['tradeable'] else 'false'}, stat: '{i['stat']}', "
            f"scope: '{i['scope']}', value: {i['value']}, palette: '{i['palette']}', "
            f"station: '{i['station']}', consumable: true, inputs: {ts_map(i['inputs'])}, "
            f"description: '{esc_ts(i['description'])}' }},\n"
        )
    o.write(']\n')

    return o.getvalue()


if __name__ == '__main__':
    items = consumables()

    keys = [i['key'] for i in items]
    assert len(keys) == len(set(keys)), 'duplicate consumable key'

    prev = 99
    for rarity, _, _, _, mats in RANKS:
        assert mats <= prev, f'{rarity} wants more materials than the rank below'
        prev = mats
    for i in items:
        assert len(i['inputs']) >= 2, f"{i['key']} has fewer than two materials"

    open('app/Game/Alchemy.php', 'w').write(emit_php(items))
    open('resources/js/game/alchemy.ts', 'w').write(emit_ts(items))
    print(f'{len(items)} consumables, {len(REAGENTS)} reagents, {len(JUNK)} junk')
