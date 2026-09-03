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

# The pair is set by (tier, profile) and nothing else, so a tier-1 brute hits
# for the same on every kind of ground. What a biome changes is WHICH creature
# you meet and what comes off it -- never how hard the fight is. Two prospectors
# on the same ring are on the same ladder whatever country they are standing in.
PAIR = {
    (1, 'brute'): (12, 5), (1, 'carapace'): (6, 11), (1, 'swift'): (9, 8),
    (2, 'brute'): (27, 12), (2, 'carapace'): (13, 26), (2, 'swift'): (21, 19),
    (3, 'brute'): (36, 17), (3, 'carapace'): (18, 38), (3, 'swift'): (30, 23),
    (4, 'brute'): (60, 30), (4, 'carapace'): (32, 58), (4, 'swift'): (52, 40),
}

# A swift one wears the blade harder (§9.5.6's wearBias); the other two do not.
WEAR = {1: 1.3, 2: 1.4, 3: 1.5, 4: 1.5}

GOLD = {1: (5, 10), 2: (15, 28), 3: (33, 58), 4: (66, 115)}

# key, name, biome, tier, profile, description
#
# Five per biome at tiers 1, 1, 2, 3, 4 -- two on the rim so a new prospector's
# own country is not one creature repeated, and one for each ring after that.
#
# **No two in a biome share a (tier, profile) pair**, which is what makes the
# roster drawable: §9.5.2 gives the silhouette to the profile and the hide to
# the tier, so biome + tier + profile already identifies a monster and no two of
# the twenty-five can come out looking like the same animal.
MONSTERS = [
    # ------------------------------------------------------------------ forest
    ('moss_hound', 'Moss Hound', 'forest', 1, 'brute',
     'Runs the treeline in threes and takes the slowest thing on the road.'),
    ('thicket_darter', 'Thicket Darter', 'forest', 1, 'swift',
     'Goes through the undergrowth rather than round it, and is out the far side already.'),
    ('thornback', 'Thornback', 'forest', 2, 'carapace',
     'Every quill points out. Hitting it costs you more than missing does.'),
    ('rootbound_elder', 'Rootbound Elder', 'forest', 3, 'brute',
     'Stood in one place long enough to grow into it, and pulls free when you get close.'),
    ('pale_stalker', 'Pale Stalker', 'forest', 4, 'swift',
     'Keeps pace a ring behind you for a day, and closes on the hex you stop at.'),

    # ---------------------------------------------------------------- mountain
    ('pick_scarred_ram', 'Pick-Scarred Ram', 'mountain', 1, 'brute',
     'Somebody swung at it once. It has been coming back down the scree ever since.'),
    ('talus_creeper', 'Talus Creeper', 'mountain', 1, 'carapace',
     'Wedged in the loose rock with its shell uppermost, and the rock is the shell.'),
    ('slag_ogre', 'Slag Ogre', 'mountain', 2, 'brute',
     'Came down off a spoil heap and has been swinging the same girder since.'),
    ('ridge_wyrm', 'Ridge Wyrm', 'mountain', 3, 'swift',
     'Fast over broken ground and faster over you. It blunts whatever it is hit with.'),
    ('mythril_warden', 'Mythril Warden', 'mountain', 4, 'carapace',
     'Grew a seam through itself standing over one. Nothing has taken a hex off it yet.'),

    # ---------------------------------------------------------------- badlands
    ('slagjaw', 'Slagjaw', 'badlands', 1, 'brute',
     'Chews the crust for what is under it and will chew you for the same reason.'),
    ('ashcrust_grub', 'Ashcrust Grub', 'badlands', 1, 'carapace',
     'Bakes a shell out of the flat it lies on. You will step on it before you see it.'),
    ('cinder_lash', 'Cinder Lash', 'badlands', 2, 'swift',
     'Whips out of a vent, opens a seam in whatever it touches, and is gone.'),
    ('kiln_tortoise', 'Kiln Tortoise', 'badlands', 3, 'carapace',
     'Bakes itself hard in the vents and takes all afternoon to get through.'),
    ('ash_revenant', 'Ash Revenant', 'badlands', 4, 'brute',
     'Walked out of the center with the fire still on it and has not stopped since.'),

    # --------------------------------------------------------------- grassland
    ('ditch_crawler', 'Ditch Crawler', 'grassland', 1, 'carapace',
     'Sits in the wheel rut with its back plated over and waits to be stepped on.'),
    ('fen_boar', 'Fen Boar', 'grassland', 1, 'brute',
     'Comes out of the reeds at the shins and is through you before it is seen.'),
    ('reed_lurker', 'Reed Lurker', 'grassland', 2, 'swift',
     'Stands still in the tall grass until the grass beside it is what moves.'),
    ('tallgrass_prowler', 'Tallgrass Prowler', 'grassland', 3, 'brute',
     'You will hear the seed heads part and nothing else until it is on the hex.'),
    ('barrow_knight', 'Barrow Knight', 'grassland', 4, 'carapace',
     'Whatever it was buried in, it is still wearing. Nothing gets through the front.'),
]

