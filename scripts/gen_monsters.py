"""Emit the §9.5 roster and its drops from one spec.

    python3 scripts/gen_monsters.py

Writes app/Game/Monsters.php, resources/js/game/monsters.ts, app/Game/Spoils.php
and resources/js/game/spoils.ts.

Eight monsters in four overlapping pools, two new per ring and two carried in
from the ring outside it. The overlap is the design: walking inward you meet two
you know how to fight and two you do not.

Ten spoils in two families, five grades each. A monster of tier N drops its
tier's grade, and rarely the one above -- which is what makes the guild rung's
input come off the center ring and nowhere else.
"""
import io

# ---------------------------------------------------------------- the roster

# Player totals the numbers are aimed at, kit and battle job matched to the ring:
#
#   common kit,     job 2    atk ~10  def ~8
#   rare kit,       job 14   atk ~23  def ~21
#   epic kit,       job 20   atk ~30  def ~28
#   legendary kit,  job 28   atk ~37  def ~35
#
# A monster's attack decides how fast it empties your kit and its defense decides
# how slowly you empty it (§9.5.5), so the pair is set from their sum and the
# profile only decides which half carries it. HP is derived below.

# key, name, tier, profile, attack, defense, wearBias, gold, description
MONSTERS = [
    ('moss_hound', 'Moss Hound', 1, 'brute', 12, 5, 1.0, (4, 9),
     'Runs the treeline in threes and takes the slowest thing on the road.'),
    ('ditch_crawler', 'Ditch Crawler', 1, 'carapace', 6, 11, 1.0, (5, 11),
     'Sits in the wheel rut with its back plated over and waits to be stepped on.'),

    ('rill_skitter', 'Rill Skitter', 1, 'swift', 9, 8, 1.3, (5, 10),
     'Crosses the road in one blur and is back before the dust drops.'),

    ('slag_ogre', 'Slag Ogre', 2, 'brute', 27, 12, 1.0, (14, 26),
     'Came down off a spoil heap and has been swinging the same girder since.'),
    ('thornback', 'Thornback', 2, 'carapace', 13, 26, 1.0, (16, 30),
     'Every quill points out. Hitting it costs you more than missing does.'),

    ('cinder_lash', 'Cinder Lash', 2, 'swift', 21, 19, 1.4, (15, 28),
     'Whips out of a vent, opens a seam in whatever it touches, and is gone.'),

    ('ridge_wyrm', 'Ridge Wyrm', 3, 'swift', 30, 23, 1.5, (30, 52),
     'Fast over broken ground and faster over you. It blunts whatever it is hit with.'),
    ('iron_shrike', 'Iron Shrike', 3, 'brute', 36, 17, 1.0, (33, 58),
     'Drops out of the sun onto the one thing in the party carrying metal.'),

    ('kiln_tortoise', 'Kiln Tortoise', 3, 'carapace', 18, 38, 1.0, (34, 60),
     'Bakes itself hard in the vents and takes all afternoon to get through.'),

    # The center is the one ring that asks for battle gear rather than work gear
    # (§9.5.4). These two are sized against a full battle set, which is what
    # makes the last step inward a kit decision rather than a level one.
    ('barrow_knight', 'Barrow Knight', 4, 'carapace', 32, 58, 1.0, (60, 105),
     'Whatever it was buried in, it is still wearing. Nothing gets through the front.'),
    ('ash_revenant', 'Ash Revenant', 4, 'brute', 60, 30, 1.0, (66, 115),
     'Walked out of the center with the fire still on it and has not stopped since.'),
    ('pale_stalker', 'Pale Stalker', 4, 'swift', 52, 40, 1.5, (68, 118),
     'Keeps pace a ring behind you for a day, and closes on the hex you stop at.'),
]

# §9.5.5 -- what a monster has to be worked through, in durability.
#
# The fight is an exchange rather than a roll: your kit's total durability is
# your HP, and a monster's is this. Sized so a rung matched to the ring wins
# with a real bill and an outclassed one is emptied.
#
# Derived from the tier and bent by the profile, because a brute standing up
# longer than a carapace is what "profile" is supposed to mean on this side of
# the table too.
HP_BY_TIER = {1: 45, 2: 105, 3: 160, 4: 240}
HP_BY_PROFILE = {'brute': 1.15, 'carapace': 0.9, 'swift': 1.0}


def hp(tier, profile):
    return int(round(HP_BY_TIER[tier] * HP_BY_PROFILE[profile]))


# Which ring each tier is NEW on. A pool is its own tier plus the one outside it.
RING_OF_TIER = {1: 'outer', 2: 'mid', 3: 'inner', 4: 'center'}

