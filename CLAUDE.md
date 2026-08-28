# CLAUDE.md — Hex Mining Idle Game

> Design handoff. This document is the single source of truth for game systems.
> Every number here is a **starting value for tuning**, not a locked constant.

---

## 1. Product summary

A wallet-bound, browser/mobile **idle resource game** on a huge hex map (~5000×5000 tiles).
Players mine biome-locked materials on long timers, process them at shared settlements,
craft equipment, and raid PvE dungeons. Monetization is **NFT-only** (bundles, rare
materials, top-tier equipment). There is no fungible game token.

**Design north star:** every system must have a *sink*. A game that only accumulates is a
spreadsheet. Loss creates decisions.

---

## 2. Threat model — read this before touching economy code

The project assumes **thousands of bots will attempt to farm this**. The economy is
structured so botting is *economically pointless* rather than technically prevented.

| Layer | Mechanism |
|---|---|
| No grind→NFT faucet | NFTs are **never** dropped by mining or raiding. Only bought/traded on marketplace or crafted from capped rare mats. |
| No P2P resource trade | Direct player-to-player resource transfer does **not exist**. Removes the laundering/arbitrage vector entirely. |
| Gold has no NFT bridge | Gold buys *common and uncommon* only. Gold can never be converted to NFT value. |
| Sybil cost | One-time character mint fee per wallet + **wallet must hold a minimum crypto balance for ≥7 continuous days** before it can act. |
| Soft caps | Bag limits (§7.6), per-wallet rare-material caps, storage caps. A bot with 1000 wallets gets 1000× capped, non-liquid output, and 1000 bags that have to be emptied by hand. |
| Server authority | All timers are **server-side**. Client never asserts elapsed time. |

**Rule for any new feature:** if it creates a path from "grind time" to "external value,"
it's wrong. Route it through a cap, a decay, or a market chokepoint.

---

## 3. Three-currency model

The three currencies are strictly separated. No backdoor converts one into another.

### 3.1 Resources (raw / refined / rare)
- Mined from hex tiles, biome-locked
- **Non-tradeable** between players
- Carried against the two bag limits (§7.6) — over either one and you cannot travel
- Consumed by: crafting, repair, building upgrades, raid charges

### 3.2 Gold
- **In-game only**, NPC-facing, freely inflatable/deflatable by design (no on-chain consequence)
- Faucets: selling excess resources to NPC (deliberately bad rate), monster gold drops, quests/dailies
- Sinks: NPC repair, basic equipment, settlement upgrades, **guild capital bidding** (largest sink)
- Buys **common and uncommon only** (§8.0) — never convertible to NFT

### 3.3 NFT

> **Not built in this stage, and last in the build order.** Nothing
> blockchain-related is implemented beyond **wallet login**, which already
> exists. Everything below is settled design and none of it is a work item yet:
> the systems that make the game playable come first, and the on-chain exit is
> the final thing added, not something the rest is shaped around.

- The *only* externally tradeable value
- Categories: bundles, rare materials, top-tier equipment, cosmetics
- Sourced from: marketplace purchase, or crafting with Tier 3 + Tier 4 materials
- **Never** a grind reward

**Minting is a withdrawal, not a label.** Nothing sitting in a bag is an NFT. A
player *converts* a mintable item (§8.0) into one, and the item **leaves the
inventory** to do it — it is out of the play world until redeemed, the way
anything else in a vault is.

- Minting **captures state**: rarity, every rolled option (§8.0.1), and
  **durability**. Redeeming restores exactly that. Without the durability half,
  mint-at-3%-and-redeem is free repair and the §11.1 sink has a hole in it.
- Minting costs gold, which makes the exit itself a sink.
- A **destroyed** item cannot be minted, because it no longer exists (§8.2). A
  battered one can — burning a 40% epic is the owner's call to make.

The consequence is the one that matters: **§8.2's destruction can never burn
something a player paid real money for**, because the moment they cared enough
to mint it, it stopped being in the game. That is what lets destruction apply to
every rung with no exception and no confirmation dialog.

---

## 4. Materials (30 total, plus 10 tier-0)

### Tier 0 — Scrap (5, biome-locked, **not part of the 20**)

What a hex gives up to **bare hands**. Work a hex with no tool for its line (§8.0)
and you get this instead of the real material: same haul size, a fraction of the
worth, and no recipe anywhere will take it.

| Scrap | Biome | Instead of |
|---|---|---|
| Branch | Forest | Wood |
| Ore Chips | Mountain | Iron ore |
| Torn Hide | Plains/Tundra | Pelt |
| Gravel | Badlands | Stone |
| Chaff | Grassland | Fiber |

- Sells to the NPC for **1 gold**, and **every raw material must sell for more** —
  that gap is the entire argument for buying a first tool, so it is a rule, not a
  tuning value.
- Reaches no other tier: not a processing input, not a crafting input, never an
  NFT ingredient. It is a gold faucet of last resort and nothing else.
- Grants the line **reduced XP** (25%). Bare-handed work still teaches, badly. At
  full rate a player could max a skill without ever buying a tool, which would
  make the §8.0 ladder optional.
- A hex is **never blocked** for want of a tool. You may always work it; you just
  will not get its material out. The UI must say this as a warning, never as a
  refusal.

Scrap sits outside the count deliberately: it never enters the economy the §11
sinks have to balance.

**Junk (5) sits outside it too, and is not scrap.** Deadfall, Slag, Bone
Splinter, Cinder and Thistle sell for 1 gold, feed no recipe and reach no tier —
but they are not what a hex gives up to bare hands, they are the rubbish carried
out alongside. Keeping the two apart matters because §4.0's argument is about
what a *missing tool* costs you, and junk has nothing to do with tools.

### Tier 1 — Raw (15, biome-locked, the bulk of what fills a bag)

The five the gathering lines are named for:

| Material | Biome |
|---|---|
| Wood | Forest |
| Iron ore | Mountain |
| Pelt | Plains/Tundra |
| Stone | Badlands |
| Fiber | Grassland |

And ten **reagents**, two per biome, which are what the consumable bench (§8.4)
runs on. Two rather than one so a recipe can want two different things off a
single kind of ground:

| Biome | Reagents |
|---|---|
| Forest | Toadstool · Birch Sap |
| Mountain | Lichen · Alum |
| Plains | Bitterroot · Marrow |
| Badlands | Ashcap · Emberdust |
| Grassland | Blue Nettle · Clover |

Reagents are raw like any other: biome-locked, and **selling for more than
scrap**, which §4.0 makes a rule rather than a tuning value.

### Tier 2 — Refined (6)
| Output | Input |
|---|---|
| Planks | Wood |
| Ingots | Iron ore |
| Leather | Pelt |
| Cut Stone | Stone |
| Cloth | Fiber |
| Reinforced Frame | Planks + Ingots (cross-combo) |

### Tier 3 — Rare (5, PvP-zone tiles only, **capped per wallet**)
Ironwood (Forest) · Mythril Ore (Mountain) · Beastfang Hide (Plains) ·
Obsidian Shard (Badlands) · Silkweave Fiber (Grassland)

### Tier 4 — Raid materials (4, dungeon-sourced, not biome-locked)
| Material | Source |
|---|---|
| Essence | Common, all monster tiers |
| Shard | Mid-tier, **element/dungeon-typed** (Verdant/Ferrous/Sanguine/Cinder/Zephyr) |
| Relic | Rare, **pity-timer protected** |
| Core | Boss-only, gates best equipment tier |

Because Shards are dungeon-locked, top recipes needing multiple Shard types force
cross-map travel — same design pressure as biome-locked mining.

---

## 5. Map

### 5.1 Structure
- Hex grid, ~5000×5000 at ship scale

> **Currently set to a 200×200 test map** — `Balance::MAP_COLS/MAP_ROWS` are 200,
> and that is the only value that differs from ship. Everything else is a
> fraction of the map radius or an absolute hex count, so it scales on its own.
> Ship value: 5000 × 5000.
>
> The client is handed `cols`/`rows` by `/api/world` at boot rather than
> compiling them in, so this constant is the single source of truth and the
> TypeScript generator needs no matching edit. What it *does* need is a
> regenerated fixture: `php artisan game:worldgen-fixture`, then `npm run
> parity`. At 200×200 the world keeps its shape — 5 dungeons, and 161 villages
> to 26 cities to 4 capitals, which holds the §6 ordering with the capital
> count thin enough to be worth watching.

- **Exactly 2 mining slots per hex.** When both are full, the tile is closed to others.
- Tiles are **depletable**, then **regrow after ~9h** (tune). Depleted tiles keep their
  biome color (drained, not dead) and show remnant/sapling props.
- **A gathering tree can read a hex better than the tool can work it**
  (`seamGrade`, §7.4.3): a share of mines come up one grade past what the tool
  reliably takes, and never past what the hex actually holds (§5.3). Capped
  low — knowing your ground is worth a mine in eight, while a guaranteed grade
  would be a free rung of tool and would make the ladder optional.

### 5.2 Ring layout (concentric, drives generation)
| Ring | Contents | Ground with a seam in it |
|---|---|---|
| Outer | Villages (dense), safe mining (low yield), most spawns | **50%** |
| Mid | Cities, mixed safe/contested mining | **60%** |
| Inner (capital ring) | **Capitals.** Contested PvP-yield mining, **rare materials spawn here** | **70%** |
| Center | Dungeon entrances. **No settlement stands here** — but the ground is the contested ring's own. | **75%** |

**Half the outer rim has no seam in it at all, and the share climbs the whole
way in.** `Balance::MINEABLE_SHARE`. What misses out is **dead ground** — not
depleted, which is drained and regrows in about nine hours (§5.1), but ground
that never carried anything and never will.

**It is not a variant, and that is the load-bearing part.** A dead hex keeps its
biome's own variant and its own fill, and carries a `dead` flag instead. What
tells you is **what stands on it** — and §13.2 draws props inside sight and
nowhere else. So a waste is *invisible at a distance and obvious underfoot*,
which is the whole design: finding workable ground is something you do by
walking, not by reading the map from four days away.

**Five dead grounds, one per biome**, each its own silhouette with the life
taken out of it — a snag is a conifer stripped to the trunk, scree is a peak
come down, stubble is a tuft cut at the ankle. Named the way the water is
(§5.3): Deadwood · Scree · Dust Flat · Hardpan · Stubble. A dead forest and a
dead mountain are not the same place, and one shared word for both would throw
that away.

*(It was a single grey `barren` variant, bleached bright enough to read from
across the map. That was backwards — it handed the player the answer for free,
and every waste on the map was the same place.)*

The gradient runs the same direction as the two that were already there — Tier 3
density (§4) and the pack rate (§9.5.1) — so the middle of the map is richer,
more dangerous and more contested by **one** gradient rather than three. It is
also the answer to a question the flat map never asked: with 96% of every ring
workable, *where* you stood barely mattered, and the only thing distinguishing
one forest hex from the next was its grade roll.

**The regions are large, and being unable to see them is what gives that
teeth.** `Balance::BARREN_CELL` is 5, and the field is smooth noise rather than
a per-hex roll — §5.3 makes the same argument about biomes, and half a ring of
independently-rolled dead hexes would be speckle rather than country.

What keeps it from being a trap is that **you are never blind about where you
are standing**: the disc of seven always tells you which of your neighbours can
be worked. What you cannot do is see the answer from the far side of the map.
Biome is still drawn at any distance, so heading for the right *country* is
free; finding the live ground inside it is the walk.

Two consequences worth stating, because both are deliberate:

- **A village can stand in a waste.** Settlement sites are placed on their own
  lattice and know nothing about the field, so some of the 698 villages have
  little workable ground around them. That is a place with a bench and no seam,
  which is a real thing for a map to have.
- **Spawning refuses dead ground** (§5.4), and has to: §12 step 1 is *bring back
  branches bare-handed*, and a hex with no branches would soft-lock the arc
  before it started.

**The center is no longer barren of everything.** It was, and the sentence that
justified it — *the last step inward is a raid, never an errand* — is carried by
the settlement ban rather than by the emptiness: no village, city or capital
stands there, so nothing but a dungeon is worth walking to it for. What it is
made of is the inner ring's own table, rolled on the inner ring's column, paying
the inner ring's premium. It is the contested ring's ground with the towns taken
out, and the walk to a dungeon mouth now crosses country rather than a void.

The two opposing pulls (outward for resources, inward for processing + dungeons)
force constant traffic through the contested middle ring. This is intentional.

**Capitals stand in the contested ring, not the center**, and that is the
sharpest version of the same pull: the best bench in the game, the only one that
runs all five lines and reaches epic, sits on ground other prospectors are
working. You cannot process at the top tier without walking into the PvP band.
No settlement of any tier stands in the center, so the last step inward is a
raid and never an errand — it is the ban that carries that, not the emptiness.

### 5.3 Biomes
Clustered regions (Voronoi-style from seed points), **not** random noise — players need a
mentally navigable map. Rare-material biome variants sit inside/near the PvP ring.

**Four grades a biome, and the grade is a rung of the equipment ladder.** Base,
Better, Best, Contested — each named for a tool rung, each giving up a better
material than the one under it.

**No grade is sealed inside a ring except the last one.** The two middle grades
leak onto the rings outside their own at a few per cent — about one hex in fifty
for Better, one in two hundred for Best — so they are a lucky find out there and
never a supply. That is the whole reason: a grade found only where it is already
outclassed is a recipe nobody ever cooks. By the time you are standing in the
mid ring for its material, the ring has handed you better gear than the material
builds, and the recipe is dead on arrival. The leak is what lets a rim
prospector build the thing at the moment it would actually be an upgrade.

**Contested does not leak, and that is a §2 rule rather than a tuning value.**
It is §4's Tier 3: capped per wallet, the gate behind every mintable recipe, and
the reason §5.2 puts it in the PvP band — walking into the contested ring is the
price of it. A lucky Tier 3 on the safe rim would be the grind→NFT path the
threat model exists to close, arriving as a weight tweak nobody read as one.
There is a test sweeping the whole map for it, and two asserts in the generator. **Dead ground is none of them** (§5.2): it is
not a fifth grade and not a variant at all, because it is not something a hex
rolls — the field decides it before the roll happens, and the hex keeps whatever
variant and colour its biome would have given it.

**A grade is what a hex MOSTLY carries, never all it carries.** The ladder ran
one way: a common axe on a Hardwood Stand takes wood nearly every time and
hardwood occasionally, but reach a grade and you took it on *every* swing. That
made a hex a switch rather than a place. An Ironwood Grove is a grove of
ironwood with ordinary trees standing in it, and a Hematite Ridge is rock that
is mostly iron ore — so both tails exist now, and their shape is the rule:

| | |
|---|---|
| A grade **above** what your tool reaches | a long shot, halving again for every further rung |
| A grade **below** what you are cutting | merely uncommon, halving again the whole way down |

Falling short of the grade you are working is more ordinary than exceeding it,
so the lower tail is the heavier of the two. The grade still dominates — over
half of every haul — or it would mean nothing. The weights are tuning; that both
tails exist is not.

**Better ground is more work, and the rung it is named for is how much more.**
A hex's HP roll (§7.3) is scaled by its grade, and the scale is the *attack of
the tool that grade is named for* over the common rung's:

| Grade | Rung | Attack | HP is |
|---|---|---|---|
| Base | village | 3 | the roll, untouched |
| Better | city | 6 | **×2** |
| Best | crafted rare | 10 | **×3⅓** |
| Contested | epic | 14 | **×4⅔** |

So **every grade of ground takes its own rung exactly as long as base ground
takes the common one** — fifteen minutes to thirty, all the way up. There is a
test pinning that sentence, and another pinning the other half of it: at a
*fixed* rung, better ground is strictly more work.

It used to be a flat roll, which made the grade decoration: an Ironwood Grove
was the same afternoon's work as the plain forest beside it, and the only thing
gating the best material on the map was where it spawned. The rung is the price
now.

**Gold per hour comes out flat across the four, and that is the intended
shape.** The price ladder (2–3g · 4–5g · 7–9g) and the HP ladder are the same
ladder, so better ground never pays better *in coin* — it pays in **access**,
because it is the only source of the refined stock the upper recipes want
(§9.5.4's `medium` and `high` grades). The contested grade pays no gold at all:
a Tier 3 is capped per wallet and the trader will not touch it.

**A hex is never refused for want of the matching rung.** An epic hex worked
with a stone axe simply runs into the 60-minute ceiling, the same as any other
hex the arithmetic outruns. §5.6 has exactly two refusals and this is not one of
them.

### 5.4 Player spawning
- **Auto-assigned**, not player-chosen (prevents spot-sniping and landgrabbing)
- Placement favors **under-populated regions** by local density (hexes per active wallet
  in a radius). Fills outward naturally.

### 5.5 Hunting grounds
Not a tile type. **Temporary herd markers** spawn on open **Plains** hexes — open
meaning neither water nor a settlement nor a dungeon mouth, for the reason §9.5.1
keeps packs off a capital: a settlement is worked ground (§6), and a deer in the
market square is the same category error as a monster camped on the only
five-line bench in the region. They decay after ~4h. Yields Pelt, the plains animal parts (horn, sinew), the biome's critter and a little of
whatever grows there. No party, no raid charge, just time — and the bow decides
how much of it, because a herd is a pile of work read exactly as a hex is
(§7.3). A crude bow is the 25-minute reference mine; a Beastfang Bow is five,
and there is no bare-handed hunt to fall back on.

**No Tier 4, and that is a §2 rule rather than a tuning value.** Essence used to be
on this table, as "the only activity bridging the mining and raid material tracks".
A herd on a four-hour clock that anyone with a crude bow can shoot is a faucet for
the one tier the dungeons exist to gate, and §9.4's ladder is supposed to end at a
boss rather than at a deer. Raid materials come from raids.

**Rich ground is the other marker with this shape, and it is not this** (§5.7).
A pocket runs on the same time-bucketed hash and answers a different question:
a herd is *hunting's own work*, on hunting's own ground, while a pocket is any
hex being briefly worth more to whichever line already works it. That is why one
is biome-locked and the other cannot be.

**Plains and nowhere else, because that is hunting's ground.** Herds briefly wandered
onto every biome, on the argument that a bow should be worth carrying on a walk you took
for another reason. It made hunting the one line with no biome of its own — every other
tool in §8.0 is worked on named ground, and a line that pays out everywhere is a line the
map cannot put anywhere. The pull toward the plains is the same pull §4 puts on the other
four.

### 5.6 Sight and travel — a fog, not a fence

These were one number and are now two, and separating them is what makes the
map worth walking.

| | Rule |
|---|---|
| **Sight** | **1 hex.** Base `Balance::SIGHT_RADIUS`, up to 3 through the Explorer tree (§7.5). |
| **Sight while traveling** | **0.** You are between hexes, watching your feet. |
| **Travel range** | **None.** Any hex on the map is walkable from any other. |

**Travel has no reach limit and must not grow one.** Distance already costs the
one currency an idle game cannot inflate — hours, at five minutes a hex — so a
level gate on top of it would be a second answer to a question the clock has
already answered.

There are exactly **two refusals**, and neither is a distance. The edge of the
map, and an **overloaded bag** (§7.6). The second is the only one the player can
undo, and it can always be undone from the hex they are standing on — sell,
process, or throw something away. A refusal with no way out from where you are
standing would be a dead end rather than a decision.

**Outside sight the map is not blank, it is unscouted.** Terrain is a pure
function of `(col, row, seed)` (§5), so the client draws the land itself for
free, at any distance, and draws a **settlement glyph and its name** wherever
anybody lives. What it does *not* have is anything the server alone knows:
depletion timers, who is mining where, what a hex would pay. That is the whole
of the difference between scouted ground and the rest.

**The land is the BIOME, and what a hex holds is not the land.** Out there a hex
is painted in its biome's own colour and named for its biome and nothing more —
so a live seam, a rare grade and a waste (§5.2) are one picture until you have
walked to them. Close up the variant paints itself and the card names it, and
that difference is what the walk buys.

Two things used to give it away and no longer do. The map painted a fogged tile
in its **variant's** tint, so an Ironwood Grove was a different green from four
days off — which said both *there is a seam here* and *this good*. And the tile
card filled its portrait from the client's own copy of the material, on the
argument that the tint had already said it. Both were true readings of a map
where every hex had something in it; neither survives half a rim of dead ground
that wears the same fill as the living country beside it.

**The biome is the one answer that is true at any distance**, which is why it is
the one the card gives. You can see that there is forest over the hill; you
cannot see whether the stand is worth cutting, and you cannot see whether it is
dead. So a fogged Ironwood Grove, a fogged plain forest and a fogged Deadwood
all read *Forest*, and every one of them resolves on arrival. The portrait slot
holds a blank pin out there — no report, agreeing with the name above it.

*(Naming every fogged hex for its dead ground was tried first. It hid the seam
just as well and told a small lie to do it: the card asserted Deadwood over
ground that turned out to be living, and then changed its mind when you got
there. Hiding a fact and inventing one are not the same move.)*

**A place's identity is terrain, and the fog was never entitled to it.** Name,
tier and the lines it runs (§6) all fall out of `(col, row, seed)`, and the
atlas has charted every one of them at any distance since it was built — off
the same bundle, in the same session. Withholding them on the play map was a
fiction rather than a fog: the client knew and pretended not to. A scouted name
is drawn in vellum and an unscouted one dim, so the ring still means something;
what it means is *how much of the live half you are being told*, which is the
only half the server owns.

**The lines are on the card, never on the hex.** A tile is a shape on a map,
and a row of marks on it would be a legend to decode at a glance nobody asked
for. Tapping a hex is the question being asked, and the card is where it is
answered — a name, and what the place refines.

Three consequences, all deliberate:

1. **The live-state query is a disc of seven tiles** — the hex underfoot and
   its six neighbors — rather than the several hundred that reach-as-sight
   scanned, and thirty-seven at the very most. Sight is the one number in the
   game no amount of play widens past `SIGHT_RADIUS + SKILL_SIGHT_CAP`, and
   that ceiling is a query budget rather than a balance one — cost goes as the
   square of the radius.
2. **A journey costs no queries at all.** Sight closes to zero when the road
   starts and opens when it ends, so a walk of two hundred hexes and a walk of
   one both cost exactly two requests.
3. **Costing a hex is bounded by the same disc.** The per-tile preview endpoint
   refuses anything unscouted — otherwise it would be the map query in a slower
   form: one tile per request, and nothing stopping a client from asking about
   every hex on the map.

The glyphs are what keep a fog navigable: you can always see *that* there is a
capital over there, which is what makes deciding to walk to it possible, and
never *what is happening* there, which is what makes arriving worth something.

### 5.7 Pockets — ground that is briefly worth more

A **pocket** is a hex having a good few hours. It stands for about **four**, it
pays **half again on the haul**, and it can appear on **any workable ground**.

It is the herd's machinery (§5.5) and deliberately **not the herd's argument**.
A herd is a time-bucketed hash on the hex, derivable so that one nobody has
walked onto costs no storage, and a pocket is the same trick with its own salt.
But a herd belongs to *hunting* and stands on hunting's own ground, because a
**line** that pays out everywhere is a line the map cannot put anywhere. A
pocket is not a line. It is the hex being good today, and it pays into whatever
that hex already trains — so restricting it to one biome would hand one of the
five a bonus the other four never see, which is exactly what §8 rule 4 forbids.

**It is yield, never the clock.** §7.3 keeps the two apart on purpose: yield is
how big the haul is and attack is how fast it comes out. A pocket that also
shortened the mine would be a second answer to a question the tool already
answers, and the §8.0 ladder is what owns that one.

**It multiplies the GROUND, beside the ring premium** (§5.2), and never joins
the gear aggregate. It is a fact about the hex for the afternoon rather than
something a player is wearing, so §8.1's ceiling has no business clamping it —
the same reason the ring's ×1.35 and ×1.9 are not clamped either. Half again is
comfortably under that gradient, which is the point: rich ground is a reason to
stop where you are, never a reason to abandon the walk inward.

**It counts bare-handed** (§4.0). Scrap is the same haul size at a fraction of
the worth, so a pocket that needed a tool would be a bonus locked away from the
whole of §12's opening arc, which is worked by hand.

**A herd standing on one gets nothing from it.** The herd walked here and pays
out of its own table; the ground being good has nothing to do with the animal on
top of it. That is the same line §5.5 already draws when it says nothing comes
off the hex for a hunt.

**Nothing to work, no pocket.** The test is the seam rather than a list of
exclusions — water, dead ground (§5.2), a settlement and a depleted hex all fall
out of that one rule, and a mark on ground nobody can work would be an
advertisement for a refusal.

**§2 — it is not a faucet, because it cannot be farmed or re-rolled.** A hex has
two seats and then depletes for nine hours (§5.1); a pocket lives four. It pays
at most two hauls, to whoever is standing there, and there is no second roll to
wait for. Supply is capped by hexes and hours, exactly as §9.5.1 caps packs.

**The tell is an ANIMAL, and specifically the biome's own critter** (§4: *the
herbs say what grows on a kind of ground; these say what lives on it*).
Glimmermoth, rockmite, dustleveret, ashnewt, fenlark — one per kind of country,
settled on the hex. Animals find the good ground before you do, which is the
whole of the reasoning: you read a hex by **what is standing on it**, the way
you already read a herd or a pack, rather than by decoding a symbol somebody
drew on it.