BIOMES = ['forest', 'mountain', 'badlands', 'grassland']


def stats(tier, profile):
    """Attack, defense, wear bias and gold, all off (tier, profile)."""
    atk, dfn = PAIR[(tier, profile)]
    wear = WEAR[tier] if profile == 'swift' else 1.0

    return atk, dfn, wear, GOLD[tier]


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

# ----------------------------------------------------------- the biome drops

# §9.5.8 -- what only THIS country's monsters give up.
#
# The plate and the ichor say how hard the thing was; this says where it lived.
# It is the one drop a prospector cannot get by walking inward on ground they
# already know -- so kitting out of a biome's line is a reason to go and fight
# in that biome rather than the nearest one.
#
# Tier 1 like the rest of the spoil stock, and priced with the mid grades: it is
# a specific thing rather than a rare one, and §2 has nothing to fear from a
# material that is capped by how many packs a country can hold.

# key, name, biome, npcPrice, description
BIOME_SPOIL = [
    ('sap_matted_fur', 'Sap-Matted Fur', 'forest', 8,
     'Comes off the shoulders in one piece, and everything it touched is stuck to it.'),
    ('ore_crusted_chitin', 'Ore-Crusted Chitin', 'mountain', 8,
     'It fed on the seam it slept in. Half the shell is the seam.'),
    ('slagged_scale', 'Slagged Scale', 'badlands', 8,
     'Ran once and set again. Nothing the smith has will make it do that twice.'),
    ('pollen_choked_gill', 'Pollen-Choked Gill', 'grassland', 8,
     'Packed solid with a summer of it. The alchemist takes the packing, not the gill.'),
]

# §4/§9.5.8 -- tier 0, one per biome, and the rubbish that says WHERE.
#
# The trophy line below says what you fought and is dropped every time; this
# says where the fight happened, and is a roll. Two lines rather than one
# because they answer two different questions -- and it is the fight's own
# leaving now rather than the mining junk it used to borrow off §4, which was
# only ever borrowed because a strap was scarce and now is not (§7.6).

# key, name, biome, description
BIOME_LEAVING = [
    ('trampled_fern', 'Trampled Fern', 'forest',
     'Flattened in the scuffle and already browning. It was not worth much standing up.'),
    ('shale_grit', 'Shale Grit', 'mountain',
     'Came down off the slope while you were busy. It is in everything now.'),
    ('scorched_grit', 'Scorched Grit', 'badlands',
     'Fused into little beads where something hot went over it.'),
    ('broken_stalks', 'Broken Stalks', 'grassland',
     'A double handful of what the fight went through. It will not even burn well.'),
]

HEADER_MONSTERS = """Generated by scripts/gen_monsters.py -- do not edit by hand.

§9.5.2 -- twenty-five monsters, five to a biome, and **a country's five stand on
that country and nowhere else**. Walking from forest into badlands changes what
is on the road, which is what makes crossing the map a thing you look at rather
than a distance you spend.

The BIOME decides which five you can meet at all; the RING decides which of them
are out. A ring fields its own tier and the one outside it, so walking inward you
meet one you already know how to fight and one you do not -- and the rim fields
both of its tier ones, because a first country should not be a single creature
repeated.

**A biome changes what you meet, never how hard it is.** The pair comes off
(tier, profile) and nothing else, so a tier-1 brute hits for the same on every
kind of ground and two prospectors on the same ring are on the same ladder
whatever country they are standing in. What a biome is worth is its own drop
(§9.5.8), not an easier or harder fight.

No two monsters in a biome share a (tier, profile) pair. §9.5.2 gives the
silhouette to the profile and the hide to the tier, so biome + tier + profile
already identifies a creature -- and without that rule two of a country's five
come out as the same animal in the same colour.

`attack` and `defense` are FLAT, and they are not the percentage stats of the
same name: §8.1's ceiling is +15%, and a fight cannot be decided by a swing that
small. The profile is what a player reads -- a brute is high attack and low
defense, a carapace the reverse, a swift one is middling in both and wears a
weapon harder for it."""

