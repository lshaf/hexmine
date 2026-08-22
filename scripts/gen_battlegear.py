"""Emit app/Game/BattleGear.php and resources/js/game/battlegear.ts from one spec.

    python3 scripts/gen_battlegear.py

§9.5.4 -- everything a character fights in. Six groups: the three weapon
families, which share one slot and decide your battle job, and the three
battle-leaning worn pieces that stand beside the work-leaning ones.

Three of each, at every rung, and the three are a MATERIALS ladder rather than a
rarity one:

    low     cheap stock, and the rung's cheapest way in
    medium  more of it, plus the group's component
    high    more again, plus what is rare for THAT rung

The rung still sets the percentage stat and the bench that reaches it (§8.0).
What the three grades move is the flat attack/defence pair, +-15% around the
rung's middle -- which is why a high common overlaps a low uncommon. The ladder
is meant to be continuous: there is always something better to build, and it is
always a question of what you are willing to carry to the bench.

Ninety items is why this is generated. Hand-writing them on both sides is the
drift the catalog has no parity test for.
"""
import io

RUNGS = ['common', 'uncommon', 'rare', 'epic', 'legendary']
VALUE = [0.03, 0.05, 0.08, 0.11, 0.14]
STATION = ['village', 'city', 'capital', 'capital', 'guild']

GRADES = ['low', 'medium', 'high']

# Flat pair around the rung's middle, and durability with it. A high piece is
# worth about a rung and a half of a low one, which is the whole argument for
# carrying more material to the bench.
PAIR_SCALE = {'low': 0.85, 'medium': 1.0, 'high': 1.15}
DUR_SCALE = {'low': 0.85, 'medium': 1.0, 'high': 1.2}

# §9.5.8 -- the spoil grade a rung is built from. Every combat piece wants one,
# which is what keeps combat gear behind combat rather than behind mining.
SPOIL = ['cracked_carapace', 'bone_plate', 'scaled_hide', 'warped_barb', 'revenant_plate']

# What "rare for this rung" means, and it is not the same thing twice: at the
# bottom it is the spoil one grade up, at the top it is a capped Tier 3 (§2).
RARE_EXTRA = [
    {'bone_plate': 1},
    {'scaled_hide': 1},
    {'warped_barb': 2, 'reinforced_frame': 1},
    None,  # filled per group with its own Tier 3
    None,
]