# 'center' rather than 'center': it is the Ring key the generator and the
# server already share (Balance::RING_CENTER), and prose is not a key.
RINGS = ['outer', 'mid', 'inner', 'center']

# --------------------------------------------------------------- the spoils

# Two families, five grades. Tier 1..4 monsters drop grade 1..4; the fifth grade
# is the center ring's rare drop, which is why guild-rung gear is reachable from
# nowhere else.

# key, name, grade, npcPrice, description
PLATE = [
    ('cracked_carapace', 'Cracked Carapace', 1, 4,
     'Comes off in sheets and takes a rivet without splitting. Most of it is waste.'),
    ('bone_plate', 'Bone Plate', 2, 7,
     'Flat, dense, and already the right shape. The armorer barely has to cut.'),
    ('scaled_hide', 'Scaled Hide', 3, 11,
     'Overlapped the whole way down. Turns a point that a flat hide would let through.'),
    ('warped_barb', 'Warped Barb', 4, 17,
     'Grew wrong and hardened that way. Holds an edge nothing whetted can match.'),
    ('revenant_plate', 'Revenant Plate', 5, 26,
     'Still warm. The smith works it fast, before it remembers what it was.'),
]

ICHOR = [
    ('thin_ichor', 'Thin Ichor', 1, 3,
     'Runs like water and keeps for a week in a stoppered flask. Barely worth the flask.'),
    ('black_blood', 'Black Blood', 2, 6,
     'Thick enough to draw a line with. The alchemist wants it for what it will not mix with.'),
    ('bile_sac', 'Bile Sac', 3, 10,
     'Cut it out whole or lose the lot. Everything downstream of this is a nerve tonic.'),
    ('ember_gland', 'Ember Gland', 4, 16,
     'Hot in the hand an hour after the thing stopped moving.'),
    ('grave_heart', 'Grave Heart', 5, 25,
     'It beat once on the bench. Nobody who saw it wants to talk about it.'),
]

# --------------------------------------------------------------- the trophies

# §4 -- tier 0. What a fight leaves that nobody wants: worth a gold, wanted by no
# recipe, and dropped every time. It is the JUNK argument (§4) applied to combat
# -- the rubbish carried out alongside -- rather than a fourth spoil ladder, so
# it can be generous without touching the economy §9.5.8 keeps combat inside.
#
# One per tier rather than one per monster. Twelve trophies would be twelve
# straps (§7.6), and the bag is the limit the whole game runs on: a fight that
# costs you a row is a fight that cost you more than it paid.

# key, name, tier, description
TROPHY = [
    ('chipped_fang', 'Chipped Fang', 1,
     'Snapped off in something harder than it was. Everybody on the rim has a jar of them.'),
    ('cracked_horn', 'Cracked Horn', 2,
     'Split down the middle and no use to anybody. It still smells of the thing.'),
    ('snapped_quill', 'Snapped Quill', 3,
     'Hollow, light, and sharp at the wrong end. Nothing will take it as a point.'),
    ('charred_sinew', 'Charred Sinew', 4,
     'Went through a fire before you got to it. It will not hold a knot.'),
]

HEADER_MONSTERS = """Generated by scripts/gen_monsters.py -- do not edit by hand.

§9.5 -- twelve monsters in four overlapping pools. Each ring adds three of its
own and carries three in from the ring outside it, so every ring is legible and
dangerous at the same time.

Three per ring rather than two, and the third is what makes each ring run all
three PROFILES. It used to be two, which left the outer rim with a brute and a
carapace and no swift anywhere until the inner ring -- so the one profile that
wears a weapon harder was something a player met for the first time at tier 3,
holding gear it was about to bill them for.

`attack` and `defense` are FLAT, and they are not the percentage stats of the
same name: §8.1's ceiling is +15%, and a fight cannot be decided by a swing that
small. The profile is what a player reads -- a brute is high attack and low
defense, a carapace the reverse, a swift one is middling in both and wears a
weapon harder for it."""

HEADER_SPOILS = """Generated by scripts/gen_monsters.py -- do not edit by hand.

§9.5.8 -- what comes off a monster. Two Tier 1 families of five: a plate/hide
line the smith and the armorer want, an ichor/organ line the consumable bench
wants. Biome-free, because they come off a thing that walked there rather than
out of the ground, and dropped by nothing else.

And a TIER 0 line of four, one per monster tier, dropped every time. §4's junk
argument applied to combat: the rubbish carried out alongside, worth a gold and
wanted by no recipe. It is generous precisely because it is worthless -- a drop
nobody can build with cannot inflate anything.

Combat feeds combat. Nothing here enters the mining economy, which is what makes
a whole new faucet safe under §2."""


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


ts_str = php_str