HEADER_SPOILS = """Generated by scripts/gen_monsters.py -- do not edit by hand.

§9.5.8 -- what comes off a monster, in four lines.

Two Tier 1 families of five say how hard the thing was: a plate/hide line the
smith and the armorer want, an ichor/organ line the consumable bench wants.
Those are graded by tier and are the same everywhere, because a tier-3 fight is
a tier-3 fight on any ground.

A third Tier 1 line says WHERE it lived: one stock per biome, dropped only by
that country's five (§9.5.2). It is the one drop a prospector cannot get by
walking inward on ground they already know, which is what makes kitting out of a
country's line a reason to go and fight in that country.

And TIER 0, in two lines of its own. The **trophy** is one per monster tier and
is dropped every time -- what you fought. The **leaving** is one per biome and is
a roll -- where the fight happened. §4's junk argument applied to combat: worth a
gold, wanted by no recipe, and generous precisely because it is worthless, since
a drop nobody can build with cannot inflate anything.

*(The leaving used to be §4's own mining junk, borrowed. That was only ever a
borrow because a strap was scarce, and §7.6 made straps roomy -- so the fight
has its own rubbish now, and the mine keeps its.)*

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


def pool(biome, ring):
    """A ring fights its own country's tier and the tier outside it."""
    tier = RINGS.index(ring) + 1

    return [m[0] for m in MONSTERS if m[2] == biome and m[3] in (tier, tier - 1)]


def biome_of(key):
    return next(m[2] for m in MONSTERS if m[0] == key)


def drops(biome, tier):
    """Grade of its tier, rarely the one above, and this country's own two."""
    return {
        'plate': PLATE[tier - 1][0],
        'ichor': ICHOR[tier - 1][0],
        'rare': PLATE[tier][0] if tier < 5 and tier < len(PLATE) else None,
        # §4 -- the tier-0 leavings, one per tier and always dropped.
        'trophy': TROPHY[tier - 1][0],
        # §9.5.8 -- and the two that say where it lived.
        'biomeSpoil': next(b[0] for b in BIOME_SPOIL if b[2] == biome),
        'biomeLeaving': next(b[0] for b in BIOME_LEAVING if b[2] == biome),
    }


# ------------------------------------------------------------------- emitters

def emit_monsters_php():
    o = io.StringIO()
    o.write('<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Game;\n\n')
    doc(o, HEADER_MONSTERS)
    o.write('final class Monsters\n{\n')
    o.write('    /** §9.5.2 -- the roster. Flat attack, defense and HP, never percentages. */\n')
    o.write('    public const ROSTER = [\n')
    for key, name, biome, tier, profile, desc in MONSTERS:
        atk, dfn, wear, gold = stats(tier, profile)
        d = drops(biome, tier)
        rare = f"'{d['rare']}'" if d['rare'] else 'null'
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'biome' => '{biome}', "
            f"'tier' => {tier}, "
            f"'profile' => '{profile}', 'attack' => {atk}, 'defense' => {dfn}, "
            f"'hp' => {hp(tier, profile)}, "
            f"'wearBias' => {wear}, 'gold' => [{gold[0]}, {gold[1]}], "
            f"'plate' => '{d['plate']}', 'ichor' => '{d['ichor']}', 'rareSpoil' => {rare}, "
            f"'biomeSpoil' => '{d['biomeSpoil']}', 'biomeLeaving' => '{d['biomeLeaving']}', "
            f"'description' => {php_str(desc)}],\n"
        )
    o.write('    ];\n\n')
    o.write('    /**\n')
    o.write('     * §9.5.2 -- what stands on each country, ring by ring.\n')
    o.write('     *\n')
    o.write('     * The BIOME decides which five you can meet at all and the RING\n')
    o.write('     * decides which of them are out: its own tier and the one outside\n')
    o.write('     * it, so walking inward you meet one you know and one you do not.\n')
    o.write('     * The rim carries both of its tier ones, because a first country\n')
    o.write('     * should not be a single creature repeated.\n')
    o.write('     */\n')
    o.write('    public const BY_BIOME_RING = [\n')
    for biome in BIOMES:
        o.write(f"        '{biome}' => [\n")
        for ring in RINGS:
            keys = ', '.join(f"'{k}'" for k in pool(biome, ring))
            o.write(f"            '{ring}' => [{keys}],\n")
        o.write('        ],\n')
    o.write('    ];\n}\n')
    return o.getvalue()