*(An abstract mark was tried first — a gold hexagon in a dark socket, cut from
the ground so it lay in the stone. It was reasoned from the mechanic, and it
looked like a sticker: a dot on a tile is a thing to look up, and every other
piece of news on this map is a creature you simply see.)*

It stands with the herd and the pack because it is the same kind of news —
something alive is on this hex — and off to the **left of centre**, because all
three can share a hex and the visitors own the middle.

**It carries a sap halo, and a pack carries an ember one** (§13.2's halo rule
below). Two things stand on a hex and mean opposite things: a pack is a state to
deal with and this is one worth crossing the screen for, which is exactly what
§13.3 already defines that pair of colours as. The reading is learned once and
holds everywhere. On plains a grazing herd
and a hare can stand together, which is exactly right: they are two different
pieces of news.

Each is a **two-tone silhouette**, solid fills, no alpha (§13.2). One flat
colour would be the thing §5.2 says about dead ground — invisible on the country
it belongs to. The rockmite is banded across the shell rather than lit on one
side, because a bright shape on a dark body is the **pack's** whole tell (two
eyes looking at you) and a beetle must not borrow it.

**The card carries the same animal**, drawn on its own ground at card scale,
because the tell *is* the creature — a symbol on the card and an animal on the
hex would be two things to learn for one fact. It is the only row on a tile card
with a clock in it, since a pocket is the one fact there that expires.

**And the almanac carries all five**, in the Ground half beside the biome
variants and the water. That is the screen that exists to answer *where does
that come from* (§13), and rich ground is otherwise the one fact about a hex a
player would have to infer from a haul coming back bigger. Five entries make it
a field guide: this is what to look for, on this kind of country.

---

## 6. Settlements — shared infrastructure, NOT per-player bases

**Critical:** settlements are *shared world locations*, not personal bases. Players do not
place or own buildings. This keeps map space for resources and removes base-building
cognitive load from an idle game.

| Tier | Processing lines | Location | Notes |
|---|---|---|---|
| Village | 1 of 5 | Outer ring | Slowest, cheapest |
| City | 2 of 5 | Mid ring | Moderate |
| Capital | All 5 | Inner (contested) ring | Fastest, most expensive, one ring out from the dungeons |

Village count > City count > Capital count. This is a **cost curve outcome**, not a map-slot
system — no extra implementation needed, just tune upgrade costs.

### 6.0 Minimum spacing — a floor, not an average

Two settlements of the same tier are **never closer than**:

| Tier | Minimum gap |
|---|---|
| Village | 8 hexes |
| City | 11 hexes |
| Capital | 15 hexes |

This is guaranteed **by construction, not by chance**. Sites sit on a lattice of
one candidate per cell, and the window a site may occupy inside its cell is
narrower than the cell and centerd in it — so two neighboring sites cannot
close on each other past the leftover margin. A site free to roam its whole cell
can sit against the shared edge of two cells, which is what previously put
villages on touching hexes.

The floor sets the ceiling on density: raising a gap thins that tier out, and
the only lever left is the per-cell spawn chance. Village spacing costs about
40% of the village count, which is the intended trade.

**Where two tiers could crowd, the higher tier's gap wins and the lower tier is
the one that yields** — a village keeps a city's 11 hexes, not its own 8, and a
city is never moved by a village. Only that one pair can ever meet: villages and
cities share the mid/outer boundary, while the barren inner ring keeps capitals
and cities far apart by construction.

Cross-tier cannot be guaranteed the way same-tier is, because the two tiers sit
on lattices of different sizes and no choice of window separates them. It is a
rejection instead — the lower-tier site is simply dropped — which costs one
small lattice scan, and only for a candidate that has already earned its place.

Because village/city players are always missing process lines, they stay dependent on
dungeon loot and the NPC gold shop. This keeps every system relevant at every tier.

### 6.1 Processing queue
- **5 open slots** per feature, first-come-first-served, any player
- Guilds that own a feature get their **own separate 5-slot line** (parallel, not competing
  with the public queue)
- When all slots at a station are busy, no one else can queue until one frees. Realistic
  congestion is intended, especially at popular dungeon-adjacent capitals.

### 6.2 Presence bonus (idle-safe)
A player "at" a settlement while their material processes gets a speed bonus **and** skill XP
for that material line. Requires only presence (session flag) — **no click-checks or QTEs**,
this is an idle game. Bot risk is low because presence produces nothing by itself; it only
accelerates an already-capped queue.

### 6.3 The five processing jobs
Each of the five lines is a **job with its own tree** (§7.4) — Sawyer, Smelter,
Tanner, Mason, Weaver — levelled by finished runs on that line and by nothing
else. They are what makes a settlement somewhere you get *better*, rather than a
timer you feed.

What their nodes may touch is bounded by §7.4.3 and by nothing new: the run's
clock (`processingSpeed`, line-locked), what it eats (`costReduction`), what
comes off it (`batch`, extra output **per run** rather than per batch, so the
number the player picks never multiplies the effect), what standing there is
worth (`presence`, §6.2), and how many runs a line can keep going at once
(`runSlot`). The two effects a craft bench owns — a rolled option and a starting
durability — belong to an *object*, and a run makes a material, which has
neither.

**`runSlot` is the capability a processing tree ends on**, and it is deliberately
the last thing bought: **one run of a line at one settlement** is the rule, and a
reeve who keeps a second pit going has earned it on that line and on no other.
§6.1's five public slots are untouched — the settlement is as congested as it
ever was, and what changed is how much of the congestion one prospector may be.

**Per settlement and per line, not per character.** It was the latter, across
the whole map: a run of planks left at a village four days' walk away refused
every saw pit in the world — while §8.4 argued in the same breath that "the real
limit on how much you have going at once is still the walking". Two rules about
one thing, disagreeing, and the walking is the one worth keeping. A capital
running all five lines therefore holds five of one prospector's runs, one to a
bench, which is most of what a capital is for (§6).

**The ceiling is ten, and it is the only global number.** `OUTSTANDING_WORK_CAP`
counts processing runs and bench crafts together, because to a player they are
one thing: something left in a building that has to be walked back to. Ten so
the walking is the limit right up until the bookkeeping would be — and a cap
rather than none at all, because §2 assumes thousands of bots and an unbounded
queue of parked work is a wallet running two hundred benches it never walks
between. Ten is a route a person plans; two hundred is a spreadsheet.

---

## 7. Character

**One character per wallet. Soulbound (non-transferable).** Gear and land are the tradeable
things, not the character — this prevents power-account selling.

### 7.1 Level
Level unlocks **capacity, not power**: access to higher-tier hexes and dungeon
floors. A whale can out-scale logistics but never out-damage a grinder.

> **Action points are gone.** AP gated a mine on a pool that refilled on a
> clock, which put a second timer underneath the one the mine already runs. A
> limit on how much can be done in a day will come back, but it is not going to
> be that one, so the pool and its columns were removed rather than left
> dormant and half-true. Nothing currently rations how many mines a day a
> character may take — that is a known gap, not an oversight.

Three things are deliberately *not* on that list. Travel range, because there is
no reach to unlock (§5.6). Sight, because the only thing that widens the eye is
having walked (§7.5). And the **bag** (§7.6), for the same reason as sight:
carrying capacity used to be a level reward, which made it a problem that solved
itself — by the time it mattered you had outgrown it. It is the Explorer's now,
and walking is the one reward in the game that cannot be bought.

### 7.2 Gathering lines (5, one per material line)
Woodcutting · Mining · Hunting · Quarrying · Harvesting

Each reduces mine time / boosts yield **for its own material only**. XP comes from mining
that material *and* from presence during its processing — so no single grind path maxes a
tree alone.

Cap total points so characters are meaningfully specialized, not universally strong.

Each line also has its **own tool slot** (§8.0) — axe, pickaxe, bow, hammer, sickle.
Tools are not the specialisation lever: all five can be equipped at once and every line
offers the same ladder. The skill point cap is the lever, and it is the only one.

### 7.3 Working a hex — there is no timer, there is HP and a rate

**Mining, gathering and hunting have no cooldown.** They never really did — what
they had was a rolled duration with a discount stapled to it — and now they have
nothing of the kind. A hex is a **pile of work with a number on it**; a tool has
an **attack**; the clock is what falls out of dividing one by the other.

```
rate      = (attack + skill_attack + skill_bite) * (1 + tripReduction)
trip_time = clamp(hp / rate, 1min, 60min)
```

- `hp`: **2,700–5,400**, rolled per tile, then **scaled by the tile's grade**
  (§5.3) — base ground untouched, up to ×4⅔ on contested. A herd is 4,500 (§5.5)