GROUPS = {
    'sword': {
        'slot': 'weapon', 'family': 'sword', 'stat': 'power', 'palette': 'iron',
        'pairs': [(8, 4), (11, 6), (15, 8), (19, 10), (23, 12)],
        'gold': [22, 110], 'dur': [60, 90, 150, 200, 240],
        'rare_mat': 'mythril_ore', 'component': 'flux_salt',
        'refined': ['ingots', 'ingots', 'steel_ingots', 'steel_ingots', 'skysteel'],
        'names': [
            ['Notched Sword', "Soldier's Sword", 'Tempered Sword'],
            ['Iron Broadsword', 'Banded Broadsword', 'Fluted Broadsword'],
            ['Steel Longsword', 'Barbed Longsword', 'Skysteel Longsword'],
            ['Mythril Edge', 'Fanged Edge', 'Skyforged Edge'],
            ['Oathkeeper', 'Longwatch Blade', 'The Last Argument'],
        ],
        'blurb': [
            'Straight, short, and honest about what it is.',
            'Wide in the blade and heavy through the swing.',
            'Long enough to keep a brute at the end of it.',
            'Light in the hand and unfair everywhere else.',
            'Named at the guild hall, and the name is the cheap part.',
        ],
        'tails': ['Village iron, and it complains.', 'Folded twice more than it needed.',
                  'The smith kept the best of the bar for this one.'],
    },
    'shield': {
        'slot': 'weapon', 'family': 'shield', 'stat': 'defence', 'palette': 'stone',
        'pairs': [(5, 8), (7, 11), (9, 15), (12, 19), (14, 23)],
        'gold': [22, 110], 'dur': [60, 90, 150, 200, 240],
        'rare_mat': 'obsidian_shard', 'component': 'whetgrit',
        'refined': ['cut_stone', 'cut_stone', 'dressed_basalt', 'dressed_basalt', 'polished_granite'],
        'names': [
            ['Plank Shield', 'Studded Shield', 'Banded Shield'],
            ['Iron Buckler', 'Ridged Buckler', 'Warded Buckler'],
            ['Tower Shield', 'Barbed Tower Shield', 'Granite Tower Shield'],
            ['Obsidian Aegis', 'Fanged Aegis', 'Blackglass Aegis'],
            ['Bulwark of the Long Watch', 'Bulwark of the Silent Gate', 'Bulwark of the Last Stand'],
        ],
        'blurb': [
            'Three boards and a strap. It has stopped worse than it looks.',
            'Small, quick, and banded where the splitting starts.',
            'You do not carry it so much as stand behind it.',
            'Glass that refuses. Nothing gets through the front.',
            'Held a gate for a night nobody talks about.',
        ],
        'tails': ['Nailed together in an afternoon.', 'Rimmed all the way round this time.',
                  'Faced with the hardest thing the rung allows.'],
    },
    'focus': {
        'slot': 'weapon', 'family': 'focus', 'stat': 'power', 'palette': 'fiber',
        'pairs': [(11, 2), (15, 3), (20, 4), (25, 5), (30, 6)],
        'gold': [22, 110], 'dur': [60, 90, 150, 200, 240],
        'rare_mat': 'silkweave_fiber', 'component': 'quench_reed',
        'refined': ['cloth', 'cloth', 'linen', 'linen', 'canvas'],
        'names': [
            ['Cracked Focus', 'Bound Focus', 'Sealed Focus'],
            ['Knotted Rod', 'Corded Rod', 'Wound Rod'],
            ['Rune Rod', 'Barbed Rune Rod', 'Silkbound Rune Rod'],
            ['Silkweave Sigil', 'Fanged Sigil', 'Sunken Sigil'],
            ['The Long Word', 'The Whole Word', 'The Spoken Word'],
        ],
        'blurb': [
            'It works. The crack is where the last one stopped working.',
            'Wound tight so the whole of it goes out the front.',
            'Cut and cut again until only the working part is left.',
            'Everything it throws arrives before the sound does.',
            'Said once, slowly, and nothing in front of it stands.',
        ],
        'tails': ['Bound with what was to hand.', 'Wrapped twice and sealed at both ends.',
                  'Every winding is the best the rung will take.'],
    },
    'armor': {
        'slot': 'armor', 'stat': 'defence', 'palette': 'pelt',
        'pairs': [(0, 4), (0, 7), (1, 12), (1, 16), (2, 21)],
        'gold': [26, 130], 'dur': [70, 110, 170, 210, 250],
        'rare_mat': 'obsidian_shard', 'component': 'slate_scale',
        'refined': ['leather', 'leather', 'boiled_leather', 'boiled_leather', 'lacquered_hide'],
        'names': [
            ['Padded Jack', 'Studded Jack', 'Riveted Jack'],
            ['Ring Hauberk', 'Scaled Hauberk', 'Plated Hauberk'],
            ['Scaled Cuirass', 'Barbed Cuirass', 'Lacquered Cuirass'],
            ['Barbed Plate', 'Obsidian Plate', 'Blackglass Plate'],
            ["Warden's Carapace", "Keeper's Carapace", 'Longwatch Carapace'],
        ],
        'blurb': [
            'Layered and quilted flat. It slows a blade rather than stopping one.',
            'Riveted rings over a gambeson. Heavy on the shoulders and worth it.',
            'Overlapped down the chest, off something that overlapped it first.',
            'Set with what killed the thing it came off.',
            'Fitted at the guild hall over three weeks.',
        ],
        'tails': ['Stitched in one sitting.', 'Doubled at every seam that matters.',
                  'Faced with the rarest plate the rung allows.'],
    },
    'boots': {
        'slot': 'boots', 'stat': 'defence', 'palette': 'stone',
        'pairs': [(0, 2), (0, 3), (0, 5), (0, 7), (0, 10)],
        'gold': [26, 130], 'dur': [70, 110, 170, 210, 250],
        'rare_mat': 'obsidian_shard', 'component': 'tar_seep',
        'refined': ['cut_stone', 'cut_stone', 'dressed_basalt', 'dressed_basalt', 'polished_granite'],
        'names': [
            ['Studded Boots', 'Nailed Boots', 'Plated Boots'],
            ['Shinguard Boots', 'Greaved Boots', 'Ridged Greaves'],
            ['Plated Sabatons', 'Barbed Sabatons', 'Granite Sabatons'],
            ['Revenant Sabatons', 'Obsidian Sabatons', 'Emberstep Sabatons'],
            ['Ironward Sabatons', 'Stonefast Sabatons', 'Unmoved Sabatons'],
        ],
        'blurb': [
            'Hobnailed through the sole. You keep your feet where others do not.',
            'Plated to the knee. Everything low comes off them.',
            'Articulated, and quieter than they look.',
            'Still warm at the ankle. You stop noticing by the second fight.',
            'Planted once and not moved since.',
        ],
        'tails': ['Cobbled cheap and fast.', 'Plated over the instep as well.',
                  'Shod in the hardest stone the rung allows.'],
    },
    'gloves': {
        'slot': 'gloves', 'stat': 'power', 'palette': 'iron',
        'pairs': [(2, 0), (4, 0), (7, 1), (9, 1), (12, 2)],
        'gold': [26, 130], 'dur': [70, 110, 170, 210, 250],
        'rare_mat': 'mythril_ore', 'component': 'horn',
        'refined': ['ingots', 'ingots', 'steel_ingots', 'steel_ingots', 'skysteel'],
        'names': [
            ['Knuckle Wraps', 'Studded Wraps', 'Banded Wraps'],
            ['Banded Gauntlets', 'Ridged Gauntlets', 'Steel Gauntlets'],
            ['Clawed Gauntlets', 'Barbed Gauntlets', 'Fanged Gauntlets'],
            ['Warped Gauntlets', 'Mythril Gauntlets', 'Skyforged Gauntlets'],
            ['Gauntlets of the Long Watch', 'Gauntlets of the Closed Fist',
             'Gauntlets of the Last Word'],
        ],
        'blurb': [
            'Cord over the knuckles and nothing else. Enough to start with.',
            'Steel over the back of the hand, open at the palm to keep the grip.',
            'Fingered in something that was already sharp.',
            'Grew wrong on the thing that wore it first, and hits the harder for it.',
            'Whatever you were arguing about is settled.',
        ],
        'tails': ['Wrapped, not fitted.', 'Plated across the fingers as well.',
                  'Clawed in the rarest thing the rung allows.'],
    },
}