def emit_monsters_ts():
    o = io.StringIO()
    doc(o, HEADER_MONSTERS)
    o.write("import type { Monster, Ring } from './types'\n\n")
    o.write('export const MONSTERS: Record<string, Monster> = {\n')
    for key, name, biome, tier, profile, desc in MONSTERS:
        atk, dfn, wear, gold = stats(tier, profile)
        d = drops(biome, tier)
        rare = f"'{d['rare']}'" if d['rare'] else 'undefined'
        o.write(
            f"  {key}: {{ key: '{key}', name: {ts_str(name)}, biome: '{biome}', "
            f"tier: {tier}, "
            f"profile: '{profile}', attack: {atk}, defense: {dfn}, "
            f"hp: {hp(tier, profile)}, "
            f"wearBias: {wear}, gold: [{gold[0]}, {gold[1]}], "
            f"plate: '{d['plate']}', ichor: '{d['ichor']}', rareSpoil: {rare}, "
            f"biomeSpoil: '{d['biomeSpoil']}', biomeLeaving: '{d['biomeLeaving']}', "
            f"description: {ts_str(desc)} }},\n"
        )
    o.write('}\n\n')
    o.write('export const MONSTERS_BY_BIOME_RING: Record<string, Record<Ring, string[]>> = {\n')
    for biome in BIOMES:
        o.write(f"  {biome}: {{\n")
        for ring in RINGS:
            keys = ', '.join(f"'{k}'" for k in pool(biome, ring))
            o.write(f"    {ring}: [{keys}],\n")
        o.write('  },\n')
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
    # §9.5.8 -- the two lines that say WHERE. The spoil is stock a bench wants;
    # the leaving is a gold and nothing else, like every other tier 0.
    for key, name, biome, price, desc in BIOME_SPOIL:
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 1, "
            f"'palette' => 'pelt', 'spoil' => 'biome', 'biome' => '{biome}', "
            f"'npcPrice' => {price}, 'description' => {php_str(desc)}],\n"
        )
    for key, name, biome, desc in BIOME_LEAVING:
        o.write(
            f"        '{key}' => ['name' => {php_str(name)}, 'tier' => 0, "
            f"'palette' => 'stone', 'biome' => '{biome}', "
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
    o.write('    ];\n\n')
    o.write("    /** §9.5.8 -- biome -> the stock only that country's monsters give up. */\n")
    o.write('    public const BIOME_SPOIL = [\n')
    for key, name, biome, price, desc in BIOME_SPOIL:
        o.write(f"        '{biome}' => '{key}',\n")
    o.write('    ];\n\n')
    o.write('    /** §9.5.8 -- biome -> the tier-0 leaving that says where the fight was. */\n')
    o.write('    public const BIOME_LEAVING = [\n')
    for key, name, biome, desc in BIOME_LEAVING:
        o.write(f"        '{biome}' => '{key}',\n")
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
    for key, name, biome, price, desc in BIOME_SPOIL:
        o.write(
            f"  {{ key: '{key}', name: {ts_str(name)}, tier: 1, "
            f"palette: 'pelt', spoil: 'biome', biome: '{biome}', "
            f"npcPrice: {price}, description: {ts_str(desc)} }},\n"
        )
    for key, name, biome, desc in BIOME_LEAVING:
        o.write(
            f"  {{ key: '{key}', name: {ts_str(name)}, tier: 0, "
            f"palette: 'stone', biome: '{biome}', "
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
    o.write('}\n\n')
    o.write("/** §9.5.8 -- biome -> the stock only that country's monsters give up. */\n")
    o.write('export const BIOME_SPOIL: Record<string, string> = {\n')
    for key, name, biome, price, desc in BIOME_SPOIL:
        o.write(f"  {biome}: '{key}',\n")
    o.write('}\n\n')
    o.write('/** §9.5.8 -- biome -> the tier-0 leaving that says where the fight was. */\n')
    o.write('export const BIOME_LEAVING: Record<string, string> = {\n')
    for key, name, biome, desc in BIOME_LEAVING:
        o.write(f"  {biome}: '{key}',\n")
    o.write('}\n')
    return o.getvalue()


if __name__ == '__main__':
    keys = [m[0] for m in MONSTERS]
    assert len(keys) == len(set(keys)), 'duplicate monster key'
    assert len(MONSTERS) == 20, 'four countries of five'

    for biome in BIOMES:
        mine = [m for m in MONSTERS if m[2] == biome]
        assert len(mine) == 5, f'{biome} is not five monsters'

        # The shape of a country's ladder: two on the rim, then one a ring.
        assert sorted(m[3] for m in mine) == [1, 1, 2, 3, 4], f'{biome} is the wrong ladder'

        # **No two in a biome share a (tier, profile).** This is what makes the
        # roster drawable: profile owns the silhouette and tier owns the hide
        # (§9.5.2), so without this two of a country's five come out as the same
        # animal in the same colour and the bestiary is a list of numbers.
        pairs = [(m[3], m[4]) for m in mine]
        assert len(pairs) == len(set(pairs)), f'{biome} draws two monsters the same'

        # And every country runs all three reads, so no biome is a place where
        # a player never meets the profile that wears their weapon (§9.5.6).
        assert {m[4] for m in mine} == {'brute', 'carapace', 'swift'}, \
            f'{biome} does not run all three profiles'

    # A ring still runs all three profiles across the map, which is the rule
    # that predates biomes: a player meets each read at their own difficulty.
    for ring in RINGS:
        out = {m[4] for biome in BIOMES for m in MONSTERS if m[0] in pool(biome, ring)}
        assert out == {'brute', 'carapace', 'swift'}, f'{ring} does not run all three profiles'

        for biome in BIOMES:
            got = len(pool(biome, ring))
            assert got == 2 if ring != 'mid' else got == 3, \
                f'{biome}/{ring} pool is {got}'

    spoil_keys = [s[0] for s in PLATE + ICHOR]
    assert len(spoil_keys) == len(set(spoil_keys)), 'duplicate spoil key'
    assert len(spoil_keys) == 10, 'two families of five'
    for key, _, _, price, _ in PLATE + ICHOR:
        assert price > 1, f'{key} sells for scrap money'

    # §9.5.8 -- one biome spoil and one biome leaving per country, no more.
    assert len(BIOME_SPOIL) == len(BIOMES), 'a country without its own stock'
    assert len(BIOME_LEAVING) == len(BIOMES), 'a country without its own leaving'
    assert {b[2] for b in BIOME_SPOIL} == set(BIOMES)
    assert {b[2] for b in BIOME_LEAVING} == set(BIOMES)
    for _, _, _, price, _ in BIOME_SPOIL:
        assert price > 1, 'a biome spoil sells for scrap money'

    # §4 -- and a trophy sells for exactly scrap money, which is the point.
    trophy_keys = [t[0] for t in TROPHY]
    leaving_keys = [b[0] for b in BIOME_LEAVING]
    biome_spoil_keys = [b[0] for b in BIOME_SPOIL]
    every = trophy_keys + leaving_keys + biome_spoil_keys + spoil_keys
    assert len(every) == len(set(every)), 'two drops share a key'
    assert len(TROPHY) == 4, 'one trophy per monster tier'

    # §9.5.5 -- the pair is what decides a fight, so every tier has to sit clear
    # of the one outside it. Within a tier the split is free; the sum is not.
    by_tier = {
        t: [sum(PAIR[(m[3], m[4])]) for m in MONSTERS if m[3] == t]
        for t in (1, 2, 3, 4)
    }
    for t in (2, 3, 4):
        assert min(by_tier[t]) > max(by_tier[t - 1]), f'tier {t} is not harder than tier {t - 1}'

    open('app/Game/Monsters.php', 'w').write(emit_monsters_php())
    open('resources/js/game/monsters.ts', 'w').write(emit_monsters_ts())
    open('app/Game/Spoils.php', 'w').write(emit_spoils_php())
    open('resources/js/game/spoils.ts', 'w').write(emit_spoils_ts())
    print(
        f'{len(MONSTERS)} monsters across {len(BIOMES)} biomes, '
        f'{len(spoil_keys) + len(BIOME_SPOIL)} spoils, '
        f'{len(TROPHY) + len(BIOME_LEAVING)} tier-0 leavings'
    )