- `attack`: **the whole base rate**, and it is the tool's — or, for gathering
  alone, `BARE_HAND_ATTACK` (**3**, the common rung's own — see below)
- `skill_attack`: `floor(level / 10)` — five more points at maxed line skill
- `skill_bite`: whole points off the **line's own tree** (§7.4.3), up to
  `SKILL_BITE_CAP` (**5**)

**The gathering tree buys attack, because there is no timer left to shave.** It
used to buy `tripReduction`, and that was a percentage on this rate sharing one
clamp with gear, options and potions (§8.1 rule 1) — so a prospector in a decent
coat had already spent the ceiling and the ten nodes they had bought were worth
nothing at all. That is the shape §7.4 forbids, reached through the clamp
instead of through a missing call site.

A count cannot be clamped away by a coat, and being flat inverts who it is worth
most to: five points is most of a Stone Axe and a fifth of a Mythril Pickaxe.
That direction is deliberate. Gear is the ladder (§8) and a tree is a **different
road to the top rather than a longer one**, which is the same bargain
`SKILL_PAIR_CAP` strikes on the combat side — five is about one rung of §8.0's
ladder and never a tier of it.

**HP is what the world rolls, and it is the only thing it rolls.** There used to
be a range of *seconds* that a reference rate converted into work, which meant a
tile carried its answer rather than its question: the same fact stored once as a
duration and once as a pile, with a constant between them waiting to drift. How
long a hex takes is `hp / rate`, and that is nobody's business but the
character's.

The range is **calibrated once and then left alone**: 2,700 is fifteen minutes
for somebody holding the common rung (attack 3) with nothing learned yet, and
5,400 is thirty. That is the *base* grade of ground; §5.3 scales the roll by the
rung a better grade is named for, so those same fifteen-to-thirty minutes are
what every grade costs the rung it belongs to. That is the whole of what the numbers mean and the only reason
they are these numbers — there is a test pinning it. Seconds appear nowhere in
the model.

**The tool is the rate, not a bonus on top of one.** Mining and hunting never
read the bare-handed number: §8.0 rule 1 refuses the verb outright without its
tool and points at the gather button instead, so a mine is worked with the tool
or with the hands and **never with both**. That is what makes a pickaxe's attack
mean something plain — six times a Stone Axe is six times the rate, not 1.6
times it once a shared base is added underneath.

**Zero attack is a refusal, not a very long mine.** Nothing in your hands and
nothing learned means the ground does not move, so the arithmetic has no answer
to give: the mine reports `able: false`, and the card says so where the clock
would have been. A number nobody can reach is worse than an honest no.

**A line you have not learned adds nothing.** `floor`, not `ceil` — the skill
term used to hand the very first level of a line a free point, so a panel
describing what your skill was worth to this mine printed **+1** at a character
who had never swung an axe.

**Bare hands take the common rung's own bite, and the tie is deliberate.**
`BARE_HAND_ATTACK` and `MINING_COMMON_ATTACK` are both **3**, so a Stone Axe
works a hex in exactly the time bare hands do.

That is not the ladder failing to start. Hands are **gathering's rate and no
other verb's**: §8.0 rule 1 refuses a mine outright without the line's tool and
points at the gather button instead, so a hex is worked with the tool or with
the hands and the two never race on the same verb. There is nothing for the
first rung to be faster *than*.

What it buys is what comes home. §4.0 is the whole argument: bare-handed work
pays **scrap** — a gold apiece, no recipe anywhere will take it, and it grants
the line a quarter XP — while the seam it displaced feeds every recipe in the
game. §12 step 5 is *buy the axe, work the same hex, see the payoff*, and the
payoff is the haul, not the clock.

So **the ladder is felt from the second rung up**, and the first rung is felt in
the bag. They were **4** for a while, which was worse than either reading: hands
beat the axe outright, twelve minutes against fifteen. A test pins the tie at
the bottom and pins every rung above it as strictly faster.

*(It used to say hands must stay strictly under the cheapest tool, on the
grounds that a bought tool should always be felt. That fought §4.0 rather than
supporting it — if the first tool has to be faster as well as richer, then
scrap's poverty is doing no work — and it left two tests failing against a
constant that was right.)*

*(The mine used to subtract flat minutes — twenty for skill, ten for
best-in-slot. That made a good tool worth exactly as much on a poor hex as on a
rich one, which is backwards: the ladder should pay most where the ground is
hardest.)*

The measured ladder, unskilled:

| Rung | Attack | 2,700 HP | 5,400 HP |
|---|---|---|---|
| Bare hands *(gather only)* | 3 | **15m** | **30m** |
| Village | 3 | **15m** | **30m** |
| Crafted starter | 4 | 11m | 22.5m |
| City | 6 | 7.5m | 15m |
| Crafted uncommon | 8 | 5.6m | 11m |
| Rare | 10 | 4.5m | 9m |
| Epic (NFT) | 14 | 3.2m | 6.4m |
| Legendary | 17 | 2.6m | 5.3m |
| Unique | 19 | 2.4m | 4.7m |

Best tool, maxed skill and best-in-slot gear on the hardest hex: **3.3 minutes**.

**Gathering is this same arithmetic with your hands in the tool's place** (§4.0).
Not a separate verb with a separate schedule — the identical hex, the identical
HP, and the identical *rate*, worked at the one rung that needs no purchase.
That is exactly why it is a floor rather than a punishment. What it costs you is
**worth**, not time: scrap sells for a gold and the seam does not.

**A herd is a pile of work too** (§5.5). Hunting used to be a flat 25 minutes
sitting deliberately *outside* this formula, because the old floor clamp would
have rounded the difference away. It goes through the same arithmetic now, so a
crude bow is the 25-minute reference and a Beastfang Bow does it in five.
Hunting was the one line whose tool bought nothing but permission.

**The floor is a guard rather than a lever, and it sits at 1 minute.** It was
fifteen and it *bound*, which made the top half of the ladder wasted ground —
paid for and never felt, with §8 rule 4 promising every line the same ladder.
Fifteen minutes is where the common rung lands, not where the game stops. One
minute rather than three because the tool being the whole rate makes the ladder
six times steep rather than three; on real gear it never binds, and if it ever
does that is a tuning bug rather than a design working.

**A gathering tool has no percentage at all.** Its base *is* the attack. It used
to lead with "+2% woodcutting yield" and have its attack derived from that, and
the two were never the same question: **attack is how fast you work through a
hex, yield is how big the haul is.** One number each, and a tool answers the
first.

Yield is still reachable on a tool — through a rolled option (§8.0.1), which is
where a *bonus* belongs. What a tool is *for* is speed.

**A tool's attack is mining attack and nothing else.** §8 rule 5 keeps the two
ladders apart in both directions: a pickaxe is worth nothing in a fight, which
is why combat reads the explicit `attack` on the weapon and the worn pieces and
never this.

### 7.4 Jobs and their skill trees

**Sixteen jobs, four kinds.** Each has a tree of 30 nodes bought with skill
points. What differs between the kinds is where the *job level* comes from.

There is a **seventeenth job that plays by none of these rules** — Explorer,
§7.5. Everything below describes the sixteen bought trees; read §7.5 before
assuming a rule here covers all of them.

| Job | Kind | Bench / role | Level comes from |
|---|---|---|---|
| Woodcutting | gathering | forest | its §7.2 skill level |
| Mining | gathering | mountain | its §7.2 skill level |
| Hunting | gathering | plains, tundra | its §7.2 skill level |
| Quarrying | gathering | badlands | its §7.2 skill level |
| Harvesting | gathering | grassland | its §7.2 skill level |
| Sawyer | processing | wood line (§6) | finishing a run of planks |
| Smelter | processing | iron line | smelting ingots, banding a frame |
| Tanner | processing | pelt line | tanning leather |
| Mason | processing | stone line | dressing cut stone |
| Weaver | processing | fiber line | weaving cloth |
| Smith | craft | weapon bench (§8.4) | forging a tool or weapon |
| Armorer | craft | armor bench | making armor, boots or gloves |
| Alchemist | craft | consumable bench | brewing a potion |
| Shieldbearer | battle | defense | fighting with a shield (§9.5) |
| Swordhand | battle | balance | fighting with a sword (§9.5) |
| Runecaster | battle | offense | fighting with a focus (§9.5) |

**Processing is not crafting, and the split is the input.** A craft bench spends
refined stock on an object; a processing line makes the stock. So the five
processing trees deal in the three things a §6 run actually has — how long it
takes, how much raw it eats, how much refined comes off it — and in what the
line can make at all. Five of them rather than one because §6 is already a
five-line structure: a village runs one of the five, a city two, a capital all
five.

Only one stat applies to a run, so the rest of a processing tree is spent on the
three things that are not the clock: what the run eats, what comes off it, and
what being *there* is worth. The five differ in which of those they lean on — a
Tanner is nearly half `presence`, because a pit is watched rather than set going;
a Sawyer leans on the clock; a Smelter and a Weaver on fuel and stock.

**A processing job's level comes from finished runs, paid on what came off the
bench.** A bigger batch is more work and teaches more; a run walked away from
teaches nothing, exactly as an abandoned mine pays nothing (§11.1).

**A gathering job's level is not a new number.** It is the §7.2 skill level that
line has always had — the same figure that takes up to 20 minutes off a mine
(§7.3). One number, so there is never a second opinion about how good a
woodcutter someone is, and the five gathering trees are playable the moment they
exist rather than waiting on a new grind.

That makes gathering the one kind whose level *does* grant power. It always did;
§7.4.1 below is about the levels this system introduces.

**EVERY `stat` node is locked to its own class. There is no global one.** A
tree makes you better at the work its job is about and at nothing else — without
that, a character takes three trees and stacks all of them on one mine, which is
the shortcut the line-locked tool ladder (§8 rule 1) exists to close, arrived at
through the skill panel instead.

| Kind | Locked to | Which means |
|---|---|---|
| gathering | its **line** | a Woodcutting node counts in a forest and nowhere else |
| processing | its **line's bench** | a Sawyer's speed counts at a saw pit and nowhere else |
| craft | its **bench category** | a Smith is faster at the weapon bench, not at the tannery |
| battle | its **weapon family** | a Swordhand's nodes are worth nothing with a shield on the arm |

**A forest and a saw pit are two different pieces of work on the same word**, so
the lock is on the pair rather than the line: a Woodcutting node pays out on a
felling mine and a Sawyer node at the pit, and neither reaches the other. This
is the one place where the *action* being costed and the *material line* under
it are two things rather than one.

**A battle tree is locked to the family, not to the job's role word.** §9.5.4
already makes the family in the slot your class; anything less would let a tree
bought for one weapon pay out through another.

*(Two whole classes of node were paying out off-class before this. Craft trees
handed out yield, mine time and travel speed, so an Armorer's tree made somebody
faster at **mining** — a craft job improving work it has nothing to do with. And
every gathering tree carried `travelSpeed` nodes that were dead weight twice
over: filed under a line, they only counted on that line's work, and walking is
not woodcutting, so they could never pay out at all.)*

**What each kind may move, after that:**

| Kind | Stat | And the rest of the tree |
|---|---|---|
| gathering | `yield` — the size of the haul, and the only percentage a mine has left | `bite`, `toolWear`, `seamGrade` |
| processing | `processingSpeed` — the one thing a bench clock reads (§8.4) | `costReduction`, `batch`, `presence`, `runSlot` |
| craft | `processingSpeed` | `costReduction`, and what the bench makes: `craftDurability`, `craftOption`, `optionTier` — or, at the consumable bench, `batch`, `brewExtra`, `stackCap` |
| battle | none — the pair is solid (§9.5.4) | `pair`, `battleWear`, `weaponWear`, `goldFind`, `lootOption`, and the three that sharpen its skills (§9.5.9) |
| wayfaring | nothing at all (§7.5) | `sight`, `bagUnits`, `bagRows` |

**A craft tree spends most of itself on what comes off the bench** rather than on
the clock, for two reasons: thirteen speed nodes came to 17% against a 15%
ceiling, which left gear nothing to add, and a bench is worth walking to for what
it *makes*, not for the ten minutes it saves.

**The consumable bench is the one that proves the rule.** A potion has no
durability and no rolled line, so an Alchemist's tree cannot carry the two
effects the other two benches are built on — it deals in how many flasks come off
a rack and how deep the shelf is instead. A tree of effects that do nothing to
the thing being made is the shape §7.4.3 exists to forbid.

**No two trees of a kind are the same tree.** What a node does is chosen per job
and what it is *worth* is read off its depth, so a capstone outweighs an opening
node of the same kind — five gathering trees used to run one shared pattern and
the three battle trees were twenty points of the pair followed by ten identical
wear nodes. There is a test asserting no two trees share a shape.

**The three battle jobs level on the road** (§9.5), and on nothing else. Which
of them earns the XP is decided by the **weapon family** in the `weapon` slot —
shield, sword or focus — so the job you level is the way you actually fight.
They must never be given a stand-in XP source from gathering; a battle job that
levels by digging would make combat optional.

*(They were dormant until map combat existed, which is what §9.5 was written to
fix. Their trees stayed dormant a while longer — two thirds percentages nobody
could feel, one third ability hooks waiting on parties. Both are gone. Dungeon
floors will feed the same three jobs when they are designed.)*

**Nothing in a tree may wait on a system that does not exist.** A node is bought
with one of a hundred skill points; a node that pays out only once dungeons are
designed is asking for that point on credit, and the panel has no honest way to
say so. Effects for undesigned systems belong in §14 until the system is built.

#### 7.4.1 Two numbers, and only one of them is power

- **Character level** — 1 to 100, one **skill point** per level. This is the only
  source of points. 100 points buys three complete trees (30 each) with 10 left
  over, which is deliberately just short of a fourth — out of **sixteen** trees,
  so the choice of which three is most of what a character is.
- **Job level** — earned by doing that job's work. It **gates** nodes and, for
  craft and battle jobs, does nothing else: no stat, no yield, no speed. It is
  the proof you have done the work, not a reward for it. Gathering levels are the
  exception noted above — they predate this system and still drive §7.3.

Points are the scarce thing, and they are spent on *breadth*. Job levels are the
slow thing, and they are earned on *depth*. Neither substitutes for the other:
you cannot buy your way past a job level, and grinding a job to 30 gives you
nothing until you spend a point.

#### 7.4.2 Tree shape — 30 nodes, five tiers

| Tier | Nodes | Job level | Prerequisite |
|---|---|---|---|
| 1 | 6 | 1 | none — open the moment the job exists |
| 2 | 8 | 5 | one tier-1 node |
| 3 | 8 | 12 | one tier-2 node |
| 4 | 6 | 20 | one tier-3 node |
| 5 | 2 | 28 | **two** tier-4 nodes |

The capstones needing two parents is what makes a tree a tree rather than a list:
a full 30-point investment is forced through choices, and a partial one has to
decide which branch it is actually committing to.

**Nodes are bought, never refunded.** A respec would turn the point cap into a
suggestion and let one character be every specialist in turn, which is exactly
what §7.2's cap exists to prevent.

#### 7.4.3 What a node may do — and the ceiling, again

This is the part that can break the game, so the rule is mechanical rather than
a matter of judgment. A node's effect must be one of these, and nothing else:

| Effect | What it does | Cap |
|---|---|---|
| `stat` | A `StatKey` percentage | **feeds the same aggregate and clamp as gear, options and buffs** |
| `pair` | Whole points of `attack` or `defense` (§9.5.4) | `SKILL_PAIR_CAP` (12) |
| `battleWear` | A share of what a fight takes off the worn kit, spared | `SKILL_BATTLE_WEAR_CAP` (15%) |
| `skillPower` | More of the *extra* on the family's three skills (§9.5.9) | `SKILL_BATTLE_POWER_CAP` (25%) |
| `skillCooldown` | Whole rounds off every one of their cooldowns | `SKILL_BATTLE_COOLDOWN_CAP` (+2) |
| `skillStun` | A round longer on a stun | `SKILL_BATTLE_STUN_CAP` (+1) |
| `weaponWear` | The same for the blade, which pays its own stream (§9.5.6) | `SKILL_WEAPON_WEAR_CAP` (15%) |
| `goldFind` | More of what a pack pays (§9.5.8) | `SKILL_GOLD_FIND_CAP` (25%) |
| `lootOption` | Chance of an extra rolled option on looted gear | `SKILL_LOOT_OPTION_CAP` (25%) |
| `bite` | Whole points of **mining** `attack` on that line (§7.3) | `SKILL_BITE_CAP` (5) |
| `toolWear` | Share of mines that leave the line's tool untouched | `SKILL_TOOL_WEAR_CAP` (25%) |
| `seamGrade` | Share of mines that come up one grade past what the tool reliably takes (§5.3) | `SKILL_SEAM_GRADE_CAP` (12%) |
| `presence` | Added to the §6.2 presence bonus, on that line's bench | `SKILL_PRESENCE_CAP` (20%) |
| `runSlot` | Runs of that line you may keep going at once | `SKILL_RUN_SLOT_CAP` (+2) |
| `craftOption` | Chance of an extra rolled option (§8.0.1) on what you make | `SKILL_OPTION_CHANCE_CAP` |
| `optionTier` | Chance a rolled line is drawn a grade deeper, never past the rung | `SKILL_OPTION_TIER_CAP` (25%) |
| `craftDurability` | Higher starting max durability on what you make | `SKILL_DURABILITY_CAP` |
| `brewExtra` | Chance of an extra flask off a brew | `SKILL_BREW_EXTRA_CAP` (35%) |
| `stackCap` | Deeper shelf for each potion carried (§8.5) | `SKILL_STACK_CAP` (+10) |
| `costReduction` | Fewer inputs per craft | `SKILL_COST_REDUCTION_CAP` |
| `batch` | More output per craft or processing run | `SKILL_BATCH_CAP` |
| `sight` | Whole hexes of sight (§5.6), on top of the base one | `SKILL_SIGHT_CAP` |
| `bagUnits` | Units the bag holds (§7.6), on top of the flat base | `SKILL_BAG_UNITS_CAP` (+80) |
| `bagRows` | Distinct things the bag holds, on top of the flat base | `SKILL_BAG_ROWS_CAP` (+20) |

**Every kind on that list has a call site, and that is the rule the list is
for.** `unlock` used to be on it — "makes a recipe craftable at all", uncapped,
where the trees were supposed to grow. It gated nothing: the server collected the
targets into an array and no recipe, hex or bench ever asked. A third of every
processing tree, a quarter of every gathering tree and four nodes of each craft
tree were bought with the scarcest thing a character has and changed no outcome.

That is not a missing feature, it is the thing §7.4 already forbids: **nothing in
a tree may wait on a system that does not exist.** A node is a point spent, and
the panel has no honest way to say *not yet*. So the kind is gone rather than
dormant, and what replaced it is the work each job actually does — a seam that
survives the mine, a tool that outlasts it, a second pit going, a deeper draw on
what the bench rolls. When a system arrives that genuinely needs gating, it
arrives with a gate that gates something.

**`pair` is solid and has nothing to do with the ceiling**, because attack and
defense are solid numbers (§9.5.4). It has its own cap instead, and the cap is
the point: twelve against a legendary kit's ~41 attack means a third of a
hundred skill points, behind job level 28, is worth roughly a rung of gear.
Never more — gear is the ladder §8 is built on, and a tree is a different road
rather than a longer one.

*(A battle node used to move `power` or `defense` by a percent, and it was the
least legible thing in the game: "+1% power" moved a common sword's 5 attack to
5. Two thirds of every battle tree did that, and the other third was ability
hooks waiting on parties and raids that are not designed (§14) — a node nobody
can feel is a node nobody should be asked to spend a point on. Every battle node
is felt now, the next time a pack stops you on a road.)*

**The two wear kinds are two questions, not one.** §9.5.6 runs two streams —
what hit you comes off the armor, what you hit comes off the blade — so a tree
that spared both with one number would be answering both with one node.
`battleWear` is the shieldbearer's, `weaponWear` the runecaster's, and both are
capped low because that bill is the largest sink in the game (§11.1).

**A battle tree also gets paid.** `goldFind` and `lootOption` are the only place
§9.5.8's payout is touched by a skill, and they are deliberately contained: coin,
which bridges to nothing external (§3.2), and an extra *option* on looted gear,
which is the same mechanism a harder pack already uses. Neither can reach rarity,
because §2 forbids a grind→NFT faucet and loot stops at rare whatever anybody has
bought.

**A skill point can never take a stat past +15%.** `stat` nodes feed the very same
sum and clamp as equipment, rolled options and potions (§8.1 rule 1). What they
buy is a *different road to the ceiling*, not a higher one — which is the point:
a player deep in a tree reaches the same cap as one in full epic gear, and §8.1
rule 4 stays true.

The caps that are not the stat ceiling exist to protect §11 and §5.6, not §8.
`costReduction`, `batch` and `brewExtra` thin a materials sink; `craftDurability`
and both wear kinds thin the repair sink; `seamGrade` reaches past the tool
ladder and `stackCap` thins the bag pressure §7.6 runs on. Left uncapped, a maxed specialist would
quietly switch off the loss the whole economy is balanced around.

`bagUnits` and `bagRows` are the same argument in counts rather than
percentages. The bag is the pressure that turns a haul into a decision (§7.6),
and an uncapped tree could carry enough that the decision never arrives — which
would switch off the selling, processing and dumping §11.1 runs on.

`sight` is the odd one, and its cap is the sharpest because sight is the
radius of the map query — cost grows as the *square* of it. One hex is seven
tiles, two is nineteen, three is thirty-seven, and ten would be three hundred
and thirty-one. `SKILL_SIGHT_CAP` is a query budget rather than a balance one,
and it is what lets sight be a reward at all instead of a scanner handed to
anyone patient enough to walk. It is also a **count, not a percentage**, so like
`bite`, `runSlot`, `stackCap` and the bag pair it has nothing to do with the
stat ceiling. It is capability.

**Where the trees grow now is depth, not a kind.** A node's value is read off the
tier it sits at, so a tier-5 node of a kind is worth two or three times a tier-1
one. New materials and new equipment tiers arrive as new *recipes* at the benches
that already reach them (§8.0) rather than as nodes promising them, which is what
keeps a point spent on a tree worth something the day it is spent.

#### 7.4.4 Pacing — six months, and it does not move

The target is **level 100 after roughly 180 days of unbroken play at game speed
1**. Measured against real income — a career averages about 1,080 character XP a
day, from 28 mines a day early and 48 late — that sizes the curve at:

```
xp_for_level(L) = round(40 + 2.1 * L^1.7)     // ~197,000 XP total, ~182 days
job_xp_for_level(L) = round(17 * L^1.5)       // ~32,000 XP, ~1,600 crafts to job 30
```

The `40` floor is there so the first level costs about three mines rather
than half of one.

**Open, and it is the one thing §7.3 knocked over.** That income was measured
when a mine clamped at 30 minutes. A geared prospector now works a hex in five
to ten, so the late-career mine rate is several times what the curve was sized
against and six months is no longer what it buys. The curve has deliberately
**not** been re-fitted: how fast a well-equipped character should level is a
pacing decision, not a consequence of removing a timer.

**XP is never passed through `Balance::scaled()`, and must never be.** Timers
compress with `GAME_TIME_SCALE`; XP does not. A mine pays the same XP at speed 1
and at speed 100 — which is what makes a fast clock a *testing* tool rather than
a progression cheat, and what keeps the six-month figure meaningful. There is a
test for this.

### 7.5 Explorer — the road job

**A seventeenth job, of a fifth kind (`wayfaring`), and it breaks four of §7.4's
rules on purpose.** It exists because §5.6 took the reach limit off the map: if
any hex is walkable, a long walk has to pay out *something*, or the map is just
a waiting room.

| | Explorer | Every other job |
|---|---|---|
| Tree | **15 nodes, 3/3/3/3/3** | 30 nodes, 6/8/8/6/2 |
| Cost | **No skill point — handed over at its job level** | 1 skill point each |
| Levels from | **Hexes crossed** | Bench work, or raiding |
| Pays in | **Capability only — never a stat** | Stats, and the work of the job |
| Gating | **One skill per level**, every 2nd level, 2 → 30 | A whole depth at once, at 1 / 5 / 12 / 20 / 28 |

**The shape is the same five depths as everything else**, and that is
deliberate: a seventeenth job that is also a fifth kind of diagram is one thing
too many to learn. Three to a row rather than 6/8/8/6/2, because fifteen free
nodes is what a free tree may be worth.

**What is exceptional sits under the layout: the row does not arrive whole.** A
bought depth opens all at once because its gate only says you may *start
spending points* there — the point is the real price, paid per node. Nothing is
bought here, so a row opening whole would be three rewards for one level. Each
skill carries its own `jobLevel` instead, one every second level, and a row
fills in across three of them.

| Depth | Levels | Left column — room | Middle column — straps | Right column — the road |
|---|---|---|---|---|
| I | **2 · 4 · 6** | Deep Pockets +10u | Second Strap **+4 rows** | Rolled Blanket +10u |
| II | 8 · 10 · 12 | Even Load +10u | Side Pouch **+4 rows** | High Ground **+1 sight** |
| III | 14 · 16 · 18 | Bindle +10u | Sorted Kit **+4 rows** | Tump Line +10u |
| IV | 20 · 22 · 24 | Packer's Knot +10u | Outer Pockets **+4 rows** | Long Haul +10u |
| V | 26 · 28 · **30** | Drover's Back +10u | Tinker's Roll **+4 rows** | Horizon Line **+1 sight** |

`Jobs::WAYFARING_TIER_JOB_LEVEL` holds the level each *row* opens at — 2, 8, 14,
20, 26 — which is what the panel prints in the gutter as a span (`lv 8–12`).
What a given skill needs is on the skill: read `NODES[$key]['jobLevel']`.

Totals: **120 → 200 units, 30 → 50 rows, 1 → 3 hexes of sight.** Eight nodes of
ten units, five of four rows, two of one hex.

**It is wired down its columns, not across.** A bought tree forks so that thirty
points have to be spent through choices; there is nothing to choose here,
because nothing is bought. Each node hangs off the one directly above it, which
makes the three columns readable as three strands — room, straps, and the mixed
one that carries both hexes of sight.

**Every node is the eye or the back, and not one of them is a stat.** This is
the rule that makes a granted tree safe to exist, and it is stricter than the
§8.1 clamp it replaced. Every other tree is paid for with a skill point, and the
point is what keeps the hundred-point cap (§7.4.1) meaningful; this one is free,
so the only currency left to it is **capability** — counts, each with its own
cap, none of them touching the stat ceiling. A percentage here would be a power
ladder you climb by leaving the app open on a long walk. There is a test
asserting a maxed Explorer moves no stat whatsoever.

*(The tree used to end on +25% travelSpeed against a +15% ceiling and lean on
the clamp to stay honest. Writing nothing is the better version of that
argument.)*

**The first skill waits for level 2, where every bought tree opens at 1.** A
bought tree can open immediately because the skill point is the price — the job
level only says you may spend it. A granted tree has no price at all, so opening
at 1 would hand a character who has walked nowhere something for existing. Two
Explorer levels is seventeen XP, four hexes: a short walk, but a walk, and a
walk is the only thing this job is ever allowed to charge.

**The steps are even in levels and steep in effort**, which is what the job XP
curve does for free: level 12 is about 800 hexes out, level 20 about 2,600, and
level 30 about 6,400. The last skill lands exactly on `JOB_MAX_LEVEL`.

**Walking earns Explorer XP and no character XP.** Both halves are load-bearing.
Without the first, a map with no reach limit is a long wait; without the second,
the cheapest XP in the game is pressing *travel* and going to bed — and §2's
whole argument is that idle time must not be a faucet.

**Its nodes cost no skill point, and that is only safe because there is exactly
one such tree.** A second free tree would not be a new job, it would be a hole
in the 100-point cap (§7.4.1).

**The road hands them over.** A wayfaring skill is claimed the moment the
walking pays for it, without being asked — because there is nothing for a press
to decide. It cannot be declined, it cannot be spent elsewhere, and there is no
wrong order to take them in, so the button's only answer was yes, and a button
whose only answer is yes is a chore.

**What the press was protecting is still protected, in the right place.** The
reason it existed was real: a reward arriving on its own is a panel that quietly
changed since you last looked at it, with no moment where it was given to you.
So the moment moved to where moments belong — the state already carries the
owned nodes, so a list that grew on its own is announced as it lands. Nothing
on the server has to remember what a client has been told.

*(It was a press for a while. The lesson worth keeping is that "free" and
"automatic" really are different, and the thing that makes automatic safe is not
the press — it is that something says so.)*

A wayfaring node is still **a row like every other node**, and the point ledger
stays honest by asking what KIND a row is rather than by keeping some rows out
of the table. That is the one place this differs from every other tree: the
`spent` count skips them.

**Sight is the rarest thing the road pays in**, and it is capped for the reason
in §7.4.3: it is a query radius, and cost goes as the square of it. It goes
1 → 3 and no further, ever. Both nodes sit at the foot of the right-hand column,
at levels 12 and 30 — the second is the last thing the tree gives. Starting at
one hex is what makes the first of them felt: it trebles what a prospector can
see rather than adding a fringe to a view that was already wide.

**The back grows every other level**, because that is what a long career of
walking should feel like. Ten units is most of a haul; four straps is four more kinds
you never have to choose between. A maxed Explorer carries two hundred units
across fifty straps — a different *game* from 120 across 30, and the only way to
get there is to have walked several thousand hexes.

### 7.6 The bag — two limits, and they refuse in two different ways

Everything a character owns and is not wearing is in one bag, and the bag has
**two limits that are counted separately**:

| | Limit | Constant |
|---|---|---|
| **Units** | **120.** Every unit of every material, every potion, and every unworn item. | `Balance::BAG_UNITS` |
| **Rows** | **30.** How many *distinct* things that is: a stack is one row whether it holds 1 or 100, and two axes are two rows because gear does not stack. Roomy against a catalog of 29 materials and 5 drafts, on purpose — see below. | `Balance::BAG_ROWS` |

Both are **flat, and level does not move them** (§7.1). The only thing that
widens either is the Explorer tree (§7.5), to **200 and 50** — fifteen nodes of
ten units or four rows, earned by walking and by nothing else.

**Units are the limit that bites; rows are the ceiling on carrying one of
everything.** A prospector who commits to a line or two will meet 120 units
several times a day and the straps almost never. That is the intended shape: the
daily decision is *what is this haul worth carrying home*, and the straps are
there so that "a little of everything, forever" is not an answer to it.

The two are enforced in two different places, and the difference is the design:

| | What happens at the limit | Where it is checked |
|---|---|---|
| **Units** | You **cannot travel.** Nothing is refused on the way in: a haul lands whole, and being too heavy stops the road rather than the work. | At the gate — `travelTo` |
| **Rows** | A kind you are **not already carrying is turned away.** Rows can therefore never go over; there is nowhere to put a thing that has no strap. | At the door — mining, processing, crafting, buying, unequipping |

**A row is a place, not a weight,** which is why it refuses rather than pins.
Units can be dealt with after the fact — sell, process, drop — but "put it
somewhere" has no after-the-fact answer, so the refusal has to come first.

Three rules follow from that, and all three are mandatory:

- **The refusal is said before the work, never after it.** A dig whose haul has
  nowhere to land is refused at the hex, with AP untouched; a craft is refused
  before its inputs are spent. An hour of mining that ends in a lost haul would
  be a worse rule than no rule.
- **More of what you already carry always fits.** Topping up a stack needs no
  new row, and that asymmetry is the whole point: the limit is on *variety*,
  which is what keeps §4's five lines a choice rather than a checklist.
- **Worn gear is not carried.** An equipped axe is on your belt, not in your
  pack — so equipping is itself a way to free a row, and a prospector who has
  committed to their five lines is not charged for the commitment. Taking
  something off is the one action that *adds* a row, so a full bag leaves it on
  the belt.

**Everything else keeps working while you are over on units.** Mining the hex
you are standing on, selling, processing, drinking, dropping. Every one of those
is a way out, and every one of them works from where you already are.

**Two numbers rather than one, because one is a bucket.** Units alone would let
a prospector carry a little of everything for nothing, and the whole of §4 is
built on not being able to work every line at once. Rows are the tighter limit
in practice, and they are the one that makes a second material line a decision
rather than an accumulation.

**It replaced storage-overflow decay** (old §11.1). Decay punished the same
state twice — you lost the surplus *and* it happened while you were not looking,
which is the worst way for an idle game to take something away. A bag that stops
the road takes nothing at all; it makes you choose what to do with the surplus,
and every one of those choices is a §11 sink.

**On screen** (§13.2's rules, off the map): rows are **drawn as a comb of
hexagons**, never measured on a bar — an empty strap is the same shape as a full
one, so free space is something you see rather than subtract. Units are a
**bar**, because a quantity is not a set of places. Tapping a strap opens what
is on it in a popup, with the one or two things that can be done with it, rather
than growing a detail panel that would push the comb off its own screen.

The bag cell in the top-right turns ember when either limit is reached, and that
is the only place the state is reported outside the bag itself. It is
deliberately **not** in the instrument cluster: that needle measures how far
along you are — the level that gates where you may go — and the bag is about
what you are *holding*.

---

## 8. Equipment

Equipment has **two axes that are not the same thing**, and conflating them is the
easiest way to break the economy:

- **Rarity** — six rungs, sets the power ceiling, the color, and where the thing
  can be made.
- **Mintable** — whether it may be converted into an NFT and withdrawn from the
  game (§3.3). Not implied by rarity, and not a property the item has while it
  is in a bag: everything in a bag is in play, and everything in play can be
  destroyed (§8.2).

### 8.0 The rarity ladder

| Rarity | Color | Stat ceiling | Option rolls | Bench | Shop | Mintable |
|---|---|---|---|---|---|---|
| Common | gray | +3% | 0 | village | village+ | no |
| Uncommon | green | +5% | 0–1 | city | city+ | no |
| Rare | blue | +8% | 0–1 | capital | never | no |
| Epic | violet | +11% | 0–2 | capital | never | **yes** |
| Legendary | gold | +14% | 0–3 | guild hall | never | **yes** |
| Unique | ember | +15% | 0–3 + fixed perk | never | never | no — soulbound |

**The option column is a ceiling, never a quota**, and only a **crafted** piece
rolls against it at all (§8.0.1). Anything off a shelf is plain.

**Mintable** is the right word and *tradeable* was the wrong one: the column
says this rung may be **converted into an NFT and withdrawn** (§3.3), not that
it arrives as one. Everything in a bag is in the game and can be destroyed.

**+15% is the hard ceiling for the whole game.** Rarity climbs toward it; nothing
— no future rarity, no rolled option, no buff — may pass it. Read
`Balance::STAT_CEILING`, never the top rung.

**A bench reaches exactly as far as its tier**, whatever materials you carry to
it: village → common, city → uncommon, capital → rare and epic, guild hall →
legendary. That is most of what makes a capital worth the walk.

Gold buys **common and uncommon only**, at any settlement tier. A capital's shop
edge over a city is *options*, not rarity (§8.0.1).

**Unique is soulbound, and that is not negotiable.** It is the strongest thing in
the game and it drops from dungeons — §2 forbids a grind→NFT faucet, so a
tradeable drop would be exactly the hole the threat model exists to close.
Minting stops at legendary. Unique is prestige, not liquidity.

### 8.0.1 Options — rolled bonus lines

An option is a **bonus**, not part of the item: something a bench sometimes puts
on a thing it made. Two of the same recipe are therefore never the same object,
and one of them carrying nothing at all is a normal outcome rather than a
broken one.

**Gold buys a plain item, at every shelf including a capital's.** Nothing bought
has ever rolled and nothing bought ever will — that is the whole difference
between a piece somebody made and a piece somebody stocked, and a shop selling
rolled goods would make crafting the slower way to buy one.

*(The capital bazaar used to stock pre-rolled stock, which was the one way a
common item could carry a line. Taking it away is what gives the benches
something a shelf cannot have.)*

**Three things are random, and all three are the point:**

| | |
|---|---|
| **How many** | nothing up to the rung's ceiling in §8.0's table — so an uncommon may roll one or none, and a legendary anywhere from none to three |
| **Which tier** | each line is drawn from any tier **at or below** the item's own rarity, so a legendary regularly carries a common-grade line |
| **What it is worth** | inside that tier's band |

| Option tier | Percentage | Scoped | **Solid** |
|---|---|---|---|
| common | +1–2% | +2–4% | +1–2 |
| uncommon | +1–3% | +2–6% | +1–3 |
| rare | +2–4% | +4–8% | +2–4 |
| epic | +3–5% | +6–10% | +3–6 |
| legendary | +4–6% | +8–12% | +4–8 |

**A line may be a percentage or a solid number, and both are real options.**
`attack` and `defense` are not percentages (§9.5.4) — a fight is an exchange and
a ±15% swing cannot decide one — so a rolled line that lands on them is a solid
number that simply **adds**. It never enters the percentage aggregate, never
meets §8.1's ceiling, and never carries a scope: a flat pair has no gathering
line to belong to.

The line carries a `kind` for exactly that reason. Without it *+2 defense* and
*+2% defense* would be the same row saying two different things, since the flat
pair and two of the `StatKey`s share their names.

**On a gathering tool a solid `attack` is MINING attack** (§7.3): it bites
deeper into a hex and is worth nothing at all in a fight, exactly as the tool's
own attack is. A tool never rolls `defense` — there is nothing for it to mean.

**A higher rarity does not roll a better line every time; it rolls from a deeper
bag.** That is a different and more interesting thing: the ceiling climbs the
ladder and the floor does not, so a good roll is *found* rather than issued, and
an unlucky legendary really can come out under a lucky rare.

- Rolled **server-side** from a seed, like every other outcome.
- **Options are inside the ceiling, not on top of it.** They feed the same
  aggregate and clamp as the base stat. An option that breached the cap would
  reintroduce pay-to-win through the back door — which is also why the bands
  above may look generous and are not: `STAT_CEILING` is still +15%, and every
  line on every piece is climbing toward that one number.

**A worn line may name one gathering line, and is worth more for it.** Armor,
boots and gloves work all five lines at once, so a flat *+2% yield* on them is
five bonuses in a coat. A roll may instead come out **scoped** — *+4% mining
yield*, *+3% off woodcutting time* — worth **twice** as much on the line it names
and **nothing on the other four**. Same ceiling, same clamp, same aggregate:
what a narrower line buys is a steeper climb to +15%, never a higher one.

The gap between the two is the whole rule. Without it a scoped roll would
be strictly the worse outcome and the pool would read as a bad-luck table; with
it, *+2% everywhere* against *+4% mining* is a real choice for a prospector who
knows which line they actually work. It is the same argument §8.5 makes for
scoping potions — seventy flat drafts would be a power ladder you can drink.

**Only the two mine stats scope**, `yield` and `tripReduction`: `travelSpeed`
has no line to belong to, and processing is scoped by the recipe already.

**Tools never carry a scope.** An axe is line-locked by its slot (§8 rule 1), so
its yield is already woodcutting's yield — storing that twice would only be
somewhere for the two to disagree. The screen still says *woodcutting yield* on
an axe; it reads the line back off the slot.

**A rolled line is drawn from what the piece is FOR.** One rule, three answers,
because §8 gives equipment three jobs — and the pool is now the most legible
difference between them:

| Piece | May roll |
|---|---|
| **Gathering tool** | `yield`, `tripReduction`, and a solid `attack` that is §7.3's mining attack |
| **Weapon** | `power`, `defense`, and the solid pair. Nothing else |
| **Worn** | every stat there is, scoped or not, plus the solid pair |

The weapon slot used to fall through to the worn pool, and that is how a sword
came off the bench carrying *+4% hunting yield* — a work bonus on the one slot
in the game that never works (§8 rule 5). The other half of the same mistake was
quieter and just as wrong: a battle cuirass whose whole stat is `defense` could
never roll one.

**Worn gear is the one pool that reaches everything**, and that is §9.5.4 rather
than laziness: armor is *one set with two axes, not a second wardrobe*. A coat
is on your back down a mine, on the road, at a bench and in a fight, so there is
no stat it has no business being good at.

**A focus rolls no guard, of either kind.** §9.5.4 says a focus has none at all
— *a focus that also held a little of it would be the balanced one twice, and
the glass cannon is the point.* A rolled line is luck rather than budget, but a
wand that can come out of the bench guarding says the same wrong thing about
what a wand is. It is the only per-*family* rule in the pool.

**A consumable has no pool.** No slot, nothing to sit on, and §8.5 already
gives a potion its one effect. What made this worth writing down is that the
pool used to be chosen by *not* being a tool, so anything without a slot got
the coat's.

**And the almanac says all of it, before anybody owns one.** What a piece *may*
roll is a fact about the recipe rather than about the copy in a bag, which makes
it the one screen that can tell you — the ceiling, the band, and the pool, on
the same rail that says where the thing comes from.

### 8.0 Slots — a gathering tool per line, and combat kept separate

Nine slots. Five are **gathering tools, one per skill line**; the rest are worn.

| Slot | Line | Biome | Worked material |
|---|---|---|---|
| Axe | Woodcutting | Forest | Wood / Ironwood |
| Pickaxe | Mining | Mountain | Iron ore / Mythril |
| Bow | Hunting | Plains, Tundra | Pelt / Beastfang Hide |
| Hammer | Quarrying | Badlands | Stone / Obsidian |
| Sickle | Harvesting | Grassland | Fiber / Silkweave |

Worn: **Armor · Boots · Gloves · Weapon.**

Rules, all mandatory:

1. **A tool pays out on its own line and on no other.** An axe does nothing to a seam;
   a bow is for animals, not trees. Working a line you own no tool for is allowed — it
   simply pays the un-geared rate.
2. **Only the tool that did the work loses durability.** The others idle. Each line's
   sink therefore scales with how much that line is actually played, not with how many
   tools are equipped.
3. **All five slots may be equipped at once.** This is an idle game; forcing a swap
   before every mine is friction, not a decision. The interesting decision is which
   lines you *invest* in — that is already capped by §7.2 skill points.
4. **Every line gets the same ladder** — village basic, city basic, crafted starter,
   crafted, NFT — and the same ceiling. Specialisation must come from the skill point
   cap, never from one line having better tools on offer than another.
5. **`Weapon` is combat only and never gathers.** Combat gear must not be able to
   stand in for a gathering tool, or fighting becomes a shortcut around the mining ladder.
   One slot holds **three families** — shield, sword and focus — and the family decides
   which battle job levels (§9.5.4). It carries flat `attack` and `defense`, which are
   not the percentage stats of the same name and are not subject to their ceiling.

### 8.1 Anti-imbalance rules (all mandatory)
1. **One global % ceiling per stat**, +15%, and rarity climbs toward it. Nothing may
   pass it: not a rarity, not an option roll, not a buff, not a future tier. The
   ceiling is the load-bearing rule — rarity is only how far up you have climbed.
2. **Diminishing returns on stacking** — a 2nd yield item gives less than the 1st. Blocks
   buying 3 identical bundles for linear scaling.
3. **Durability decays with use** (fighting drains fastest, then raiding, then mining),
   and at zero the thing is **gone** (§8.2). Equipment is never "buy once, dominate
   forever" — it is rented from the repair bill.
4. The gap from common to unique is **12 points, not an order of magnitude**, and every
   rarity below unique is reachable by crafting without spending. That is what keeps F2P
   viable — and F2P viability is what sustains the active playerbase that drives NFT
   demand. If a change widens that gap, it is wrong.

### 8.2 Repair, or lose it

**At 0 durability the item is destroyed.** Not broken, not inactive — gone from
the bag, named in the result that killed it.

*(It used to go inactive and wait for a repair. That made repair optional: an
item at zero cost nothing to leave at zero, so the sink only ever collected from
players who wanted their gear back. Destruction moves the whole bill forward —
you repair to keep the thing, not to un-break it — and it is the largest sink in
the game, §11.1.)*

- Repair costs refined + raid materials, scaled to rarity tier, and is only ever
  possible **above** zero. There is no resurrection.
- **A piece carries its own ceiling, not its recipe's.** §7.4.3's
  `craftDurability` raises the max of what a Smith makes, so two copies of one
  recipe can differ — and everything that measures wear, prices a resale or
  offers a mend reads the ceiling off the *object*.

  It used to write the bonus into the current fill and leave the ceiling at the
  recipe's, which made the node worth exactly one craft: the bar read past 100%,
  resale clamped the fraction back to 1, and the first repair filled the piece to
  the catalog max and threw the extra away for good. A Smith deep enough in the
  tree to buy it got a piece that was better right up until it was mended once.

  It now pays out **twice, permanently**. The piece holds more, and a full mend
  costs one recipe's worth of materials however many points that turns out to be
  — so a well-made piece is cheaper to keep per point as well as longer-lived.
  What it does *not* change is what the trader gives for it: the parts are the
  same parts (§8.2 above), and craftsmanship is labour like the bench time.
- Discard returns a **small % salvage** — clears inventory bloat, gives obsolete gear an exit
- **Sell it back for gold** — half the shelf price, multiplied by the fraction of
  durability left. A piece has three exits and they are deliberately different:
  repair *keeps* it, salvage returns a fraction of what went *into* it, and a
  sale returns gold scaled by what is *left* of it.

  **A potion sells too** (§8.5) — by the flask rather than by the object, since
  it stacks and has no durability to price it by. Consumables were the one thing
  in the bag whose only exit was the mouth.

  **What it is worth is what it is MADE OF wherever that is knowable, and the
  shelf price only where it is not.** A crafted piece is not a piece with no
  price — it is a piece nobody stocks, and those are different things. The catalog proved it by accident: a Notched Sword is
  common, craftable *and* stocked, so it sold, while a Tempered Sword is common
  and craftable and unstocked, so it did not, and nothing a player can see tells
  those two apart.

  Four refusals, each closing a hole rather than being a nicety. Not away from a
  settlement — the trader is an NPC who stands somewhere (§6). Not off your own
  belt: a sale is a trade, and losing the worn tool to a mistap is worse than
  losing one out of the pack, so stow it first. Not above the second rung — gold
  buys the bottom two and never the top (§3.2), so an epic or better has no
  price at all whether it was bought or made: minting is that rung's exit (§8.0)
  and salvage is the one open everywhere. And not for nothing: a piece worn past
  the point where half its price still rounds to a coin is refused rather than
  taken for zero.

  *(It used to read "a crafted or NFT piece has no shelf price to halve", which
  put a common Hewn Axe in the same sentence as an epic Mythril Pickaxe. The
  reason it gave — gold buys the bottom two rungs and never the top — only ever
  argued for excluding the top.)*

  **Two numbers hold this in place and neither is optional.** The round trip must
  lose money, or a trader is a gold faucet with no work in it. And repairing must
  stay cheaper than selling-and-rebuying at *every* wear level, or the largest
  sink in the game (§11.1) quietly switches itself off.

  **And a third: the trader never pays for the markup or the bench time.** A
  shelf price is make-cost plus half again plus the minutes (§8.3), so it is
  *always* above what the thing is made of — which meant half of it could still
  clear the parts, and for six uncommon battle pieces it did: 41g of materials
  made an Iron Broadsword that sold for 53g. Gather, craft, sell was a slow gold
  press with no cap on it, which is exactly the shape §2 cares about.

  So resale reads the **parts** wherever a thing has a recipe, and the shelf tag
  only where it has none. One rule, and it prices a stocked-and-craftable piece
  the same as the craft-only piece beside it — the two used to differ by nothing
  a player could see. A test sweeps all 49 craftable pieces; none turns a
  profit. You did not pay the markup, and the trader is not buying your
  afternoon. Both are pinned by tests,
  because the resale rate and the NPC's repair rate are set independently and
  nothing else would keep them in the right order.
- **Nothing is destroyed without warning.** Durability is on the item, and any
  action that could take the last of it says so first — the fight preview
  (§9.5.5) and the hex preview alike. An idle game may take something expensive
  from a player; it may never take it by surprise.
- **It reaches the mine, not only the fight.** Mining and hunting wear the
  line's tool, and at zero that tool is gone the same way a weapon is. The
  warning is line-locked exactly as the wear is: the axe on your back is not at
  risk while you are down a mine.
- Nothing minted can be destroyed, because minting takes it out of the game
  (§3.3). Destruction reaches every rung that is actually *in* a bag.
- **A stowed piece mends, and so does a broken one.** Repair asks what a piece
  is missing and never where it is being carried: an axe in the pack is the same
  axe. It was only ever offered on the prospector sheet, which lists what you
  are *wearing*, so a spare tool had to be put on before it could be fixed —
  a rule nobody wrote, enforced by a missing button.
- **The bill is said before the button, everywhere the button is.** What a mend
  takes is the decision (§11.1 makes it the largest continuous sink in the
  game), not a footnote to it, so the parts are listed under the wear bar with
  anything you are short of in ember (§13.3). Basic gear says the trader mends
  it for coin instead, which is a different bill and one only payable at a
  settlement.
- **The four verbs are glyphs, not words.** Equip, stow, repair and scrap are
  the same four things wherever a piece is met, and four words typed out again
  on every row spent more of it on the buttons than on the item. Equip and stow
  are **one gesture reversed**, so they are one drawing reversed — the game's
  own hexagon (§13) with the chevron pointing in or out. Repair is an **anvil**
  and deliberately not the hammer: `craft` is already the hammer, and mending a
  thing is not making one. Scrap is a **bin**, and it is the one glyph in the
  game that is a plain interface symbol rather than something off the subject's
  own bench — it earns that because it is the only one of the four that does not
  give the piece back, and nothing drawn from a forge says *gone* as fast. A
  plate with room for words still says them beside the glyph.
- **The prospector sheet is a condition read-out, not an inventory.** Nine
  slots — the five lines and the four worn — drawn as nine hexagons on one
  baseline, each with a gauge running down its **right face**. Names are gone:
  §13.1 already puts the slot in the silhouette and the rung in the colour, so a
  name spent a whole row saying what the icon had said, and the question the
  screen is actually opened for — *which piece is about to break* — was answered
  nowhere. Nine gauges side by side answer it before a word is read.

  **The gauge is the hexagon's own edge, not a bar beside it.** §13 allows two
  shapes and no third; a straight rail next to a hexagon is a third one, and it
  sits in the gap reading as a divider between two cells rather than as a
  reading off one. The chevron runs parallel to the right face at the same
  slope the clip cuts.

  **The scale is fixed and the fill is not**: sap at the top, gold in the
  middle, ember at the foot (§13.3), lit from the foot up. Pinning the colours
  to positions is what makes nine gauges comparable, and it means the height and
  the colour of the tip say the same thing twice — a piece in trouble is short
  *and* red. **The unlit track carries half of that reading** and must have real
  contrast against the panel: what separates a piece at 84% from one at 73% is
  nine pixels of lit length, which nothing can see, or the same nine pixels said
  as an absence above it, which anyone can.

  **The icon fills its cell, and the icon's own frame IS the cell.** §13.1
  gives every item a hex frame, so a smaller one drew a hexagon inside a
  hexagon — two rings saying one thing, with the rarity colour on the inner and
  quieter of the two. Only a bare slot keeps the dark clipped box, because it
  has no icon to be the shape.

  **Every measurement in a cell is taken off the drawn hexagon, never off a
  box.** That is the rule the whole thing kept breaking. §13.1's icon draws a
  *regular* hexagon inscribed in a square viewBox — 0.93 of the width across
  the points, 0.866 of that again down the flats — and a box is none of those
  numbers. Laying a clip on the box, centring the art in the box and hanging
  the gauge off the box gave three shapes that agreed nowhere. The hexagon's
  **width** is the one dimension everything else derives from.

  **The gauge starts and stops where the hexagon does**, with a one-pixel
  hairline between them. Geometry alone does not give you that: `butt` caps cut
  each end square across a path that meets the corner at 60°, so a chevron
  drawn corner-to-corner paints two pixels short at the head and one at the
  foot, while the hexagon's own stroke overhangs a little past both. The path
  runs *past* the corners and the ends fall where they may.

  **One ground, drawn once, under every slot.** The dark hexagon is a polygon
  in the gauge's own SVG rather than a CSS clip on the art — exact geometry,
  behind the icon instead of cutting it, and one definition of the shape rather
  than two. It used to be a clip that only a *bare* cell wore, which put the
  heaviest mark in the rack on the slots holding nothing: an empty hand reading
  louder than a full one.

  **Tapping one opens what it is**: the name, the rung, the rolled lines
  (§8.0.1), the exact figure, what a mend takes, and Repair · Stow. That is
  §7.6's grammar indoors — a thing you tap opens what it is and the one or two
  things that can be done with it.

  **The plate draws the same frame the rack does**, at the plate's size, and so
  does every spare filed behind the slot. The rule above is about geometry
  rather than about one screen: a square box wearing the hex clip is a hexagon
  stretched 15% tall, and an icon set to some smaller pixel size rattles around
  inside it. Both were true here while the rack beside it was right, which made
  the frame around a piece mean two different shapes two taps apart.
- **The stowed list is gone, and is not lost.** An unworn axe is filed **behind
  the axe slot**, which is where somebody looking for it would look, with the
  Equip button on the row it belongs to. A flat list of everything unworn was a
  second place to keep gear, ordered by nothing, and it is the reason the sheet
  could not fit a screen. A broken spare offers Repair where Equip would only
  have refused — a plate whose job is to offer what can be done must never
  offer the thing that does nothing.

  **And the row says what the SWAP moves, not what the spare is worth.** A
  spare is never a question on its own — §8 puts one item in a slot, so the
  piece on the belt is the other half of every answer — and two rows of
  absolute numbers is that question handed back as arithmetic. The pair
  subtracts, because §9.5.4's numbers are solid; a percentage is **projected
  through the whole kit both ways** instead, so §8.1's falloff and ceiling are
  in the figure. That is the difference between *+8% travel speed* on a label
  and *+5%* in the reading, and only the second one is true.

  It is the same arithmetic and the same drawing the bag uses when a piece is
  tapped there, because it is the same question asked from two rooms. Sap for
  what the swap wins and ember for what it costs (§13.3) — a stat is neither
  and is drawn plain, but a *change* is exactly a thing to weigh.
- **Tuning decision still open:** repair cost must be cheaper than crafting new, but not
  dramatically so, or the crafting-materials sink stalls at endgame.

### 8.3 Example recipes (starting values)

`travelSpeed` **divides the travel clock** — +8% boots really are 8% faster over
any distance. It used to buy hexes of reach, and §5.6 removed reach, so the stat
now does the thing it is named after.

Worn gear:
```
Leather Armor     = 6 Leather + 2 Cloth                        → +6% mine time
Reinforced Boots  = 4 Cut Stone + 3 Leather                    → +8% travel speed
Work Gloves       = 3 Cloth + 2 Planks                         → +4% processing speed
Ironwood Armor    = 3 Ironwood + 2 Silkweave + 1 Shard         → +12% mine time [NFT]
Beastfang Boots   = 2 Beastfang + 1 Obsidian + 1 Relic         → +15% travel  [NFT]
```

Gathering tools, §8.0 — one ladder, repeated per line. **Attack only**, and
worked on that line alone (§7.3). The crafted starter is single-line on purpose:
it is what a player can build straight off the opening arc's first processing
run (§12 step 6).

**The shop shelf is priced by one rule, and the rule is two valuations of the
same object.** The price is the higher of them:

| | What it means |
|---|---|
| **Cost to make** | its parts at the NPC's own poor rate, marked up by half, **plus the bench time it takes** (§8.4's craft clock, at a gold a minute) |
| **What it is worth** | gold per point of durability, set per station — village ~0.43, city ~1.40 |

Neither alone is enough, and both failures are ones this catalog actually had.
*Worth alone* priced the village combat rung at 22g against 26–35g of materials,
so the shop undercut its own recipe and crafting one was a straight loss — a
shelf that beats the bench inverts §8's whole ladder. *Make-cost alone* would
price a 40-durability axe and a 60-durability cloak the same, because neither of
them has a recipe at all.

Bench time is the smallest term and is meant to be: it is not there to set the
number, it is there to be the difference between two pieces made of the same
parts at different benches, which material cost alone cannot express.

Hand-picked numbers drift the moment the catalog grows, and these had already
drifted twice — first the gathering tools sat half again under everything added
after them (§8.0 rule 4: every line gets the same ladder, and a price gap *is* a
different ladder), then the combat rung fell under its own materials. Derived in
`gen_battlegear.py` and pinned by a test that recomputes every shelf price from
`Balance`, so a changed recipe reprices the item and a drifted one fails.

| Line | Village atk 3 | Starter atk 4 | City atk 6 | Crafted atk 8 | NFT atk 14 |
|---|---|---|---|---|---|
| Woodcutting | Stone Axe | Hewn Axe | Iron Hatchet | Ironbound Axe | Ironwood Axe |
| Mining | Chipped Pick | Wood Pickaxe | Miner's Pick | Iron Pickaxe | Mythril Pickaxe |
| Hunting | Crude Bow | Shortbow | Recurve Bow | Sinew Longbow | Beastfang Bow |
| Quarrying | Stone Mallet | Stone Maul | Iron Sledge | Banded Sledge | Obsidian Sledge |
| Harvesting | Bent Sickle | Reed Sickle | Steel Sickle | Toothed Sickle | Silkweave Sickle |

Rare is **10**, legendary **17**, unique **19**. The crafted uncommon finally
sits above the shop uncommon rather than tying with it — the old percentages had
both at +5%, which made the bench rung pointless.

```
Hewn Axe          = 4 Planks
Iron Pickaxe      = 5 Ingots + 3 Planks
Sinew Longbow     = 4 Leather + 3 Cloth
Banded Sledge     = 4 Ingots + 4 Cut Stone
Toothed Sickle    = 4 Ingots + 3 Cloth
Mythril Pickaxe   = 3 Mythril + 2 Reinforced Frame + 1 Essence            [NFT]
Beastfang Bow     = 3 Beastfang + 2 Silkweave + 1 Sanguine Shard          [NFT]
```

Every NFT tool wants its line's rare material **and** its line's dungeon shard, so
kitting out a second line is a cross-map project, not an errand — the same
pressure §4 puts on Shards.

### 8.4 Three craft benches

Crafting is split into **weapon**, **armor** and **consumable**. The category is
derived from the slot, never stored: a thing's category is already implied by
where it is worn, and a second field would only be somewhere for the two to
disagree.

| Category | Slots | Notes |
|---|---|---|
| Weapon | axe · pickaxe · bow · hammer · sickle · weapon | The five gathering tools plus the combat slot, §9.5.4 |
| Armor | armor · boots · gloves | Worn gear, never line-locked |
| Consumable | *none* | Having no slot is exactly what makes it the third category |

**The bench queue is drawn by the same component the processing queue is.**
Two banks of shared slots under one rule (§6.1) were drawn twice and drifted
into two visual ideas; they are one drawing now, so a slot means the same thing
at the saw pit and at the anvil.

**And the panel says nothing a prospector cannot act on.** What the header
carries is the rungs this bench *makes* and the smallest settlement that makes
the next one. Guild halls are named only to somebody in a guild (§10.5), and
unique is never named at all — it is never crafted (§8.0), so a rung for it on a
bench screen is a row of noise about somebody else's business.

**On screen it is four lists, not three, and that is not a fourth bench.** A
tool and a sword come off the same anvil and teach the same Smith, but they
answer two questions that have nothing to do with each other — *which of my five
lines do I upgrade* and *what do I carry into a fight* — and one merged list
buried the axe rungs among the shield rungs. So the panel filters Tools ·
Weapons · Armor · Drafts, and inside each the axis is the thing actually being
chosen between: the **line** for a tool (§8 rule 4 promises all five the same
ladder), the **family** for a weapon (§9.5.4), the **slot** for worn gear, and
the **action** a draft arms (§8.5). Rarity is never the axis; it is a rung drawn
down the edge of each card, and how far the bench itself reaches (§8.0) is the
one row of six caps at the top of the panel.

**A bench takes time, and it hands over where it was left.** Crafting used to be
instant, which made a capital a vending machine: carry the materials in, walk
out with the item. Two rules turn it back into a place.

| Rung | On the bench |
|---|---|
| Common | 8 min |
| Uncommon | 14 min |
| Rare | 22 min |
| Epic | 34 min |
| Legendary | 50 min |

The clock is the same one §6 runs processing on — the settlement's tier, the
presence bonus (§6.2), and whatever the gloves are worth — because they are the
same building. The cheapest craft is longer than the longest processing run, on
purpose: a run is a step, a craft is the thing itself.

**Everything that can refuse does so before a single material is spent**: the
bench's reach (§8.0), the strap the output will need (§7.6), and the stock. What
happens afterwards is only the clock.

**One craft per settlement, and five benches to stand at.** The bank queues
exactly the way §6.1's processing line does: five slots, first-come-first-served,
shared by everybody at that settlement, so a busy capital is busy at the anvil as
well as at the saw pit. What stays personal is the one bench each — you cannot
stack your own work five deep, and the real limit on how much you have going at
once is still the walking, up to §6.3's ten.

**The two banks are counted apart**, because they are two buildings. A run of
planks must never close the forge, and while both were counted off one number it
did exactly that.

**The hand-over plate is about the OBJECT**, because §8.0.1 makes a craft a
reveal: two of one recipe are never the same thing, and this is the one moment
a player finds out which one they got. So it draws the piece — its silhouette,
its rung, what it is worth in the same chips the trader and the bag use — and
lands last on **what the bench rolled onto it**, with *no lines* said plainly
where nothing did, because nothing is a normal outcome rather than a fault.

*(It used to be a name and a durability figure over a row of XP. The server had
been sending the rolled lines the whole time and nothing read them, so the most
interesting half of an hour at the anvil arrived unmentioned.)*

A potion has neither a rolled line nor a durability (§7.4.3), so its plate is
the flask, the rung and the count — and never a stat block reporting that it
has no stats.

#### The slate — ten things you mean to make

A **bookmark on a recipe**, and the only control in the game that changes
nothing about the world. Ten at most (`Balance::SLATE_CAP`), on any recipe a
bench takes: an item the craft benches make (§8.4) or a run one of the five
processing lines takes (§6).

**What it is for is the walk.** A recipe you cannot afford yet names materials
you have to go and get, and the bench that would tell you which ones is four
days behind you. Everything else on the map is answered where you are standing;
this is the one question whose answer has to travel with you.

**Ten, and the eleventh is refused rather than pushing one off.** The same ten
as §6.3's cap and for the same reason rather than by coincidence: both count
things a prospector keeps in mind across a map they have to walk. A list that
quietly forgets is worse than one that says it is full — §7.6 makes exactly
that argument about a bag, where the refusal *is* the decision.

**Nothing is reserved.** The slate holds no materials, blocks no bench and
grants nothing. It is a note to yourself, which is why the mark sits *beside*
Craft and Queue rather than looking like one of them, and why it is drawn in
copper — §13.3 spends copper on work in progress, and an intention is exactly
that. Sap would read as a payout and ember as a problem.

**One column, and the kind is derived.** A recipe key and an item key never
collide, and the catalog already knows which is which — so storing a `kind`
beside the key would be the same mistake §8.4 avoids by deriving a bench
category from the slot. There is a test pinning the two key spaces disjoint,
because the whole scheme rests on it.

**A shop-only piece cannot go on it.** It has no recipe, so there is nothing to
gather for it and nothing for the list to say; the trader is the whole of its
story.

**It is read on the Benches ledger**, which is the page that already plans a
route — what is on a bench, and what you meant to put on one. Two halves of one
question asked a step apart: that half plans a walk and this half plans a
gather. It is also the one screen reachable from every hex, which is the only
place a shopping list is worth having.

**Two tabs rather than two stacked sections.** They were stacked, which put the
slate below however many jobs happened to be out — so the half you read while
standing in a field was the half you had to scroll a bench ledger to reach. Two,
and no third: there is no state a bench job is in that the row cannot say
itself.

**Every material on it is drawn as well as named.** A shopping list read in a
field is scanned rather than read, and the glyph is what a player recognises in
the bag they are comparing it against — spelling the names alone made it the one
list in the game you had to parse.

**What you are short of is worked out where it is drawn, never stored.** The
bag moves with every haul, so a written-down answer would be stale before it
was read. The line reads sap when the bag already covers it and names the
shortfall in ember when it does not — the same two colours the rest of the
ledger uses, pointed the same way.

#### The claim happens at the bench

**Anything left in a settlement is collected in that settlement**, a craft and a
processing run alike. Claiming from the other side of the map would make the
building a mailbox: carry the materials in, walk off, collect wherever you
happen to be. The walk back is what makes *which* capital you use a decision.

Two consequences follow, and both are the point:

- **A haul you cannot reach is not a haul.** "Ready" at a village four days away
  is a route to plan, which is why the ledger names the bench and the distance
  rather than only the clock.
- **The strap is asked for twice** — before the work and again when the thing is
  handed over (§7.6). An hour is long enough to fill a bag, and the answer is a
  refusal rather than a lost item: it stays on the bench until there is room.

Nothing here touches §6.1's five public slots. Those are the processing lines;
the benches have five of their own, counted separately and refused separately.

### 8.5 Consumables — potions and buffs

- **Stackable, never equipped.** They live in their own table, not with
  equipment: a potion has no durability and no slot, so a row per object would
  be wrong.
- Using one spends it and **arms one action** with a **charge** on one stat. A
  potion is not a flat stat increase; it is bought for a specific thing you do.
  The actions are the five §7.2 gathering lines plus `travel`, `processing` and
  `battle` (§9.5.8) — the last of them the only place `power` and `defense` are
  worth drinking for.
- **A charge waits, and taking the action spends it** — the first woodcutting
  mine after the draft, whenever that is. It does not run on a clock.

  *(It used to. A 30-minute window meant a woodcutting draft drunk in the
  mountains was simply thrown away, which made scoping a trap rather than a
  choice: the potion you had bought for one line could only be drunk while
  already standing on it. Waiting is what turns the scope back into a decision.)*
- **Being spent is the sink** (§11.1). Nothing here may ever be permanent — a
  permanent effect only accumulates, which the north star forbids. A charge is
  not permanent: it survives until it pays out exactly once.
- **The trader takes a flask back**, at half what its reagents fetched at the
  NPC's own poor rate. A brew has no shelf price to halve — nothing stocks
  consumables, because this section makes them a thing you *make* — so §8.3's
  other half prices it, and there is no wear term because a potion has nothing
  to have spent.

  **Half is the guard, not a tuning value.** Selling a brew must always come to
  less than selling the reagents that went into it, or the consumable bench is a
  gold press: brew, sell, repeat, best run by whoever has the most wallets (§2).
  It holds even at the Alchemist's `brewExtra` cap — +35% flasks still reaches
  only 0.675 of what the inputs fetched. There is a test sweeping all seventy.

  **Only the bottom two rungs**, like every other sale (§3.2). That matters more
  here than for gear: every epic and legendary draft wants a Tier 3 rare, and
  those are capped per wallet — a gold price on one would turn a capped rare into
  uncapped coin, which is the bridge §2 exists to keep shut.
- **As many different effects at once as you like; the same effect never twice.**
  A woodcutting draft and a mining draft are *different things you are better
  at*, so both may be held, and so may a road tonic on top. Two drafts on the
  same stat and the same action are one thing twice.
- **When they are the same thing twice, the stronger wins.** Charges on a stat
  contribute their **highest** value, never their sum — so no combination of
  potions is a way of buying the ceiling in installments, and a `global` charge
  cannot quietly double up with a line-scoped one on the same mine.
- **A weaker draft is refused before the flask is opened.** Pouring a common
  draft on top of a legendary philtre would be paid for and never felt, and an
  idle game must not take something away for nothing. The refusal reads as *you
  already have better*, and the flask stays in the bag.
- **How many of one draft may be carried is the Alchemist's** (`stackCap`,
  §7.4.3), on top of the flat stack. It widens the cellar and never the effect:
  the clamp is on what an action's charges add up to, and that is untouched.