def doc(o, header, prefix=' * '):
    o.write('/**\n')
    for line in header.split('\n'):
        o.write(f'{prefix}{line}\n'.rstrip() + '\n' if line else ' *\n')
    o.write(' */\n')


def pool(ring):
    """A ring fights its own tier and the one outside it."""
    tier = RINGS.index(ring) + 1
    return [m[0] for m in MONSTERS if m[2] in (tier, tier - 1)]


def drops(tier):
    """Grade of its tier, and rarely the one above. Tier 4 is where grade 5 lives."""
    return {
        'plate': PLATE[tier - 1][0],
        'ichor': ICHOR[tier - 1][0],
        'rare': PLATE[tier][0] if tier < 5 and tier < len(PLATE) else None,
        # §4 -- the tier-0 leavings, one per tier and always dropped.
        'trophy': TROPHY[tier - 1][0],
    }


# ------------------------------------------------------------------- emitters

def emit_monsters_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    doc(o, HEADER_MONSTERS)
    o.write('final class Monsters\n{\n')
    o.write('    /** §9.5.2 -- the roster. Flat attack, defense and HP, never percentages. */\n')
    o.write('    public const ROSTER = [\n')
    for key, name, tier, profile, atk, dfn, wear, gold, desc in MONSTERS:
        d = drops(tier)
        rare = f"'{d['rare']}'" if d['rare'] else 'null'
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'tier' => {tier}, "
            f"'profile' => '{profile}', 'attack' => {atk}, 'defense' => {dfn}, "
            f"'hp' => {hp(tier, profile)}, "
            f"'wearBias' => {wear}, 'gold' => [{gold[0]}, {gold[1]}], "
            f"'plate' => '{d['plate']}', 'ichor' => '{d['ichor']}', 'rareSpoil' => {rare}, "
            f"'description' => {php_str(desc)}],\n"
        )
    o.write('    ];\n\n')
    o.write('    /**\n')
    o.write('     * §9.5.2 -- what stands on each ring. Two new, two carried in from\n')
    o.write('     * outside, and the outer ring has nothing outside it to carry.\n')
    o.write('     */\n')
    o.write('    public const BY_RING = [\n')
    for ring in RINGS:
        keys = ', '.join(f"'{k}'" for k in pool(ring))
        o.write(f"        '{ring}' => [{keys}],\n")
    o.write('    ];\n}\n')
    return o.getvalue()


def emit_monsters_ts():
    o = io.StringIO()
    doc(o, HEADER_MONSTERS)
    o.write("import type { Monster, Ring } from './types'\n\n")
    o.write('export const MONSTERS: Record<string, Monster> = {\n')
    for key, name, tier, profile, atk, dfn, wear, gold, desc in MONSTERS:
        d = drops(tier)
        rare = f"'{d['rare']}'" if d['rare'] else 'undefined'
        o.write(
            f"  {key}: {{ key: '{key}', name: {ts_str(name)}, tier: {tier}, "
            f"profile: '{profile}', attack: {atk}, defense: {dfn}, "
            f"hp: {hp(tier, profile)}, "
            f"wearBias: {wear}, gold: [{gold[0]}, {gold[1]}], "
            f"plate: '{d['plate']}', ichor: '{d['ichor']}', rareSpoil: {rare}, "
            f"description: {ts_str(desc)} }},\n"
        )
    o.write('}\n\n')
    o.write('export const MONSTERS_BY_RING: Record<Ring, string[]> = {\n')
    for ring in RINGS:
        keys = ', '.join(f"'{k}'" for k in pool(ring))
        o.write(f"  {ring}: [{keys}],\n")
    o.write('}\n')
    return o.getvalue()


def emit_spoils_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    doc(o, HEADER_SPOILS)
    o.write('final class Spoils\n{\n')
    o.write('    /** §9.5.8 Tier 1 -- monster parts. Biome-free, dropped by nothing else. */\n')
    o.write('    public const STOCK = [\n')
    for family, rows, palette in (('plate', PLATE, 'pelt'), ('ichor', ICHOR, 'stone')):
        for key, name, grade, price, desc in rows:
            o.write(
                f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 1, "
                f"'palette' => '{palette}', 'spoil' => '{family}', 'grade' => {grade}, "
                f"'npcPrice' => {price}, 'description' => {php_str(desc)}],\n"
            )
    # §4 -- tier 0, a gold apiece, feeding nothing. No `spoil` family: it is
    # not a ladder, and filing it as one would put it in the bench's lists.
    for key, name, tier, desc in TROPHY:
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 0, "
            f"'palette' => 'stone', 'grade' => {tier}, "
            f"'npcPrice' => 1, 'description' => {php_str(desc)}],\n"
        )
    o.write('    ];\n\n')
    o.write('    /** Grade -> the two things a monster of that tier gives up. */\n')
    o.write('    public const BY_GRADE = [\n')
    for i in range(len(PLATE)):
        o.write(f"        {i + 1} => ['plate' => '{PLATE[i][0]}', 'ichor' => '{ICHOR[i][0]}'],\n")
    o.write('    ];\n\n')
    o.write('    /** §4 -- monster tier -> the tier-0 leaving it drops every time. */\n')
    o.write('    public const TROPHY_BY_TIER = [\n')
    for key, name, tier, desc in TROPHY:
        o.write(f"        {tier} => '{key}',\n")
    o.write('    ];\n}\n')
    return o.getvalue()