def key_for(name):
    out = name.lower().replace("'", '').replace('-', ' ')
    return '_'.join(out.split())


def scale(n, factor):
    """Zero stays zero: a slot that gives no attack does not start giving one."""
    return 0 if n == 0 else max(1, round(n * factor))


def inputs_for(group, rung_i, grade):
    refined = group['refined'][rung_i]
    spoil = SPOIL[rung_i]

    # §2 -- a MINTABLE rung must have a per-wallet cap behind it, so epic and
    # legendary want their group's Tier 3 at every grade, not only at `high`.
    # Without that the cheap grade would be a mintable item with nothing capped
    # underneath it, which is the grind-to-external-value path the threat model
    # exists to close. The count is what the grade moves: 1, 2, 3.
    gate = {group['rare_mat']: rung_i - 2 + GRADES.index(grade)} if rung_i >= 3 else {}

    if grade == 'low':
        return {refined: 3, spoil: 2, **gate}
    if grade == 'medium':
        return {refined: 4, spoil: 3, group['component']: 2, **gate}

    extra = RARE_EXTRA[rung_i]
    if extra is None:
        extra = {'grave_heart': 2} if rung_i == 4 else {}

    return {refined: 4, spoil: 4, group['component']: 3, **gate, **extra}


def items():
    out = []
    for name, group in GROUPS.items():
        for i, rung in enumerate(RUNGS):
            for g, grade in enumerate(GRADES):
                atk, dfn = group['pairs'][i]
                label = group['names'][i][g]
                # Low is the rung's shop line as well, at the two rungs gold
                # reaches (§3.2). Everything else is bench work.
                gold = group['gold'][i] if (grade == 'low' and i < 2) else None

                out.append({
                    'key': key_for(label),
                    'name': label,
                    'slot': group['slot'],
                    'family': group.get('family'),
                    'rarity': rung,
                    'tradeable': i >= 3,
                    'stat': group['stat'],
                    'value': VALUE[i],
                    'attack': scale(atk, PAIR_SCALE[grade]),
                    'defence': scale(dfn, PAIR_SCALE[grade]),
                    'palette': group['palette'],
                    'goldPrice': gold,
                    'maxDurability': round(group['dur'][i] * DUR_SCALE[grade]),
                    'station': STATION[i],
                    'inputs': inputs_for(group, i, grade),
                    'description': f"{group['blurb'][i]} {group['tails'][g]}",
                })
    return out


HEADER = """Generated by scripts/gen_battlegear.py -- do not edit by hand.

§9.5.4 -- everything a character fights in. Three weapon families sharing one
slot, three battle-leaning worn pieces beside the work-leaning ones, and three
of each at every rung.

The three are a MATERIALS ladder inside the rung, not a rarity one: `low` is the
cheapest way in, `medium` wants more of the same plus the group's component,
`high` wants more again plus whatever is rare for THAT rung -- a spoil one grade
up at the bottom, a capped Tier 3 at the top (§2). What moves is the flat
attack/defence pair, +-15% around the middle; the rung still owns the percentage
stat and the bench that reaches it.

`attack` and `defence` are FLAT and are not the percentage stats of the same
name. §8.1's ceiling is +15%, and a fight cannot be decided by a swing that
small."""


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