- One charge per (stat, action) is still enforced by a unique index rather than
  by code, and that index is also the cap on hoarding: a cellar of seventy
  drafts is still four stats across eight actions once drunk. The ceiling on
  any one action is exactly what it was, because the clamp applies to that
  action's aggregate alone.
- **Seventy of them, fourteen a rung**, across the five rungs a bench can
  reach: yield and mine time on each of the five lines, plus the road, the
  bench, and the fight — `power` and `defense` scoped to `battle` (§9.5.8).
  Scoping is what makes that many potions safe; seventy flat stat boosts would
  be a power ladder you can drink.
- **Recipes get shorter as they climb.** The number of *different* materials a
  potion wants never rises with rarity: a common draft is a muddle of four
  cheap things, a legendary philtre is two perfect ones. Every one wants at
  least two, so nothing is a one-ingredient shortcut.
- **Epic and legendary are mintable (§8.0), and §2 gates them with a cap, not a
  label.** Both rungs require a Tier 3 rare, and every Tier 3 is capped per
  wallet — the same gate every NFT tool stands behind. Legendary needs a guild
  hall, so like legendary equipment it is defined and reachable from nowhere.
- Buffs feed the same aggregate as gear and are **clamped by the same ceiling**.
  A potion that could push a stat past `STAT_CEILING` would be a power ladder
  you can drink.