def emit_spoils_ts():
    o = io.StringIO()
    doc(o, HEADER_SPOILS)
    o.write("import type { Material } from './types'\n\n")
    o.write('export const SPOILS: Material[] = [\n')
    for family, rows, palette in (('plate', PLATE, 'pelt'), ('ichor', ICHOR, 'stone')):
        for key, name, grade, price, desc in rows:
            o.write(
                f"  {{ key: '{key}', name: {ts_str(name)}, tier: 1, "
                f"palette: '{palette}', spoil: '{family}', grade: {grade}, "
                f"npcPrice: {price}, description: {ts_str(desc)} }},\n"
            )
    for key, name, tier, desc in TROPHY:
        o.write(
            f"  {{ key: '{key}', name: {ts_str(name)}, tier: 0, "
            f"palette: 'stone', grade: {tier}, "
            f"npcPrice: 1, description: {ts_str(desc)} }},\n"
        )
    o.write(']\n\n')
    o.write('export const SPOILS_BY_GRADE: Record<number, { plate: string; ichor: string }> = {\n')
    for i in range(len(PLATE)):
        o.write(f"  {i + 1}: {{ plate: '{PLATE[i][0]}', ichor: '{ICHOR[i][0]}' }},\n")
    o.write('}\n\n')
    o.write('/** §4 -- monster tier -> the tier-0 leaving it drops every time. */\n')
    o.write('export const TROPHY_BY_TIER: Record<number, string> = {\n')
    for key, name, tier, desc in TROPHY:
        o.write(f"  {tier}: '{key}',\n")
    o.write('}\n')
    return o.getvalue()


if __name__ == '__main__':
    keys = [m[0] for m in MONSTERS]
    assert len(keys) == len(set(keys)), 'duplicate monster key'
    assert len(MONSTERS) == 12, 'the roster is twelve'
    for tier in (1, 2, 3, 4):
        band = [m for m in MONSTERS if m[2] == tier]
        assert len(band) == 3, f'tier {tier} is not three monsters'
        # Every ring runs all three profiles, which is the whole reason the
        # third one exists: a player meets each read at their own difficulty.
        assert {m[3] for m in band} == {'brute', 'carapace', 'swift'}, \
            f'tier {tier} does not run all three profiles'
    for ring in RINGS:
        assert len(pool(ring)) == (3 if ring == 'outer' else 6), f'{ring} pool is the wrong size'

    spoil_keys = [s[0] for s in PLATE + ICHOR]
    assert len(spoil_keys) == len(set(spoil_keys)), 'duplicate spoil key'
    assert len(spoil_keys) == 10, 'two families of five'
    for key, _, _, price, _ in PLATE + ICHOR:
        assert price > 1, f'{key} sells for scrap money'

    # §4 -- and a trophy sells for exactly scrap money, which is the point.
    trophy_keys = [t[0] for t in TROPHY]
    assert len(trophy_keys) == len(set(trophy_keys)), 'duplicate trophy key'
    assert not set(trophy_keys) & set(spoil_keys), 'a trophy collides with a spoil'
    assert len(TROPHY) == 4, 'one trophy per monster tier'

    # §9.5.5 -- the pair is what decides a fight, so every tier has to sit clear
    # of the one outside it. Within a tier the split is free; the sum is not.
    by_tier = {t: [m[4] + m[5] for m in MONSTERS if m[2] == t] for t in (1, 2, 3, 4)}
    for t in (2, 3, 4):
        assert min(by_tier[t]) > max(by_tier[t - 1]), f'tier {t} is not harder than tier {t - 1}'

    open('app/Game/Monsters.php', 'w').write(emit_monsters_php())
    open('resources/js/game/monsters.ts', 'w').write(emit_monsters_ts())
    open('app/Game/Spoils.php', 'w').write(emit_spoils_php())
    open('resources/js/game/spoils.ts', 'w').write(emit_spoils_ts())
    print(f'{len(MONSTERS)} monsters, {len(spoil_keys)} spoils, {len(TROPHY)} trophies')
