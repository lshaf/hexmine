"""Emit app/Game/TopTier.php and resources/js/game/toptier.ts from one spec.

§8.0 -- the two rungs above epic, so the whole six-rung ladder is visible.

    python3 scripts/gen_toptier.py

Neither rung is reachable, and that is the point of defining them:

  legendary  a guild hall bench, and guild halls do not exist (§10). Tradeable,
             so §2 requires a wallet cap behind it -- every recipe wants its
             line's Tier 3, which is capped, plus a Core off the floor-10 boss.

  unique     no bench, no shop, no recipe. It drops, and it is soulbound the
             moment it lands: §8.0 stops tradeability at legendary because a
             tradeable drop is exactly the grind-to-external-value faucet §2
             exists to close. Three rolled options plus one fixed perk.

The `weapon` slot is absent from both, and no longer because it is empty: §9.5.4
fills it with three families whose whole ladder -- village to guild hall --
lives in the catalog beside the tools. What is missing here is a UNIQUE weapon,
which waits on dungeon loot (§14.3) the way every other unique does.

The perks are NAMED, not costed. Loot tables and combat resolution are §14.3
and §14.2, both undesigned, so each one states an intent the almanac shows as
pending. None of them is a percentage: the ceiling is +15% and the three rolled
options already reach for it, so a perk that added more would breach §8.1.
"""
import io

# --------------------------------------------------------------------- lines
# slot -> (biome, tier 3, rare grade raw, rare grade refined, component, shard)
LINES = {
    'axe':     ('forest',    'ironwood',        'heartoak',      'bentwood',         'heartknot',   'shard_verdant'),
    'pickaxe': ('mountain',  'mythril_ore',     'meteoric_iron', 'skysteel',         'flux_salt',   'shard_ferrous'),
    'bow':     ('plains',    'beastfang_hide',  'dire_pelt',     'lacquered_hide',   'horn',        'shard_sanguine'),
    'hammer':  ('badlands',  'obsidian_shard',  'granite',       'polished_granite', 'whetgrit',    'shard_cinder'),
    'sickle':  ('grassland', 'silkweave_fiber', 'hemp',          'canvas',           'quench_reed', 'shard_zephyr'),
}

# The three worn slots are not line-locked (§8.0), so each one picks the line
# whose material the piece is actually made of.
WORN = {
    'armor':  ('forest',    'ironwood',       'heartoak',  'bentwood',       'pine_pitch', 'shard_verdant'),
    'boots':  ('plains',    'beastfang_hide', 'dire_pelt', 'lacquered_hide', 'sinew',      'shard_sanguine'),
    'gloves': ('grassland', 'silkweave_fiber', 'hemp',     'canvas',         'beeswax',    'shard_zephyr'),
}

SLOTS = dict(LINES, **WORN)

STAT = {
    'axe': 'yield', 'pickaxe': 'yield', 'bow': 'yield', 'hammer': 'yield',
    'sickle': 'yield', 'armor': 'tripReduction', 'boots': 'travelSpeed',
    'gloves': 'processingSpeed',
}

PALETTE = {
    'forest': 'wood', 'mountain': 'iron', 'plains': 'pelt',
    'badlands': 'stone', 'grassland': 'fiber',
}

# ----------------------------------------------------------------- legendary
# key, name, description
LEGENDARY = {
    'axe':     ('grovefeller',  'Grovefeller',  'Two hands and a full swing. The stand goes quiet around it.'),
    'pickaxe': ('deepreach',    'Deepreach',    'Made for seams that start below where anyone has dug.'),
    'bow':     ('farshot',      'Farshot',      'Loosed from the treeline. Nothing on the range hears the first one.'),
    'hammer':  ('hillbreaker',  'Hillbreaker',  'The face does not chip. It opens, all at once, along a line you chose.'),
    'sickle':  ('longreap',     'Longreap',     'A field in one pass, and the stubble left standing to the inch.'),
    'armor':   ('wardencoat',   'Wardencoat',   'Grown, boiled and banded. Worn by people who walk the contested ring twice a day.'),
    'boots':   ('leaguewalkers', 'Leaguewalkers', 'Cut for the road between rings. The miles stop being the argument.'),
    'gloves':  ('steadyhands',  'Steadyhands',  'Every line foreman in the capital knows the pattern and none can afford it.'),
}

# -------------------------------------------------------------------- unique
# key, name, perk, description
UNIQUE = {
    'axe': ('the_last_bough', 'The Last Bough',
            'Reads the grade of every forest hex inside sight, unworked or not.',
            'Older than the grove it came out of, and the grove was there first.'),
    'pickaxe': ('mothervein', 'Mothervein',
                'Names the seam a mountain hex is hiding before the first swing.',
                'It hums on the walk in, and louder the closer you get.'),
    'bow': ('the_quiet_mile', 'The Quiet Mile',
            'A herd will not leave the hex you are standing on.',
            'Drawn once at the treeline. Nothing on the range has ever heard it.'),
    'hammer': ('the_standing_stone', 'The Standing Stone',
               'Never breaks: it drops to its last point of durability and stops there.',
               'Someone set it upright a long time ago and it has not moved since.'),
    'sickle': ('the_long_acre', 'The Long Acre',
               'A worked grassland hex regrows on its own clock, not the map’s.',
               'The field it was named for is still cut every year by nobody.'),
    'armor': ('coat_of_ash', 'Coat of Ash',
              'Weather on the contested ring costs you nothing at all.',
              'Came out of the Ashpit on someone who did not.'),
    'boots': ('the_wanderers_debt', "The Wanderer's Debt",
              'The road pays Explorer experience whether you arrive or turn back.',
              'Worn through and mended eleven times. None of the mending is yours.'),
    'gloves': ('coldforge', 'Coldforge',
               'A processing queue you are standing in never counts you as absent.',
               'Warm to hold in any weather, which is the wrong way round.'),
}