- There is no deadline to compare and nothing to tick, so an hour offline and an
  hour idle produce the same result for free (§16). A charge is spent where the
  work is committed — the job row already carries the shortened clock and the
  larger haul — and never by a read. **Costing a hex you are only looking at
  must never burn what you are carrying.**
- **On screen** the charges sit in the top-left instrument cluster, one hexagon
  each: the glyph is the *action* it is waiting on, the color is the *stat* it
  moves — the same two channels §13.1 splits rarity and material across. It is
  the one thing on that plate that is not a number, and tapping it says what
  each charge is and what will spend it. Nothing there may drain or pulse like a
  countdown; the toast (§13.1) already owns the draining hexagon, and a charge
  has no clock to draw.

---

## 9. PvE combat — the road and the dungeon

Combat is **PvE only** — no player-vs-player fighting anywhere in the game. That
removes the snowball problem (winners farming losers' gear) entirely, leaving
only loot-table tuning.

**Skills are §9.5.9**, and they are the one thing in a fight that is not a
trade of blows. The weapon decides which three and the tree teaches them, they
all start a fight on cooldown, and none of them can be steered.

It happens in two places. **Dungeons** (§9.1–§9.4) are the deliberate expedition: you
kit up, spend a charge, and go. **The road** (§9.5) is the other one, and it
comes to you — packs stand on hexes and stop travelers. Dungeons are the ladder
the map points inward at; the road is what teaches you to climb it.

### 9.1 Five dungeons, one per biome, sited in the barren capital ring
| Dungeon | Biome | Signature drop |
|---|---|---|
| Rootvault | Forest | Verdant Shard |
| Deepshaft | Mountain | Ferrous Shard |
| Beastwarren | Plains | Sanguine Shard |
| Ashpit | Badlands | Cinder Shard |
| Windhollow | Grassland | Zephyr Shard |

### 9.2 Floor tiers
| Floors | Party | Loot |
|---|---|---|
| 1–3 | Solo, gear-score gated | Essence + occasional Shard |
| 4–6 | Solo-hard or duo | Reliable Shard, Relic chance |
| 7–10 | 2–4 party required | Relic; **boss at 10 drops Core** |

### 9.3 Entry cost
- **Raid charge** — consumable crafted from refined materials (gives Planks/Ingots a sink
  outside equipment)
- Durability drain, heavier than mining
- **Pity timer on Relic** after N clears, so RNG never pushes players toward raid-spam botting

### 9.4 Difficulty ladder
`mine → hunt → road pack → dungeon floors 1–3 → deep floors → boss`
Each step introduces exactly **one** new mechanic (a fight, then charges, then parties).

The pack is what makes the step from hunting to a dungeon floor survivable: it
is the only place a player learns what attack, defense and durability wear cost
them, and it costs a walk rather than a crafted charge to find out.

### 9.5 Map combat — a pack, a pin, and an exchange

Monsters stand on hexes. They stop travelers, they block the ground they are
standing on, and they are settled in **one action** — an exchange run to a
conclusion the moment you close, with **no health bar to watch**, because the
health bar is your gear's durability and it is already on the item (§9.5.5).

This is the section that wakes four things that have been defined and dead since
the start: the empty **`weapon` slot** (§8.0 rule 5), the three **battle jobs**
(§7.4), the **`power` and `defense`** stats, and §3.2's listed **monster gold**,
which nothing currently produces.

**There is no elemental cycle in v1.** Eight monsters with explicit attack and
defense already make "am I built for this one" a real question, and a matchup
table on top would be a second system answering it. §14 keeps it as a later
layer, where it can tie into Shard types.

#### 9.5.1 Packs — derived, cleared, and never farmed

Same machinery as the herd (§5.5), so a pack that nobody has met costs no
storage: a **time-bucketed hash** of `(col, row, bucket)`, with a **per-hex
offset folded into the bucket** so the world does not blink all at once every
two hours.

| Ring | Spawn chance per hex per bucket | Pool |
|---|---|---|
| Outer (villages) | 0.04 — the safest road on the map, and not an empty one | 2 |
| Mid (cities) | 0.10 | 4 |
| Inner (capitals) | 0.18 | 4 |
| Center (dungeon mouths) | 0.22 | 4 |

**The outer ring runs at twice what it first did.** At 0.02 a walk between two
villages — call it twenty-five hexes — was stopped about two times in five,
which made the pack something a new prospector heard about rather than the
thing §9.4 says it is: the one step where you find out what attack, defense and
durability cost you, before a dungeon charges a crafted charge to teach the
same lesson. At 0.04 that walk is stopped about two times in three, and the
outer ring is still by far the quietest ground on the map. What is not a tuning
value is the **order** — density climbs every ring inward, and there is a test
pinning that it climbs monotonically.

Lifetime is **2h**, through `Balance::scaled()` like every other clock — the
fast clock is a testing tool, so it shortens the pin too. Job XP is not scaled
and never will be (§7.4.4).

**Two hexes never hold a pack**: open water, and any hex with a settlement on
it. The second is the load-bearing one — a pack parked on a capital would lock
an entire region out of the only five-line bench it has (§6), and blocking
shared infrastructure is a kind of griefing the map must not make possible.

**Resolution clears the pack, win or lose.** The cleared flag is the one thing
the hash cannot know, so it is the one thing stored: a key per
`(col, row, bucket)` with a TTL to the bucket's end, in Redis, pushed to other
clients over SSE (§16). Clearing is **shared** — whoever fights it removes it
for everyone, the way a worked seam closes for everyone.

That is the whole anti-farm argument, and it needs no cooldown: **you cannot
re-roll a pack**, because after the roll there is no pack. Supply is capped by
hexes and hours, not by patience.

#### 9.5.2 The roster — twelve monsters, four overlapping pools

| Ring | Pool | New here | Carried in from |
|---|---|---|---|
| Outer | 3 | 3 | — |
| Mid | 6 | 3 | outer |
| Inner | 6 | 3 | mid |
| Center | 6 | 3 | inner |

Twelve in total, and the overlap is the design: walking inward you meet three
you know how to fight and three you do not, so every ring is **legible and
dangerous at the same time**. It also gives §5.2's barren center something to
be, on the walk to a dungeon mouth.

Each carries its own `attack`, `defense` and a profile — a brute is high attack
and low defense, a carapace the reverse, a fast one is middling in both and
wears a weapon harder. The profile is what a player reads, not a level number.

**Three per ring rather than two, and the third is what makes every ring run
all three PROFILES.** It was two, which left the outer rim holding a brute and
a carapace and no swift anywhere until the inner ring — so the one read that
blunts a weapon (§9.5.6) was something a player met for the first time at tier
3, in gear it was about to bill them for. There is a test asserting every ring
runs all three.

**A monster is drawn, and the drawing is the same one in both places.** The map
used to put one generic dark mass with two lit eyes on every hex: the map said
*something* is here and the fight plate said *what*, which is two answers to one
question — and the map's was the one that decided whether to walk on. A
Thornback and an Ash Revenant were the same picture until the preview.

Three axes, and each owns exactly one thing:

| Axis | Owns | Why |
|---|---|---|
| **Profile** | the silhouette | it is what you act on: wide at the shoulders, wide at the ground, or narrow |
| **Tier** | the hide | bark on the safe rim climbing to §13.3's alarm colour, so a tier 4 is the warning colour wearing a body |
| **The monster** | one mark | which of the twelve it is |

**The mark is whatever the monster's own description already says it is** — the
Slag Ogre's girder, the Thornback's quills, the Iron Shrike's wings, the Ash
Revenant's embers. Nothing invented: if the sentence in the roster names a
thing, that is the thing drawn. One mark each, never two, and always a
silhouette-level shape — these are read at 24px on a map tile before they are
read at 44 on a plate, so a mark that needs the big size is a mark that is not
there when it matters.

**The frame is the only difference between the two.** A crest sits in a
hexagon of its own; on the map the tile already is one. The silhouette is
literally the same function.

**A pack wears an ember halo and a pocket's critter a sap one, and the halo is a
solid ring rather than a blur.** §13.2 forbids alpha on the map — transparency
ghosts through neighbouring hexes — so a real glow is not available, and at
58×34 it would be the wrong idea anyway. What works is the silhouette drawn
twice: once underneath with a fat stroke in the halo colour, once solid on top.
The stroke's outer half survives as a hard ring the exact shape of the thing,
made of nothing but solid fills.

Both colours are **lifted in saturation, never toward white.** `shade()` blends
to white, which turned ember into a pink outline and sap into a mint one — and a
pastel ring reads as a highlighter drawn over the thing rather than as light
coming off it. And the halo cannot simply *be* ember: §9.5.2's tier ramp ends
there, so at full ember it would vanish on the one monster that most needs
seeing.

The width adds to whatever a path already carries, so a thin stroked detail — a
hare's ears, a moth's antennae — needs a fatter halo than a filled body does
before any rim survives. That is why the critters ask for more of it at half the
size.

**A carrier gets no halo** (§9.5.7). Ember says *deal with this now*, and a
corpse is a debt on a 24-hour clock rather than something looking at you.

**And the almanac's bestiary shows a monster ON A HEX**, not in a crest — the
same drawing the map puts on the tile, halo and all. A crest is how a thing
looks in a fight; the tile is how it looks while you are still deciding whether
to have one, which is what a bestiary is read for. It stands on plain stone
rather than a biome, because a monster belongs to no ground.

**And the almanac carries the whole bestiary**, as a fourth half beside
Materials, Equipment and Ground. It owes a monster the same two answers it owes
everything else — where it comes from, and what comes off it — so each entry
gives the crest, the profile, the three solid numbers, the rings it stands on
(and which of them it is thickest on, since density climbs inward), and every
drop drawn with its own glyph rather than spelled. Static like the rest of that
screen: it talks to nothing and is correct with no character at all.

**Studying one costs nothing** (§9.5.3's pin has two exits and this is neither).
A `Study` plate beside the Fight button gives the name, the profile, the tier,
the three solid numbers, what the profile *means*, where its wear lands, and
what it pays — read straight off the catalog the client already mirrors, so it
needs no request and cannot be stale. It deliberately says nothing about your
side: whether you win and what it costs are the preview's, thirty pixels away,
and saying them twice would make two answers out of one.

#### 9.5.3 The pin — the road stops, and so does everything else

A pack does **not** force a fight. It stops you, which is a different thing.

- **Travel ends at that hex.** The rest of the road is not walked.
- While a live pack shares your hex you may not **mine, gather, hunt or
  travel**. There is nothing else out there to do, which is the point: the hex
  is theirs until it is not.
- **Two exits, and only two.** Fight it — either outcome clears it — or wait out
  its clock.
- **Nothing resumes by itself.** When the hex is free you are standing on it,
  not at the destination you set out for, and the next move is a decision you
  make again. An hour offline and an hour watching produce the same result
  (§16), because the release condition is the pack's clock either way.

**This is never the dead end §5.6 forbids**, and the reason is exactly that a
**loss clears the pack too**. A character who wandered in over their head is
never parked: they can always fight, bare-handed if their gear is gone, and
losing is a legitimate way out — an expensive one, since losing is dying
(§9.5.7), but never a locked door. It pays nothing and costs durability, a row,
and the walk back.

Which is why **a loss grants no XP at all.** Half XP for losing sounds generous
and is a trickle you can farm by dying on purpose. Losing is an exit, not a
strategy.

#### 9.5.4 Attack and defense — flat numbers, not the percentage stats

Combat needs a base, and §8.1's ceiling makes percentages useless as one: a
±15% swing cannot decide a fight on its own. So gear carries **two flat values**
alongside its work stat.

| | Where it comes from |
|---|---|
| **Weapon** | the `weapon` slot, by rarity rung, split by family |
| **Armor · boots · gloves** | a smaller pair each, beside their work stat |
| **A rolled solid line** | §8.0.1 — an option may be a solid number instead of a percentage, and it simply adds |
| **Battle job level** | added directly — the proof you have fought |
| **`power` / `defense`** | percentages **multiplying** the gear total, inside the same +15% ceiling |

**`attack` and `defense` are solid numbers and the `power`/`defense` StatKeys
are percentages, and the two must never be confused.** They share a name in one
case, which is why anything that can be either — a rolled line — carries a
`kind` saying which.

**On screen the percentage twins are never printed on the item.** A weapon's
`power` and a battle piece's `defense` are the percentage forms of the pair, and
printing both put an inert number where the deciding one should be: +3% power
moves a common sword from 5 attack to 5. The chips show the pair instead, which
is the same stat said in the units it is felt in. The percentage is still real
and still applies — it is visible where it is *felt*, in the totals on the Hero
screen, rather than on a shelf tag where it reads as a second opinion.

**A zero half is not shown at all.** "0 attack" is not information; it is a slot
the piece does not fill, and printing it made every travel cloak look like it
had lost a fight.

**One component draws all of it** — trader, bench, almanac, bag and gear list —
so a piece reads the same wherever it is met. The pair is told apart by its
label, never by color: §13.3 spends ember on a state to deal with and sap on one
worth crossing the screen for, and a stat is neither. An attack drawn in ember
would read as a warning about the sword. They share a name in one
case, which is why anything that can be either — a rolled line — carries a
`kind` saying which.

**Both are on the player state, not only on a fight preview.** What your kit is
worth is a thing to know while shopping, not something to discover by finding a
pack standing on your hex — so the pair and the pool (§9.5.5) ride the state,
and the Hero screen prints them in their own block. Solid numbers get a block
rather than a meter, because a meter implies a roof and these have none.

**Defense belongs to armor, to the shield, and to the sword.** Those are the
three things whose job is to be between you and something: the worn set, the
thing built to stop a blow, and the one weapon family that is meant to be the
balanced answer. **A focus has none at all** — a focus that also held a little
of it would be the balanced one twice, and the glass cannon is the point. It is
paid for in attack.

**The five gathering tools carry an attack too, and it is not this one.** A
tool's attack is what it takes out of a *hex* per second (§7.3); it is worth
nothing in a fight, and combat never reads it. §8 rule 5 has always kept the two
ladders apart, and this is the direction it had not needed to say out loud
before there was a number on both sides.

**One weapon slot, three families**, and the family is your class — the three
§7.4 already names:

| Family | Job | Split | Legendary pair |
|---|---|---|---|
| Shield | Shieldbearer | **⅓ attack, ⅔ defense** | 14 / 31 |
| Sword | Swordhand | **half and half** | 18 / 17 |
| Focus (wand) | Runecaster | **⅘ attack** | 27 / 7 |

**Balanced means an even split, not "a bit of both".** The sword is the one
family whose two numbers are the same, and that is what makes it the reference
the other two are read against.

**A wand keeps a little off you, and a shield lands a little.** Neither may be
zero. A wand at zero guard would make the sword the balanced one twice over; a
shield that cannot land is not a defensive build but a stalemate, because
§9.5.5 makes a fight a **race** — surviving everything in the center and putting
none of it down loses on the bell.

**The shield carries a larger budget than the other two**, and that is not a
thumb on the scale: a shieldbearer has no offense anywhere else in the kit,
while the other two spend the same total on a shape that kills faster.

One slot rather than three, because you fight with one thing. The five
**gathering** tools are all equipped at once precisely because they never
compete (§8 rule 3); a weapon competes with itself. Which family you carry is
what decides **which battle job earns the XP** — §7.4 has always said a battle
job levels by fighting with a shield, a sword or a focus, and this is what
finally gives that sentence something to count.

**Armor is one set with two axes, not a second wardrobe.** Every armor, boots
and gloves item gains attack and defense next to its work stat, and
combat-leaning pieces sit beside work-leaning ones at every rung. Forcing a
change of clothes before every fight would be friction, not a decision (§8 rule
3) — the decision belongs at the bench.

**Battle gear runs the full ladder, village to guild**, same as the gathering
tools and for the same reason: §8.1 rule 4 says every rarity below unique is
reachable by crafting without spending, and a combat ladder that started at a
capital would make that false for half the game.

**Three of everything at every rung, and the three are a *materials* ladder
rather than a rarity one.** Each of the six combat groups — three weapon
families, three worn slots — offers `low`, `medium` and `high` at each of the
five rungs, ninety pieces in all:

| Grade | Costs | Pair |
|---|---|---|
| low | the rung's cheap stock and its spoil | −15% |
| medium | more of both, plus the group's component | the rung's middle |
| high | more again, plus whatever is **rare for that rung** | +15% |

"Rare for that rung" is not the same thing twice: at the bottom it is the spoil
one grade up, at the top it is a capped Tier 3. The rung still owns the
percentage stat and the bench that reaches it (§8.0) — what the grade moves is
the flat pair and the durability, so **a high common overlaps a low uncommon**.
That overlap is deliberate: there is always something better to build, and it is
always a question of what you are willing to carry to the bench rather than of
where you are allowed to stand.

**Every mintable piece wants its group's Tier 3 at every grade**, not only at
`high`. §2 requires a per-wallet cap behind anything that can leave the game, and
a cheap epic with nothing capped underneath it would be the grind→NFT path the
threat model exists to close.

**Work gear stops at the treeline; anything past it asks for battle gear.** The
measured ladder, against the eight of §9.5.2:

| Kit | Beats |
|---|---|
| No weapon at all | the tier-1 carapace, and nothing else |
| Common battle | tier 1 |
| Rare, any family | tiers 1–3, and **neither** of the center's two |
| Epic sword | + the Barrow Knight. Driven off by the Ash Revenant |
| Epic wand | + the Barrow Knight, and the Ash Revenant **about 3 times in 5** |
| Epic shield | tiers 1–3 only. It survives a carapace and cannot put one down |
| Legendary, any family | everything |
| Full legendary *work* set | tier 1 and the tier-2 carapace |

**The wand is the only kit in the game with a genuinely uncertain fight**, and
that falls out of the model rather than being arranged: an epic wand and an Ash
Revenant race each other closely enough that §9.5.5's ±15% swing decides it.

**The shield is always the most expensive win.** A slow kill is more rounds, and
more rounds is more of both wear streams (§9.5.6) — a legendary shield pays 235
durability in the center where a legendary wand pays 145. Survivability is not
free; it is paid at the repair bench instead of on the odds.

That is the intended shape of the walk inward: it is a kit decision rather than
a level one, and giving up mine time and travel speed is not optional past the
mid ring — it is the only way through.

**Every worn piece carries the pair, at every rung including the top two.** The
legendary and unique work pieces were written before §9.5.4 and had none, which
made a Wardencoat worth less in a fight than a common travel cloak. They are far
under the battle piece of the same rarity — 1/9 against 2/18 for armor — and
that gap *is* the trade above, rather than an accident of tuning.

#### 9.5.5 An exchange, and durability is the health bar

```
myHp   = total durability of the equipped weapon, armor, boots and gloves
itsHp  = the monster's own `hp`

each round, you strike first and it strikes back if it is still standing:
  hit = max(strikeFloor(attacker), attacker.attack - defender.defense) * swing
  strikeFloor(a) = max(1, ceil(a * BATTLE_CHIP_FRACTION))     // 10%
  swing = U(1 - BATTLE_SWING, 1 + BATTLE_SWING)               // ±15%, seeded

you win  if its pool empties first
you lose if yours does, or if it is still standing at round BATTLE_MAX_ROUNDS
```

**There is no second pool to invent.** The gear holding you up is the gear the
fight is spending, so a beating and a repair bill are one event rather than two,
and §11.1's largest sink is fed by the thing that already decides whether you
should have engaged.

*(It used to be a single roll against a margin, with a band to keep it from
being decided before it was tapped. An exchange gets that for free and gets
something the coin never had: a **cost**. The interesting question stops being
"will I win" — which a preview answers anyway — and becomes "is this worth what
it takes off my kit".)*

**Combat slots only.** The five gathering tools are not in the pool and never
wear in a fight: §8 rule 2 says only the tool that did the work wears and the
others idle, so counting an axe toward it would quietly turn a full tool belt
into armor.

Four numbers hold the shape, and each of them had to be what it is:

- **`BATTLE_CHIP_FRACTION` = 10%.** Straight subtraction makes armor an on/off
  switch — one point either side of an attack turns routine into impossible,
  which is exactly how every matchup came out 0% or 100%. A striker always gets
  a tenth of its attack through, so a heavy hitter still hurts a wall and a
  light one still cannot. That slope is the whole difference between a rare kit
  and an epic one against the same Barrow Knight.
- **`BATTLE_MAX_ROUNDS` = 60, and the bell is a LOSS.** Pools are far larger
  than anything a pack carries, so a long enough fight is won by whoever brought
  more durability — a wall could be ground down by a kit with no business
  touching it. Failing to put something down is being driven off. Sixty rather
  than forty because at forty the bell decided nearly every losing fight, which
  made defense worthless: you won if your attack cleared the HP in time and
  armor only moved the bill. At sixty most losses are the pool running out, so
  the guard is back in the outcome.
- **`BATTLE_SWING` = ±15%.** Otherwise a fight is a calculator. It is enough
  that two runs at one pack are not the same fight, and enough to decide a
  genuinely close one; in a lopsided matchup it only moves the cost, which is
  correct — being outclassed should not be a lottery.
- **You strike first.** A small edge, and the right one: engaging is a decision
  you made and being engaged is not.

**The fight is settled the instant you close, and then you watch it.** The
server runs the whole loop at engagement, stores it round by round, and hands
the rounds over with the job; the screen draws both pools draining against each
other at one round a beat, and the receipt (§9.5.8) replaces the plate when the
last blow lands.

*(It used to be a cooldown — three minutes plus two a tier, a clock ticking down
to something already decided. The fight was the most interesting arithmetic in
the game and the player saw none of it. A replay costs nothing a countdown did
not already cost, and it is the one place the durability-as-health rule becomes
visible: what drains on screen is what you will be paying to repair.)*

**The clock is the exchange, not the monster, and a round is one second.** So a
rout is over in two or three seconds and a grind against a wall takes as long as
the grind did — which is the whole reason to watch one: a fight that costs you a
legendary should take longer to sit through than a fight that costs you nothing.
The bell at sixty rounds is therefore a **full minute**, which is the ceiling and
not the common case; a Barrow Knight in a mid kit runs about forty-five seconds.
It is the one clock that does **not** go through `scaled()`: `GAME_TIME_SCALE`
compresses game hours so a tester need not wait them out, and an animation is
not an hour.

**The client is handed the outcome early, and that is fine.** A fight cannot be
abandoned (§9.5.3) and nothing is left to decide, so reading ahead buys a few
seconds of knowing and nothing else. The result rides the job rather than the
animation, so closing the tab mid-exchange costs the replay and never the
receipt — reopening catches the bars up to where the clock says the fight is.

Three things follow, and all three are the same rule the rest of the game runs
on:

- **The exchange is run when you close, and stored.** Exactly as a mine records
  the material its tool could reach: the kit that took the fight is the kit that
  fought it, and swapping to a better sword while the timer runs buys nothing.
- **The pack is spent on engagement, not on resolution.** While you are swinging
  at it there is no pack for anybody else either.
- **A fight cannot be abandoned.** §9.5.3 offers exactly two exits from a pack
  and once the first is chosen there is no third — dropping it would be a way to
  duck a loss, and a loss is a death (§9.5.7).

**The preview is a promise, not a guess.** It runs the same exchange with the
swing taken out and prints what the arithmetic says: whether you take it, in how
many rounds, and what it costs. The fight then wanders by fifteen per cent
either way. Server-computed and server-seeded, like every other outcome (§16).

**What a loss costs is printed beside it, every time.** Not as a condition that
sometimes applies — losing is dying (§9.5.7), so it is the terms of the fight
rather than a hazard to check for.

#### 9.5.6 Durability wear *is* the combat system

The pool that held you up **is** the gear, so what the exchange took off the
pool comes off the pieces. There is nothing to convert and no second schedule to
invent: the health bar and the repair bill are the same number read twice.

**A quarter of what the fight took out of you. That is the whole bill.**

```
bill  = round(damageTaken * BATTLE_WEAR_RATE)     // 25%
```

A player who watched their pool drop by eighty knows the bill is twenty before
the plate says so, which is the point of drawing the exchange at all (§9.5.5).

*(It used to be two streams — the whole of the damage capped at half the pool,
plus a separate per-round blade bill for the rounds spent hitting armor. Between
them they could exceed the damage taken, and neither could be predicted from the
bar the player had just watched. One bill off one number is the version that
makes "what drains here is the repair bill" literally true.)*

**The bill lands where the fight happened.**

| The monster leans on | Which means | Takes 70% |
|---|---|---|
| its **attack** | it beat on you | **armor · boots** |
| its **guard** | you spent the fight hitting a wall | **weapon · gloves** |

Seventy against thirty rather than all or nothing, because every combat piece
was in the fight and the split says which part of it was the work. This is the
surviving half of the old two-stream model: *what hit you is on the armor, what
you hit is on the blade* is a ratio inside one bill now rather than a second
bill of its own.

Within a half, the share is weighted by **what a piece was built to take** rather
than by what is left of it, so a big coat takes the brunt and a nearly-broken
piece is **found out** rather than quietly protected. Whatever a half cannot
absorb spills to the other — a fighter with no gloves does not get a discount.

**`wearBias` moves the ratio, never the total.** A Ridge Wyrm "blunts whatever it
is hit with", so it sends half again as much of the same bill to the blade; the
extra comes out of the worn half rather than out of thin air. A monster can
change where a quarter lands and never how big the quarter is.

**Two consequences, both chosen rather than accidental:**

- **A fight that never landed on you costs nothing.** A kit strong enough to
  take a pack untouched pays no repair bill for it. The sink (§11.1) charges the
  fights that hurt and forgives the routs.
- **A long grind against a gentle wall is cheap.** Seven rounds against a
  Thornback run to three points where the old blade stream charged forty. What
  a wall costs you now is the *time*, not the edge.

Both fall out of anchoring the bill to damage **taken**, which is what buys the
legibility above. If the repair sink ever needs the weight back, the honest
lever is a per-round floor on the bill rather than a second stream.

**The cap is the anchor, not a separate rule.** Damage taken can never exceed
the pool, so the bill can never exceed a quarter of the kit however badly it
went. That is a tighter promise than the old "half the pool, plus however long
the fight ran", and it needs no constant of its own.

#### 9.5.7 Death — what losing is

Death is not a third outcome. It is what losing *is*:

> **A loss is a death.** Whatever you were wearing when you took the fight.

*(It used to be narrow — a loss only killed you when nothing absorbed it, no
armor at all or the piece that would have taken the hit going with it. That made
the interesting question "am I wearing anything" rather than "should I take this
fight", and the second one is what the preview exists to be read for. **Armor
still decides whether you lose** — defense is what a strike is subtracted from,
and the pool it protects is the one that empties — it simply no longer decides
what losing costs.)*

What it costs:

1. You wake at the **nearest settlement**. The walk back is the first bill, and
   at five minutes a hex it is a real one.
2. The pack takes **one row from your bag** — truly random, gear or material.
3. **The pack does not despawn.** It becomes a **carrier**, named for what it
   took, drawn as a `XXX's corpse` glyph. It lives **24h**, and the clock is on
   the glyph. **Who sees it is two rules, not one:**
   - **Its owner sees it through any fog, at any distance.** It is their row, on
     a clock, and a debt you cannot find is a fine with extra steps. This is the
     only thing in the game outside the fog, and it is outside it for exactly
     one wallet — so it rides the **player state**, not the map. That split is
     what keeps the two endpoints meaning one thing each: the state is what is
     *yours* and is bounded by nothing, the map is what is *around you* and is
     bounded by sight.
   - **Everybody else sees it only inside sight** (§5.6). A stranger's corpse is
     not owed to you, and a map-wide list of them would be a *scanner* — every
     death on the server, live, with the rich ones worth racing to. Finding one
     is the interesting part.

   On the road sight is zero, so strangers' corpses wink out and your own does
   not. That asymmetry is the rule working.
4. **You** kill it and the row comes home, on top of its ordinary drops.

**A corpse stands, but it does not pin.** A pack owns the hex it is on for two
hours (§9.5.3), which is a hazard; a corpse stands for twenty-four, and a hex
fenced off for a day is exactly the griefing §9.5.1 keeps packs off settlements
to prevent. So it is a **hook, not a fence** — a verb beside the others on a hex
that otherwise works normally. It is also why a loss to a corpse leaves it
standing rather than clearing it: it is a debt, not a spawn, and the *seed*
therefore has to move with the clock. A fixed one would mean losing to it once is
losing to it forever, which turns the walk back into a wall.

**Only the bag is robbed.** Worn gear is not carried (§7.6), so what is on your
belt is what you die in and what you wake up in — and an empty bag is taken at
its word: nothing is stolen, no corpse is left, and the walk back is the whole
bill. There is no way to owe more than you were carrying.

**The strap is asked for before the recovery**, exactly as a bench claim asks
(§8.4). A row that comes home to a full bag would be a row taken twice, and the
refusal is one you can always act on from where you are standing.

Flat gold loss was the alternative and it teaches nothing — a number evaporates
and the day goes on. A carrier gives death a **hook**: a marked enemy, holding
your thing, that you now have to kit up for. It is recoverable, so it is never a
dead end; the recovery costs the two things this game can safely charge, hours
and durability.

**Anyone may kill a carrier — and a non-owner kill destroys the row.**

That second half is not flavour, it is §2. An item another wallet can pick up is
a **direct player-to-player transfer**, which the threat model closes outright,
and "random row" is no defense at all:

> Empty the bag down to the one thing you want to move. Fight naked. Die. The
> carrier now holds exactly that, marked on the map. Partner walks over, kills
> it, keeps it.

Cost of that pipe: one walk and some durability. So the row **burns** unless its
owner is the one standing over it. Rivals can still race you and take the
recovery away — which is the sharper kind of interesting anyway — but nothing
crosses accounts.

#### 9.5.8 What a pack pays

Combat feeds combat and touches no other economy. That containment is what
makes a whole new faucet safe under §2.

| Drop | Notes |
|---|---|
| **Gold**, on a win | fills §3.2's monster drops, and needs no bag row — which matters when the fight was not your idea. A loss pays nothing at all (§9.5.3) |
| **Monster materials** | 2 families × 5 grades: a plate/hide line for the smith and armorer, an ichor/organ line for the consumable bench. Tier 1, biome-free, dropped by nothing else |
| **Leavings** | a **Tier 0** line of four, one per monster tier, dropped every time. §4's junk argument applied to combat: the rubbish carried out alongside |
| **The ground's junk** | §4's own five, about two wins in five. The monster belongs to no ground, but the FIGHT happened somewhere |
| **Looted gear** | the kit the monster was using, at **5–50% durability**, rarity capped at **rare** |
| **Battle job XP** | on a win only (§9.5.3) |
| **Never** | Tier 3, Tier 4, or anything mintable |

**The rarity cap on loot is a §2 rule, not a tuning value.** Epic is where gear
becomes mintable (§8.0), and a monster that drops one is precisely the
grind→NFT faucet the threat model exists to close.

**The leavings are generous precisely because they are worthless.** A Chipped
Fang fetches a gold and feeds no recipe (§4), so a drop nobody can build with
cannot inflate anything — which lets the one part of a fight that always pays
something sit outside the containment above without threatening it. What it
actually spends is a **strap** (§7.6), and that is the interesting part: it is
the one drop in the game that can be worth throwing away.

One per *tier* rather than one per monster, and that is the bag again. Twelve
trophies would be twelve rows, and a fight that costs you a strap is a fight
that cost more than it paid.

**And the hex itself turns up, about two wins in five.** A monster belongs to no
ground — it walked here — but the *fight* did not happen in the abstract, and
what gets trampled into the dirt is the ground's own. It is §4's existing junk,
which is the tidy part: the same five a mine already turns up, so it costs no
new kind of strap. Two tier-0 lines rather than one, and they answer two
different questions — the trophy says **what** you fought and is always there,
the junk says **where** and is a roll. Two guaranteed rows of rubbish on every
win would be clutter dressed as variety.

**Harder packs roll better options, not better rarity.** A tougher monster
grants **extra option slots** (§8.0.1) on what it drops — the same mechanism the
capital bazaar already uses — so a center-ring kill can hand you a rare carrying
three lines, and never an epic.

Battle drafts come off the ichor line and want a new **`battle` buff scope**
(§8.5) moving `power` and `defense`: twelve more on the shelf, two a rung.

#### 9.5.9 Battle skills — what a weapon knows besides swinging

**Nine of them, three to a family, and no two families share a trick.** §9.5.4
already makes the weapon in the slot your class; three costumes over one
mechanic would have made that choice cosmetic. Each family gets the answer to
its own problem:

| Family | Its problem (§9.5.4) | What its three do about it |
|---|---|---|
| **Shield** | kills slowly, so wins cost the most | turn being hit into damage, and buy rounds where nothing comes back |
| **Sword** | remarkable at nothing | attrition — more swings, answered blows, a guard that never comes back up |
| **Focus** | no guard anywhere in the kit | end it before that matters: pierce, burn, escalate |

| Family | Skill | CD | What it does |
|---|---|---|---|
| Shield | **Shield Bash** | 11 | it loses its next **2** answers |
| | **Anvil Stance** | 14 | for 3 rounds **half of what lands is kept, not suffered** — and comes back as one blow |
| | **Warden's Toll** | 12 | one blow that adds **your defense to your attack** |
| Sword | **Onslaught** | 10 | you swing **twice** for 2 rounds |
| | **Sunder** | 12 | its guard drops **3 permanently**, and stacks with itself |
| | **Riposte** | 13 | for 3 rounds everything it lands **comes straight back**, through no guard |
| Focus | **Ember Bolt** | 11 | ignores guard, and **burns 3 rounds** — armor answers neither half |
| | **Chain Arc** | 10 | a blow that is **bigger the deeper the fight has gone** |
| | **Rune of Binding** | 15 | it loses its next answer |

**The WEAPON decides which three; the TREE teaches them.** §9.5.4 makes the
family in the slot your class, so carrying a sword is what makes you a
Swordhand — but the three sword skills are then *learned*, and they are learned
as **ordinary nodes of that battle job's tree**: one at depth I, one at II, one
at III. A fighter who has spent nothing swings and does nothing else.

Being nodes is the load-bearing part. A battle job still costs **thirty points
like every other job** — the skills displaced three stat nodes rather than
arriving beside them — so learning all three is a tenth of the tree, paid for
out of the same hundred (§7.4.1) and against the same stat nodes. That is the
choice: a Runecaster who takes all three has three fewer nodes of pair and wear
than one who takes none.

**What the displaced nodes were worth was merged, not lost.** Each one's value
went into a surviving sibling of the same kind, so a battle tree is worth
exactly what it was before it taught anything — three of its nodes are simply
larger. A skill may never be placed on a tree's *only* node of some kind, or the
tree loses that kind outright; there is a test for it, because it happened
twice.

*(They used to arrive with the weapon, on the argument that the three simply
**are** what a sword is. That made a Runecaster's whole kit free the moment they
picked up a wand, and left the battle trees buying nothing but percentages on
skills nobody had chosen.)*

**A `battleSkill` node is the one effect with no value** (§7.4.3): owning it IS
the effect. It is also the one thing a battle tree carries that is not a number.

What a point ALSO buys is the tree that sharpens them — `skillPower`,
`skillCooldown`, `skillStun` (§7.4.3), each capped so a maxed tree is worth
about a rung of gear and never a tier of it.

**Every skill starts a fight on cooldown, and the cooldowns are long.** Both
halves are load-bearing. An opening alpha strike would decide fights §9.5.5
wants decided by the exchange; and a pack put down in four rounds is meant to be
a **rout**, not a rotation. Skills are what a *long* fight is for, which is
exactly where the shield needs them. At most one fires a round — the first the
family lists that is off cooldown — and using one resets only that one, so a
long fight rotates through all three rather than repeating the cheapest.

**Nothing is steered.** A fight is settled the instant you close (§9.5.5) and
cannot be abandoned (§9.5.3), so this is not a bar of buttons: the skills are on
the fight preview because *whether to close at all* is the decision, and against
a long fight these are half of it.

**The burn tick and a riposte both ignore guard**, and that is the one asymmetry
in the set. It is what gives a family an answer to a carapace — the matchup
where `BATTLE_CHIP_FRACTION` otherwise leaves a light hitter tapping at a wall
until the bell.

**A stun is set, never added, and a burn is refreshed, never stacked.** Two
stuns running would be a monster that never answers again; two burns would be
two fires on one body. Sunder is the deliberate exception: it is *permanent and
stacks*, which is the one effect in the game that makes every round after it
worth more than the one before, and it is the sword's for exactly that reason.

**The preview runs the real exchange with the swing pinned to 1.** It used to
approximate — rounds-to-kill against rounds-to-fall — and that closed form does
not survive a stance that stores damage for three rounds and returns it as one
blow. §9.5.5 calls the preview a promise; running the actual loop is the only
version of it that stays one.

**On screen** each skill has its own procedural glyph (§13), and the replay
draws it on the round it lands with one line saying what it did — *held 2
rounds*, *guard down 6*, *answered for 13*. It gets a strip of its own between
the crests and the pools rather than a mark tucked beside the round counter: a
skill is the only thing in an exchange a player did not already expect, and at
one round a second it has to be readable at a glance. The strip keeps its
height whether or not anything fired, so the pools never jump — and on the
rounds that had none, which is most of them, it holds a hairline. Reserved and
empty read as a hole; a hairline is the seam between *who is fighting* and *how
it is going*, and a skill breaks it open.

**The cooldown comes round a HEXAGON, and that is a rule rather than a
flourish.** It was a circle, and a circle was the only round thing in a game
whose map tiles with hexes (§13.2), whose icons are cut from them (§13.1) and
whose screens block nests as a honeycomb (§13.3) — one foreign object on a
plate full of cut corners, and it read as borrowed from some other app. The
sweep travels the six edges of one flat-top hexagon, drawn twice and dashed by
`pathLength` so it uncovers itself from the top middle, clockwise. Not a circle
wearing a hex mask: the stroke is genuinely hexagonal, which is the difference
between borrowing the shape and being made of it.

The sweep reports the clock and **only** the clock — it is full-strength copper
whether or not the skill is ready, because fading it toward the track colour
was what made two skills at 79% and 92% indistinguishable. Readiness is told on
the mark instead, which is where the eye already is: dim while it runs, copper
when it is up, ink on a filled face for the one beat it goes.

**They are named on the bench, and that is where the rule is said out loud.**
A player who has spent no skill points and finds three skills armed is looking
at §9.5.9 working rather than at a bug — so the list says *they come with the
weapon, not with a skill point* in as many words. Off a fight they carry the
sentence and the cooldown; every figure arrives with the fight, because every
figure is the server's.

**They are NOT on the pin.** That plate is read with something standing on your
hex, and what it holds is the decision and its terms — who, when it leaves, and
what a loss costs (§9.5.7). Nine cold dials under that is a reference table
where an answer should be. It was there on the argument that "whether to close
at all is the decision, and against a long fight these are half of it", which is
true and is an argument for reading them *somewhere*, not for reading them in
the one place already carrying the urgent half.

*(The client mirrors the nine — name, glyph, family, cooldown, and the effect
SENTENCE — in `battleSkills.ts`, because the modal has to name a skill the
instant a stored round mentions one and a fetch that has not landed would draw
a key where a name goes. **Nothing the fight reads is mirrored**: multipliers,
ticks and stun lengths are the server's alone (§16). The sentence is safe to
carry for exactly the reason it is written the way it is — it holds no figure,
and a test proves it by maxing a tree and checking it did not move. A parity
test compares the two.)*

**One band, one cooldown rail, one skill row, drawn everywhere a fight is.**
The replay, the receipt and the bench at `/battle` are three moments of one
screen, so the two crests facing each other across a middle column are a single
component with a slot in it — a round counter while it runs, a hairline when it
is over. It was three hand-kept copies, comment for comment, and the bench is
where that stops being a tidiness argument: a simulator that redraws what it
simulates is a second opinion, and the first thing a second opinion does is
drift. The arithmetic was already shared — `/api/battle-sim` runs the real
`resolveBattle()` and the real wear split — and now the drawing is too.

**Open: the roster and the swing were tuned before any of this existed.** Adding
skills moved §9.5.4's measured ladder up by roughly a rung — a rare focus now
takes a Barrow Knight, and an epic sword takes an Ash Revenant about one time in
five where it never did. Monster `attack`/`defense`/`hp` and `BATTLE_SWING` are
the levers for putting that back where it belongs, and neither has been touched
yet: how hard the eight *should* be once every fighter has three skills is a
tuning decision, not a consequence of adding them.

---

## 10. Guilds

Scoped to v1: **raid co-op + shared consumable pool + feature ownership.** Territory war,
guild-vs-guild, and politics come later.

### 10.0 Founding one — the hall, and what it costs

A guild is founded **at a city or a capital**, never at a village and never in
open country. That is the same argument §6 makes about every other bench: a
guild is a *place* before it is a roster, and the place has to be somewhere
people already walk to.

| | Rule |
|---|---|
| **Cost** | **20,000 gold**, from the founder's own purse |
| **Where** | Standing at a **city** or a **capital** |
| **One each** | A character may belong to exactly one guild |
| **Identity** | Name, **code**, description, and a 32×32 flag they draw |

**20,000 gold is the point, not a price tag.** §11.2 lists capital bidding as
the largest gold sink in the game; this is the second, and unlike bidding it is
open to anybody with the patience to save. Gold has no bridge to NFT value
(§3.2), so it may be inflated freely — which is exactly why it needs sinks this
size for the number to keep meaning anything.

**Many guilds may hold halls in one settlement.** A hall is not a claim on the
place: §10.4 keeps capital *ownership* as its own separate, admin-triggered
thing, and conflating the two would make founding a guild an act of conquest.

#### The hall is the legendary bench

§8.0 has always said legendary is made at a guild hall and nowhere else. Until
now that was a rung nobody could reach — forty recipes with a station no
building in the game had. A hall is what can eventually reach them, **for that
guild's members, at that guild's hall**.

**Founding opens the building, not the rung.** A new hall's bench reaches
exactly what the settlement under it already reached, and every rung above that
is bought a level at a time out of the treasury (§10.5). So 20,000 gold is the
*entry* to the top rung rather than the price of it, which is the right shape:
it is payable by anyone, and what comes after is payable only *together*. A hall
open to passers-by would be a public good rather than a reason to join, and a
legendary bench that needed no guild would make §10 optional.

#### 10.0.1 The door — one setting, three positions

| | What it means |
|---|---|
| **Closed** | not listed, and nobody gets in |
| **Open** | listed, and walking in is enough |
| **Approval** | listed, and the owner decides who comes through |

**One setting rather than two flags.** "Recruiting" and "vets applicants" as
separate booleans make four states out of three, and the fourth — *closed, but
vetting* — means nothing at all. A door is in one position at a time.

**Which position it is in is on the listing**, so the button says *Join* or
*Ask* before it is pressed. Finding out which one it was afterwards is the worse
answer.

**A refusal is not a ban.** Turning somebody away takes their name off the list
and nothing else; they may ask again. Bans are a different thing with different
consequences, and they must not arrive by accident through a rejection button.

**Joining tears up every other application.** One guild each (§10.0), so a name
left on another list is an answer to a question no longer being asked.

#### 10.0.4 Where each half of this lives

**Every verb is at the settlement; the corner panel only reports.** That
follows from §10.0's first line — a guild is a *place* before it is a roster, so
the place is where it is dealt with.

| | Where |
|---|---|
| **Found one · join one · roster · door · flag · leaving** | the dock, at a **city or a capital** |
| **Who is in it, and where the hall stands** | the guild screen, from any hex |

The corner panel is reachable from every hex on the map, which is exactly why
nothing is *done* there. Offering *found a guild* everywhere and allowing it
almost nowhere is the shape of a menu that wastes your time, and the same
argument covers the rest of the roster once it is made: a door you can reset
from the bottom of a mine is a setting, not a hall. At the dock the offer and
the possibility are the same thing.

What the panel keeps is what a player asks it from anywhere — **who is with me
and where is home** — plus the distance back to it, which is the only number
that makes the second half worth printing.

#### 10.0.2 Three ranks, and the middle one exists to do the chores

| Rank | May |
|---|---|
| **Owner** | everything, including disband and hand over |
| **Officer** | remove members, open and close recruiting |
| **Member** | leave |

Officers exist because the owner is one person in a game where nobody is
online at the same time as anybody else. Everything an officer may do is
**reversible in one action**; everything that is not — disbanding, editing the
identity, promoting — stays with the owner.

**The last owner cannot leave a guild that still has members.** They hand it
over or they disband it. A guild whose owner has walked away is a guild nobody
can close, and it would sit on its name and its code forever.

#### 10.0.3 The flag — 32×32, drawn in the app

A guild draws its own flag on a 32×32 grid, a dot at a time, in **any color**.
It is stored as exactly 1024 colors and nothing else: no upload, no file, no
URL. What can be in the column is bounded by the column's own shape, which is
the only kind of user-supplied image this game is willing to carry.

§13 says all visuals are procedural and no artist is required, and this is the
one deliberate exception — because the thing being drawn is not *art direction*,
it is **identity**. A guild that all look alike is a list of names.

### 10.1 Shared consumable pool — guardrails required
The pool is technically a backdoor around the no-P2P-trade rule. It must be constrained:
- **Consumables only** — never raw/refined/rare resources
- **Contribution ≠ withdrawal** — apply a conversion loss (e.g. contribute 100, pool credits 80)
  so it can't function as a clean 1:1 transfer channel
- **Per-wallet daily withdrawal cap**
- Open question: should the *guild* also need to meet a wallet-age/balance bar, so a legit
  guild can't be used to vouch bot wallets in by proxy?

### 10.2 Raid loot split
By contribution (damage dealt, resources spent on entry), not equal share — prevents
leeching. A small alliance-bonus roll goes to the pool on top of individual loot.

### 10.3 Feature ownership
- Guilds own **processing features** at cities/capitals; ownership grants their members a
  private 5-slot queue plus up to **10% cheaper/faster at max level**
- Ownership is **upgradeable Level 1–20** (long-term guild resource sink)
- Ownership is **permanent** — no renewal/upkeep (a 10% bonus doesn't justify upkeep tracking)
- A guild may own **max 2 instances of the same feature type** map-wide (anti-monopoly)
- Owning guild may **self-revoke to relocate**, recovering only **40%** of upgrade resources

### 10.4 Capital claims — admin-triggered bidding
- **Purely admin discretion**, no automatic threshold. Admin opens a timed bidding window
  when guild demand warrants it.
- **Gold-only bids.** Members donate gold to the guild pool; donations are **non-retractable**,
  even by the guild owner (prevents fake-bidding to scout rival pool sizes).
- No replacement of existing owners — bidding rounds are for **unclaimed** capitals only.
- Cities have **no ownership layer** — only capitals, because capitals sit adjacent to
  dungeons and that proximity is the real prize.

This is likely the single largest gold sink in the game.

### 10.5 The treasury — what a guild's gold is actually for

**A guild is founded with a hall and nothing in it.** The 20,000 buys the
building and the place on the map; what the roster puts in afterwards is what
turns it into somewhere worth walking to. Two facilities, both bought a level at
a time out of a shared purse.

| | What the level buys |
|---|---|
| **Hall** | seats. 10 flat, **+10 a level**, five levels, so a maxed hall holds sixty |
| **Bench** | rungs. One **more** than the ground underneath it already reached, up to legendary |

**Gold goes in and does not come out.** Non-retractable, exactly as §10.4
requires of a bidding donation and for the same reason: a pot that can be
emptied again is a pot whose size can be scouted. Anybody in the guild may
donate — it is the one guild action with no rank on it, because gold going the
right way needs no permission — and **the owner alone spends it**, since §10.0.2
keeps the irreversible things with them and three hundred thousand gold is the
most irreversible thing a guild can do. An officer opening a door wrong costs
one tap to undo; an officer spending the roster's year of saving costs the year.

What each member has put in is **kept on their row and shown on the roster**.
§10.2 already says raid loot splits by contribution rather than equal share, and
this is the same number asked a different way: who carried the hall.

#### The price

```
cost(level) = round(25000 * level^1.6, to the nearest 100)
              25,000 · 75,800 · 145,000 · 229,700 · 328,300
```

**The first level costs more than the hall did**, and that is the shape rather
than an accident. Founding is what one patient prospector saves for; a facility
is what a roster does together. Gold is the one currency the game may inflate
freely (§3.2), which is exactly why it is the one that can carry a sink this
size — a maxed Hall and a city Bench run to a million between them.

#### The bench climbs from the ground it stands on

This is the part that decides whether the early levels are worth anything. A
Bench level is **one rung past what the settlement itself reaches** (§8.0), not
one rung up from common:

| Hall stands in | Starts at | Levels to legendary |
|---|---|---|
| City | uncommon | **3** — rare, epic, legendary |
| Capital | epic | **1** |

So no level is ever money thrown away, and a capital hall reaches the top for a
fifth of what a city hall pays. That gap is the same pull inward §5.2 puts on
everything else, said in gold: the contested ring is where the cheap route to
legendary is.

**Legendary still needs the hall as well as the bench.** The two questions are
separate and both are asked — *is this your guild's hall* (§8.0: members only,
at their own) and *has the bench been built that far*. A capital's own bench
never reaches legendary no matter who is standing at it.

**The seats are checked on the way in, not warned about.** Both doors (§10.0.1)
arrive at the same admission, so that is the one place a full hall says so. A
guild that wants to grow has to build for it, which is what makes the Hall
facility worth buying at all.

---

## 11. Sinks (the stability engine)

### 11.1 Continuous / passive (unavoidable, happens during normal play)
- **Bag pressure** (§7.6) — the bag does not destroy anything itself; it forces
  the choice between the NPC's deliberately poor rate, a processing queue, and
  throwing the surplus away. It replaced storage-overflow decay, which punished
  the same state twice and did it while the player was not looking.
- **Equipment destruction** (§8.2) — durability drains from mining, raiding and
  hardest of all from fighting (§9.5.6), and at zero the item is gone rather
  than idle. This is the largest continuous sink in the game, and it is the one
  that makes repair urgent instead of optional.
- Building/feature degradation requiring refined-material repair
- **Death's stolen row** (§9.5.7) — burned outright unless its owner walks back
  and takes it off the carrier
- **Minting fees** (§3.3) — gold on the way out, and the item itself leaves the
  world with it
- Tile abandonment penalty (leaving a hex mid-progress forfeits partial yield)

### 11.2 Periodic / event-driven
- **Championship** — see below
- Capital bidding rounds (gold)

### 11.3 Championship = supply-control valve, NOT a fixed season
Championship is **triggered by admin/telemetry when circulating supply crosses a threshold**,
not on a calendar. Fixed schedules let players time their entry and defeat the purpose.

- **Entry cost:** equipment durability burn + resource/gold fee
- **Prize pool is funded by entry fees** — pool value scales with how much supply needed
  removing. Self-balancing.
- **Equipment entered is consumed/heavily degraded regardless of win or loss** — this is the
  actual supply reduction, not the prizes
- **Bracketed by gear tier / level** so fresh wallets aren't farmed by maxed ones
- Rewards: rare crafting materials, cosmetics, leaderboard standing — **never permanently
  stronger gear** (that would reintroduce the snowball)

---

## 12. Onboarding — the ledger, not a tutorial

**There is no tutorial.** There was one: eleven scripted steps and a card in the
corner. It was always the *actual game loop* rather than fake mechanics, so
there was never anything to unlearn — which is exactly why it converted into
quests without losing a lesson.

What it never had was a **reason to finish**. It paid nothing, and a prompt that
pays nothing is a prompt to dismiss. The same nine lessons in the same order,
each with gold on the end of it, is the same teaching with a stake in it — and
it leaves **one** place a player looks to find out what to do next instead of
two.

The opening arc, which is the old script:

1. Bring back branches bare-handed → hex work, biome locking, and §4.0: bare hands get scrap
2. Sell them → NPC shop, gold faucet, and a rate that is bad on purpose (1g a branch)
3. Buy a Stone Axe → gold buys the bottom two rungs, and the axe is the *forest* tool (§8.0)
4. Put it on → a tool pays out on its own line and nowhere else
5. Work the same hex → it gives wood now, not branches. This is the payoff
6. Take planks off a saw pit → processing, and the presence bonus (§6.2)
7. Forge a Hewn Axe → material crafting, and the gold→crafted rung
8. Back to the trees → the loop closes with visible improvement

§5.4 guarantees a forest spawn with a woodcutting village in reach, which is
what lets this arc name wood, a stone axe and a saw pit without ever
soft-locking anybody.

The arc used to end on a sentence about the contested ring. Now the ledger just
keeps going — each quest after it points at one system the arc only brushed: the
road, the trader, the benches, the seams, the ring.

### 12.1 How the ledger works

A chained ledger of one-time tasks that pays **gold** (§3.2) and tells a
prospector what the map wants from them next. Three rules, and they are the
whole of the design:

**One-shot, per character, forever.** A quest is claimed once and never comes
back. That cap is what makes it safe for §2 — an unbounded gold faucet is a
bot's entire business plan, while a finite list of one-time payouts is worth
exactly as much to a thousand wallets as to one, which is not enough to farm.
Dailies, if they ever land, need their own cap and their own argument; they must
not be bolted onto this.

**Gold, and only gold.** §3.3 forbids a grind→NFT faucet outright and §3.2 makes
gold the currency that may be inflated precisely because it bridges to nothing
external. A quest that paid a rare material would be a hole in the threat model
rather than a nicer reward.

**Nothing here is a new verb.** Every goal counts something the player was going
to do anyway — a haul, a walk, a run at a bench, a sale — riding the same eleven
call sites the tutorial cursor used to sit on. That is most of why §12 converted
without losing anything. A quest asking for an action that existed only to
satisfy quests would be a second game played beside this one.

| Goal kind | Counts | Narrowed by |
|---|---|---|
| `gather` | units that **landed in the bag** off a mine | a material, or any |
| `process` | refined units off a bench | a line, or any |
| `craft` | things made | a bench category, or any |
| `travel` | hexes actually crossed | — |
| `sell` | gold taken from traders | — |
| `level` | character level | — |
| `job` | a job level | the job |

The first five are **counted**: a tally on the character's row for that quest,
bumped as the work finishes. The last two are **measured** — read off the
character every time they are asked about and never written down, because "am I
level five" has a live answer and a stored copy would eventually disagree with
the character it is about. That is also why there is no `completed_at` column:
whether a goal is met is a comparison, and storing the answer beside the inputs
is a second opinion about one fact.

**Counted work counts whether or not the quest was offered yet.** A prospector
who walked two hundred hexes before anybody wrote the quest down has still
walked them, and being handed a task already half done is a better welcome than
being told to start again.

**A quest is offered once the one before it is *claimed*** — not merely met — so
the chain advances on a decision the player made. A still-locked quest is not
sent to the client at all: what is next should be legible, and what comes after
that is not yet anybody's problem. That single `requires` field is the whole
extension point; adding a quest is a row in `Quests::DEFS` and nothing else.

**On screen** it is a ledger in the top-right screens block, and its cell goes
**green** when something is payable. That is the same idea as the bag's ember
(§7.6) pointed the other way, and the color is the whole distinction: ember is
what a *problem* looks like — a full bag, a broken tool — and a reward wearing it
reads as an alarm going off over good news.

Gold is taken too, and deliberately not reused for this: gold is the currency,
it is already on every reward figure in the panel, and spending it on status as
well would make "20g" and "done" the same color saying two different things.

Two tabs and no third: **pending** is everything still owed you, in progress or
finished-and-waiting, sorted so the payable rows come first; **completed** is
everything already paid. Finished reads green wherever it appears — the tally,
the bar, the goal line — and nothing else on the screen does.

**A claim answers with a receipt, never a toast.** It is the one thing on the
ledger the player came back for, and there is more to say than a status line
holds — what was earned, what the purse is now, and what the claim just opened
up. It gets the same one-beat arrival as the haul modal (§4), and like that one
it carries no button: the gold landed before the plate was drawn, so "Take it"
would be a question with one answer.

---

## 13. Art direction — no artist required

All visuals are **procedural SVG**. The full equipment icon set is generated from
`9 base shapes (one per slot) × 3 tier treatments × 5 material palettes` via
fill/stroke swaps. The 25 gathering tools of §8.0 cost five new silhouettes and
nothing else.

**One shape, and two ways of cutting it.** A hexagon is what the map tiles
with, what an icon is framed in and what a cooldown comes round; a **chamfer** —
two opposing corners cut off the same stone — is what a panel, a button and a
chip get, because a stretched hexagon is unreadable at a panel's aspect ratio
and a chamfer is not. Nothing in the interface is round. If a new element wants
a circle, it wants one of these two instead.

`.plate` draws its hairline border by being the border colour with **exactly
one child** inset a pixel to carry the fill — so `.plate > *` styles every
direct child as if it were that one. A plate handed five children draws five
stacked slabs, which is what the fight replay did until it was given the single
wrapper the contract asks for.

### 13.1 Icon system
| Axis | Encoding |
|---|---|
| Equipment slot | One base silhouette (axe, pickaxe, bow, hammer, sickle, armor, boots, gloves, weapon) |
| Rarity | Six colors: gray → green → blue → violet → gold → ember. Owns the hex frame and the glow; ornamentation starts at rare. |
| Material | Accent stays on the body, so "what it is made of" and "how good it is" never compete for the same color |

### 13.2 Map rendering (critical implementation notes)

**Settlement tiers are told apart by shape category, not by size.** At a 58x34 hex
you often see only one settlement, so there is nothing to compare a height
against. Village is a *scatter* of unaligned huts, city is an *enclosure* — a wide
toothed wall with a gate — and capital is a *spire* with a gold pennant. Keep that
distinction categorical if these are ever redrawn.

The working approach, after several failed attempts:

- **Bake the tilt into the geometry.** Hexes are drawn squashed (58×34 px, not equilateral)
  with extruded side faces for thickness. **Do not use CSS `perspective`/`rotateX`** — it
  magnifies near tiles, shrinks far ones, and distorts hex shape.
- **One single SVG** containing all tiles as `<g transform="translate(...)">`. Per-tile SVGs
  with `overflow: visible` overlap and stack drop-shadows into visual mush.
- **Painter's algorithm** — sort tiles by screen Y before render so tall props (mountains,
  capital towers) correctly occlude tiles behind them.
- **No alpha anywhere on the map.** Use solid desaturated fills for depleted tiles.
  Transparency causes ghost-hex artifacts through neighbors. Unscouted ground
  (§5.6) is the same rule: a darker **solid** fill, never opacity.
- **The dashed ring is the sight boundary, not a fence.** It marks where the
  scouting report stops, and it vanishes entirely on the road, where sight is
  zero. Every hex outside it is still walkable — the map must never imply
  otherwise.
- **Beyond sight a tile gets its terrain color and, if anybody lives there, its
  settlement glyph and its name** — the name dimmed, because it is derived
  rather than scouted (§5.6). Nothing else: no props, no slot pips, no live
  state. The glyph and the name are what make deciding to walk somewhere
  possible; the absence of everything else is what makes arriving worth
  something.
- **What a settlement processes is never drawn on the hex**, scouted or not. It
  is on the tile card, in the portrait slot a seam fills with its material: a
  comb of the materials each line turns out (§6), on the same nested lattice
  the map itself tiles with.
- **Dead ground fills that slot with a hex of itself** (§5.2) — the biome's own
  fill with a snag or a scree slope on it, drawn by the very function that draws
  it on the map, exactly as water and a dungeon mouth are. It is the one kind of
  country the map deliberately withholds at a distance, so the card is where a
  player learns what it looks like; a blank pin there left the one hex worth
  recognising as the only one whose portrait said nothing.
- **One exception, and it is one wallet wide: your own corpse** (§9.5.7). It is
  drawn through any fog at any distance, because it holds a row of yours on a
  24h clock. Anybody else's obeys the fog like everything else.
- Tiling: flat-top hexes, `colStep = W * 0.75`, `rowStep = H`, odd columns offset by `H/2`.
- **Layout with inline styles, not Tailwind arbitrary values** (`w-[390px]` etc. silently
  failed in the artifact sandbox and collapsed the viewport to zero height). Use a flex
  column: header `flex: 0 0 auto` → map `flex: 1 1 auto; min-height: 0` → tab bar `0 0 auto`.

### 13.3 Palette
```
ink        #141b18   inkPanel  #1d2622   line    #3a463f
vellum     #ece3cd   vellumDim #c9bd9e
copper     #c1793f   ember     #b8453f   gold    #d8b34a   violet #7d5fa8
sap        #8fbf7f
forest     #5f8058   mountain  #6d8399   plains  #b08a5a
badlands   #96604c   grassland #a8a05c
```
Depleted tiles use a darker/desaturated variant of their **own biome color**, never gray —
the land is drained, not dead, and it will regrow.

**Dead ground has no colour of its own at all** (§5.2), and that is deliberate:
it wears its biome's fill, so at a distance — where §13.2 draws the fill and
nothing else — a waste and the country around it are the same picture. The tell
is entirely in the props, which are drawn in sight and nowhere else.

**What the props say is that nothing is alive.** Each of the five is its biome's
own silhouette emptied out — bare snags where the conifers were, scree where the
peak was, stubble where the tufts were — drawn in the biome's own colour
drained, never in gray. Gray would say *different place*; this has to say *same
place, finished*. Cracks in the pan are the one thing all five share.

*(A single bleached `barren` tint was tried first. It read beautifully and
defeated the purpose: the wastes were legible from across the map, so there was
nothing left to go and find out.)*

**Ember and sap are a pair, and neither may do the other's job.** Ember marks a
state to deal with — a full bag, a broken tool, a destructive button. Sap marks
one worth crossing the screen for: a finished quest, a good toast. A payout in
ember reads as an alarm; a warning in sap reads as a congratulation. `forest` is
a biome fill and is not either of them.

**The screens block is three across and two down** — a honeycomb, nested the way
§13.2's map hexes are: three quarters of a width between columns, middle column
dropped half a height. Six cells in a row would eat 350px of the top edge, and
six in a nested column reached a third of the way down a phone. Three by two is
the squarest the lattice allows, and it keeps every screen within a thumb's
reach of the corner.

---

## 14. Open items — not yet designed

Ordered roughly by leverage:

1. **Crafting recipe tree in full** — the chokepoint every other system feeds into
2. **Dungeon combat** — §9.5 answers resolution for a pack on a hex; floors, parties and
   a boss are a different shape and are not designed yet
3. **Loot table math** — drop odds per floor, pity-timer thresholds
4. **NPC shop catalog** — full gold-sink list and price curve
5. **Championship trigger thresholds** — what telemetry values prompt an admin event
6. **Guild formation** — member cap, roles, join/leave flow
7. **Marketplace mechanics** — listing fees (another sink), anti-wash-trading, floor manipulation
8. **Catch-up for late joiners** — early dungeon tiers scaling to server age, not just level
9. **Notification design** — resource ready, raid available, **a pack on your hex**,
   equipment about to be destroyed (critical for an idle game's retention)
10. **Provable fairness** — on-chain verifiable seeds for loot and championship outcomes
11. **Public queue congestion** at popular capitals — priority rules, if any
12. **Fairness of permanent guild ownership** — should there be a periodic "slot up for
    grabs" event so new guilds always have something contestable?
13. **Elemental matchup**, deliberately left out of §9.5 — a Fire/Water/Earth/Wind +
    Neutral/Light/Dark cycle mapping onto Shard types, if the eight profiles ever stop
    being decision enough

---

## 15. Legal flags (not design, but pre-launch blockers)

- **Loot box regulation.** Raid loot odds + purchasable NFT bundles can trigger loot-box law
  in Belgium, the Netherlands, and parts of the US. Determine exposure before launch.
- **Securities framing.** Market NFTs as utility/collectible. Avoid "investment," "yield,"
  or "guaranteed value" language anywhere in copy or marketing.

---

## 16. Guidance for implementation

- **Server-authoritative everything.** Timers, yields, drops, durability. The client renders
  state; it never asserts it.
- **Build the economic telemetry dashboard early** — faucet vs sink rate per resource tier,
  settlement tier distribution, equipment in circulation. Championship triggers and balance
  patches both depend on it. Ship it before launch, not after the first inflation crisis.
- Suggested stack given prior projects: PHP or Node backend, MySQL/SQLite, **SSE + POST** for
  any realtime needs (already evaluated as sufficient at this scale — WebSockets are overkill),
  React + Vite frontend, Capacitor if packaging for Android.
- Start with the opening quest arc (§12) as the first vertical slice. It touches mining,
  processing, gold, NPC shop, and crafting — roughly 60% of the core systems in one flow.