ts_str = php_str


def doc(o):
    o.write('/**\n')
    for line in HEADER.split('\n'):
        o.write(f' * {line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(' */\n')


def emit_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    doc(o)
    o.write('final class BattleGear\n{\n')
    o.write('    /** §9.5.4 -- ninety pieces: six groups, five rungs, three grades. */\n')
    o.write('    public const ITEMS = [\n')
    for it in items():
        bits = [f"'name' => {php_str(it['name'])}", f"'slot' => '{it['slot']}'"]
        if it['family']:
            bits.append(f"'family' => '{it['family']}'")
        bits += [
            f"'rarity' => '{it['rarity']}'",
            f"'tradeable' => {'true' if it['tradeable'] else 'false'}",
            f"'stat' => '{it['stat']}'", f"'value' => {it['value']}",
            f"'attack' => {it['attack']}", f"'defence' => {it['defence']}",
            f"'palette' => '{it['palette']}'",
        ]
        if it['goldPrice']:
            bits.append(f"'goldPrice' => {it['goldPrice']}")
        bits += [f"'maxDurability' => {it['maxDurability']}", f"'station' => '{it['station']}'"]
        pairs = ', '.join(f"'{k}' => {v}" for k, v in it['inputs'].items())
        bits.append(f"'inputs' => [{pairs}]")
        bits.append(f"'description' => {php_str(it['description'])}")
        o.write(f"        '{it['key']}' => [{', '.join(bits)}],\n")
    o.write('    ];\n}\n')
    return o.getvalue()


def emit_ts():
    o = io.StringIO()
    doc(o)
    o.write("import type { ItemDef } from './types'\n\n")
    o.write('export const BATTLE_GEAR: ItemDef[] = [\n')
    for it in items():
        bits = [f"key: '{it['key']}'", f"name: {ts_str(it['name'])}", f"slot: '{it['slot']}'"]
        if it['family']:
            bits.append(f"family: '{it['family']}'")
        bits += [
            f"rarity: '{it['rarity']}'",
            f"tradeable: {'true' if it['tradeable'] else 'false'}",
            f"stat: '{it['stat']}'", f"value: {it['value']}",
            f"attack: {it['attack']}", f"defence: {it['defence']}",
            f"palette: '{it['palette']}'",
        ]
        if it['goldPrice']:
            bits.append(f"goldPrice: {it['goldPrice']}")
        bits += [f"maxDurability: {it['maxDurability']}", f"station: '{it['station']}'"]
        pairs = ', '.join(f'{k}: {v}' for k, v in it['inputs'].items())
        bits.append(f'inputs: {{ {pairs} }}')
        bits.append(f"description: {ts_str(it['description'])}")
        o.write(f"  {{ {', '.join(bits)} }},\n")
    o.write(']\n')
    return o.getvalue()


if __name__ == '__main__':
    rows = items()
    keys = [r['key'] for r in rows]
    assert len(keys) == len(set(keys)), 'duplicate battle gear key'
    assert len(rows) == 90, f'{len(rows)} pieces, expected 90'

    # Every piece wants a spoil: combat gear is built out of combat (§9.5.8).
    for r in rows:
        assert any(k in SPOIL for k in r['inputs']), f"{r['key']} wants no spoil"
        assert len(r['inputs']) >= 2, f"{r['key']} is a one-ingredient shortcut"

    # §2 -- nothing mintable may be craftable without a per-wallet cap behind
    # it. Every epic and legendary piece, at every grade, wants its group's
    # Tier 3.
    tier3 = {g['rare_mat'] for g in GROUPS.values()}
    for r in rows:
        if r['tradeable']:
            assert any(k in tier3 for k in r['inputs']), f"{r['key']} is mintable and uncapped"

    # Within a rung the pair climbs with the grade, which is the whole ladder.
    for group in GROUPS.values():
        for i in range(len(RUNGS)):
            pairs = [scale(group['pairs'][i][0], PAIR_SCALE[g])
                     + scale(group['pairs'][i][1], PAIR_SCALE[g]) for g in GRADES]
            assert pairs == sorted(pairs), 'a high grade is not better than a low one'

    open('app/Game/BattleGear.php', 'w').write(emit_php())
    open('resources/js/game/battlegear.ts', 'w').write(emit_ts())
    print(f'{len(rows)} battle gear pieces')