def legendary_inputs(slot):
    _, rare, grade_raw, grade_refined, component, _ = SLOTS[slot]

    # Six kinds, one rung deeper than epic at every position, and a Core on top:
    # §4 makes the boss drop the thing that gates the best equipment tier.
    return {
        rare: 5,
        grade_raw: 10,
        grade_refined: 6,
        component: 6,
        'reinforced_frame': 3,
        'core': 1,
    }


def rows():
    for slot in SLOTS:
        biome = SLOTS[slot][0]
        lkey, lname, ldesc = LEGENDARY[slot]
        ukey, uname, uperk, udesc = UNIQUE[slot]
        yield {
            'key': lkey, 'name': lname, 'slot': slot, 'rarity': 'legendary',
            'tradeable': True, 'stat': STAT[slot], 'value': 0.14,
            'palette': PALETTE[biome], 'station': 'guild', 'maxDurability': 240,
            'inputs': legendary_inputs(slot), 'perk': None, 'description': ldesc,
        }
        yield {
            'key': ukey, 'name': uname, 'slot': slot, 'rarity': 'unique',
            'tradeable': False, 'stat': STAT[slot], 'value': 0.15,
            'palette': PALETTE[biome], 'station': None, 'maxDurability': 260,
            'inputs': None, 'perk': uperk, 'description': udesc,
        }


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


ts_str = php_str

HEADER = """Generated by scripts/gen_toptier.py -- do not edit by hand.

§8.0 -- the two rungs above epic. Neither is reachable and both are defined, so
the whole six-rung ladder can be read off one page.

Legendary needs a guild hall, and guild halls do not exist (§10). Unique has no
bench and no recipe at all: it drops, and it is soulbound the moment it lands,
because §2 forbids a grind-to-external-value faucet and a tradeable drop would
be exactly that. The `weapon` slot stays empty until raid combat is designed."""


def emit_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(' */\nfinal class TopTier\n{\n')
    o.write('    /** §8.0 -- legendary and unique, eight slots each bar the empty one. */\n')
    o.write('    public const ITEMS = [\n')
    for i in rows():
        parts = [
            f"'name' => {php_str(i['name'])}",
            f"'slot' => '{i['slot']}'",
            f"'rarity' => '{i['rarity']}'",
            f"'tradeable' => {'true' if i['tradeable'] else 'false'}",
            f"'stat' => '{i['stat']}'",
            f"'value' => {i['value']}",
            f"'palette' => '{i['palette']}'",
        ]
        if i['station']:
            parts.append(f"'station' => '{i['station']}'")
        parts.append(f"'maxDurability' => {i['maxDurability']}")
        if i['inputs']:
            inner = ', '.join(f"'{k}' => {v}" for k, v in i['inputs'].items())
            parts.append(f"'inputs' => [{inner}]")
        if i['perk']:
            parts.append(f"'perk' => {php_str(i['perk'])}")
        parts.append(f"'description' => {php_str(i['description'])}")
        o.write(f"        '{i['key']}' => [" + ', '.join(parts) + '],\n')
    o.write('    ];\n}\n')

    return o.getvalue()


def emit_ts():
    o = io.StringIO()
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(" */\nimport type { ItemDef } from './types'\n\n")
    o.write('export const TOP_TIER: ItemDef[] = [\n')
    for i in rows():
        parts = [
            f"key: '{i['key']}'",
            f"name: {ts_str(i['name'])}",
            f"slot: '{i['slot']}'",
            f"rarity: '{i['rarity']}'",
            f"tradeable: {'true' if i['tradeable'] else 'false'}",
            f"stat: '{i['stat']}'",
            f"value: {i['value']}",
            f"palette: '{i['palette']}'",
        ]
        if i['station']:
            parts.append(f"station: '{i['station']}'")
        parts.append(f"maxDurability: {i['maxDurability']}")
        if i['inputs']:
            inner = ', '.join(f'{k}: {v}' for k, v in i['inputs'].items())
            parts.append(f'inputs: {{ {inner} }}')
        if i['perk']:
            parts.append(f"perk: {ts_str(i['perk'])}")
        parts.append(f"description: {ts_str(i['description'])}")
        o.write('  { ' + ', '.join(parts) + ' },\n')
    o.write(']\n')

    return o.getvalue()


if __name__ == '__main__':
    items = list(rows())

    keys = [i['key'] for i in items]
    assert len(keys) == len(set(keys)), 'duplicate item key'
    assert 'weapon' not in SLOTS, 'the weapon slot stays empty until combat exists'

    for i in items:
        if i['rarity'] == 'legendary':
            assert i['station'] == 'guild', f"{i['key']} is legendary off a reachable bench"
            assert i['tradeable'] and i['inputs'], f"{i['key']} is legendary and uncrafted"
            assert 'core' in i['inputs'], f"{i['key']} does not want a Core"
        else:
            assert i['station'] is None, f"{i['key']} is unique and has a bench"
            assert not i['tradeable'], f"{i['key']} is a tradeable unique"
            assert i['inputs'] is None, f"{i['key']} is unique and craftable"
            assert i['perk'], f"{i['key']} is unique with no fixed perk"

    open('app/Game/TopTier.php', 'w').write(emit_php())
    open('resources/js/game/toptier.ts', 'w').write(emit_ts())
    print(f'{len(items)} items across {len(SLOTS)} slots')
