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

### 5.2 Ring layout (concentric, drives generation)
| Ring | Contents |
|---|---|
| Outer | Villages (dense), safe mining (low yield), most spawns |
| Mid | Cities, mixed safe/contested mining |
| Inner (capital ring) | **Capitals.** Contested PvP-yield mining, **rare materials spawn here** |
| Center | **Barren of everything.** Dungeon entrances and nothing else — no settlement stands here. |

The two opposing pulls (outward for resources, inward for processing + dungeons)
force constant traffic through the contested middle ring. This is intentional.

**Capitals stand in the contested ring, not the dead centre**, and that is the
sharpest version of the same pull: the best bench in the game, the only one that
runs all five lines and reaches epic, sits on ground other prospectors are
working. You cannot process at the top tier without walking into the PvP band.
The centre stays reserved for dungeon mouths, so the last step inward is a raid,
never an errand.

### 5.3 Biomes
Clustered regions (Voronoi-style from seed points), **not** random noise — players need a
mentally navigable map. Rare-material biome variants sit inside/near the PvP ring.

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
whatever grows there. No party, no raid charge, just time.

**No Tier 4, and that is a §2 rule rather than a tuning value.** Essence used to be
on this table, as "the only activity bridging the mining and raid material tracks".
A herd on a four-hour clock that anyone with a crude bow can shoot is a faucet for
the one tier the dungeons exist to gate, and §9.4's ladder is supposed to end at a
boss rather than at a deer. Raid materials come from raids.

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
| **Sight while travelling** | **0.** You are between hexes, watching your feet. |
| **Travel range** | **None.** Any hex on the map is walkable from any other. |

**Travel has no reach limit and must not grow one.** Distance already costs the
one currency an idle game cannot inflate — hours, at ten minutes a hex — so a
level gate on top of it would be a second answer to a question the clock has
already answered.

There are exactly **two refusals**, and neither is a distance. The edge of the
map, and an **overloaded bag** (§7.6). The second is the only one the player can
undo, and it can always be undone from the hex they are standing on — sell,
process, or throw something away. A refusal with no way out from where you are
standing would be a dead end rather than a decision.

**Outside sight the map is not blank, it is unscouted.** Terrain is a pure
function of `(col, row, seed)` (§5), so the client draws the land itself for
free, at any distance, and draws a **settlement glyph** — tier only, no name —
wherever anybody lives. What it does *not* have is anything the server alone
knows: depletion timers, who is mining where, what a hex would pay. That is the
whole of the difference between scouted ground and the rest.

Three consequences, all deliberate:

1. **The live-state query is a disc of seven tiles** — the hex underfoot and
   its six neighbours — rather than the several hundred that reach-as-sight
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
narrower than the cell and centred in it — so two neighbouring sites cannot
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
number the player picks never multiplies the effect), and what the line can make
at all (`unlock`). The two effects a craft bench owns — a rolled option and a
starting durability — belong to an *object*, and a run makes a material, which
has neither.

---

## 7. Character

**One character per wallet. Soulbound (non-transferable).** Gear and land are the tradeable
things, not the character — this prevents power-account selling.

### 7.1 Level
Level unlocks **capacity, not power**: access to higher-tier hexes and dungeon
floors. A whale can out-scale logistics but never out-damage a grinder.

> **Action points are gone.** AP gated a trip on a pool that refilled on a
> clock, which put a second timer underneath the one the trip already runs. A
> limit on how much can be done in a day will come back, but it is not going to
> be that one, so the pool and its columns were removed rather than left
> dormant and half-true. Nothing currently rations how many trips a day a
> character may take — that is a known gap, not an oversight.

Three things are deliberately *not* on that list. Travel range, because there is
no reach to unlock (§5.6). Sight, because the only thing that widens the eye is
having walked (§7.5). And the **bag** (§7.6), for the same reason as sight:
carrying capacity used to be a level reward, which made it a problem that solved
itself — by the time it mattered you had outgrown it. It is the Explorer's now,
and walking is the one reward in the game that cannot be bought.

### 7.2 Gathering lines (5, one per material line)
Woodcutting · Mining · Hunting · Quarrying · Harvesting

Each reduces trip time / boosts yield **for its own material only**. XP comes from mining
that material *and* from presence during its processing — so no single grind path maxes a
tree alone.

Cap total points so characters are meaningfully specialized, not universally strong.

Each line also has its **own tool slot** (§8.0) — axe, pickaxe, bow, hammer, sickle.
Tools are not the specialisation lever: all five can be equipped at once and every line
offers the same ladder. The skill point cap is the lever, and it is the only one.

### 7.3 Mining time formula
```
trip_time = clamp(base_tile_time - skill_reduction - equipment_reduction, 30min, 60min)
```
- `base_tile_time`: 30–60 min depending on tile
- `skill_reduction`: max **20 min** at maxed relevant skill
- `equipment_reduction`: max **10 min** at best-in-slot

Max total reduction = 30 min, so a 60-min tile at BiS lands exactly on the 30-min floor.

**The floor clamp is mandatory and must be in the formula from day one.** Without it, any
future buff/equipment tier creates a sub-30 or zero-time exploit.

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
| Shieldbearer | battle | defence | fighting with a shield (§9.5) |
| Swordhand | battle | balance | fighting with a sword (§9.5) |
| Runecaster | battle | offence | fighting with a focus (§9.5) |

**Processing is not crafting, and the split is the input.** A craft bench spends
refined stock on an object; a processing line makes the stock. So the five
processing trees deal in the three things a §6 run actually has — how long it
takes, how much raw it eats, how much refined comes off it — and in what the
line can make at all. Five of them rather than one because §6 is already a
five-line structure: a village runs one of the five, a city two, a capital all
five.

Only one stat applies to a run, which makes these the most capability-heavy
trees in the game: eleven `unlock` nodes against twelve `stat` ones. That is the
right shape for the work — a processing line grows by learning what it can make,
not by shaving another percent off the clock.

**A processing job's level comes from finished runs, paid on what came off the
bench.** A bigger batch is more work and teaches more; a run walked away from
teaches nothing, exactly as an abandoned trip pays nothing (§11.1).

**A gathering job's level is not a new number.** It is the §7.2 skill level that
line has always had — the same figure that takes up to 20 minutes off a trip
(§7.3). One number, so there is never a second opinion about how good a
woodcutter someone is, and the five gathering trees are playable the moment they
exist rather than waiting on a new grind.

That makes gathering the one kind whose level *does* grant power. It always did;
§7.4.1 below is about the levels this system introduces.

**Gathering and processing `stat` nodes are line-locked**, exactly as tools are
(§8 rule 1): a Woodcutting node counts in a forest and nowhere else, and a
Sawyer's speed counts at a saw pit and nowhere else. Without that, taking three
gathering trees would stack yield on every trip at once, which is the shortcut
the line-locked tool ladder exists to close. Craft and battle nodes are not
line-locked; they have no line.

**A forest and a saw pit are two different pieces of work on the same word**, so
the lock is on the pair rather than the line: a Woodcutting node pays out on a
felling trip and a Sawyer node at the pit, and neither reaches the other. This
is the one place where the *action* being costed and the *material line* under
it are two things rather than one.

**The three battle jobs level on the road** (§9.5), and on nothing else. Which
of them earns the XP is decided by the **weapon family** in the `weapon` slot —
shield, sword or focus — so the job you level is the way you actually fight.
They must never be given a stand-in XP source from gathering; a battle job that
levels by digging would make combat optional.

*(They were dormant until map combat existed, which is what §9.5 was written to
fix. Dungeon floors will feed the same three jobs when they are designed.)*

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
a matter of judgement. A node's effect must be one of these, and nothing else:

| Effect | What it does | Cap |
|---|---|---|
| `unlock` | Makes a recipe craftable at all | none — capability, not power |
| `stat` | A `StatKey` percentage | **feeds the same aggregate and clamp as gear, options and buffs** |
| `craftOption` | Chance of an extra rolled option (§8.0.1) on what you make | `SKILL_OPTION_CHANCE_CAP` |
| `craftDurability` | Higher starting max durability on what you make | `SKILL_DURABILITY_CAP` |
| `costReduction` | Fewer inputs per craft | `SKILL_COST_REDUCTION_CAP` |
| `batch` | More output per craft or processing run | `SKILL_BATCH_CAP` |
| `sight` | Whole hexes of sight (§5.6), on top of the base one | `SKILL_SIGHT_CAP` |
| `bagUnits` | Units the bag holds (§7.6), on top of the flat base | `SKILL_BAG_UNITS_CAP` (+80) |
| `bagRows` | Distinct things the bag holds, on top of the flat base | `SKILL_BAG_ROWS_CAP` (+20) |

**A skill point can never take a stat past +15%.** `stat` nodes feed the very same
sum and clamp as equipment, rolled options and potions (§8.1 rule 1). What they
buy is a *different road to the ceiling*, not a higher one — which is the point:
a player deep in a tree reaches the same cap as one in full epic gear, and §8.1
rule 4 stays true.

The caps that are not the stat ceiling exist to protect §11 and §5.6, not §8.
`costReduction` and `batch` both thin a materials sink, and `craftDurability`
thins the repair sink. Left uncapped, a maxed crafter would quietly switch off
the loss the whole economy is balanced around.

`bagUnits` and `bagRows` are the same argument in counts rather than
percentages. The bag is the pressure that turns a haul into a decision (§7.6),
and an uncapped tree could carry enough that the decision never arrives — which
would switch off the selling, processing and dumping §11.1 runs on.

`sight` is the odd one, and its cap is the sharpest because sight is the
radius of the map query — cost grows as the *square* of it. One hex is seven
tiles, two is nineteen, three is thirty-seven, and ten would be three hundred
and thirty-one. `SKILL_SIGHT_CAP` is a query budget rather than a balance one,
and it is what lets sight be a reward at all instead of a scanner handed to
anyone patient enough to walk. It is also a **count, not a
percentage**, so like `unlock` it has nothing to do with the stat ceiling. It
is capability.

**`unlock` is the one with no cap, and that is deliberate** — it is where the
trees grow. New materials, new biome variants and new equipment tiers arrive as
new unlock nodes rather than as bigger numbers, so the trees can keep expanding
without ever touching the ceiling.

#### 7.4.4 Pacing — six months, and it does not move

The target is **level 100 after roughly 180 days of unbroken play at game speed
1**. Measured against real income — a career averages about 1,080 character XP a
day, from 28 trips a day early and 48 late — that sizes the curve at:

```
xp_for_level(L) = round(40 + 2.1 * L^1.7)     // ~197,000 XP total, ~182 days
job_xp_for_level(L) = round(17 * L^1.5)       // ~32,000 XP, ~1,600 crafts to job 30
```

The `40` floor is there so the first level costs about three mining trips rather
than half of one.

**XP is never passed through `Balance::scaled()`, and must never be.** Timers
compress with `GAME_TIME_SCALE`; XP does not. A trip pays the same XP at speed 1
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
| Cost | **Nothing — granted at its job level** | 1 skill point each |
| Levels from | **Hexes crossed** | Bench work, or raiding |
| Pays in | **Capability only — never a stat** | Stats, unlocks, craft effects |
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

**Its nodes are granted, never bought, and that is only safe because there is
exactly one such tree.** A second free tree would not be a new job, it would be
a hole in the 100-point cap (§7.4.1). Granted nodes are **derived from the job
level and never stored** — a row would be a second place for "do you have this
yet" to be answered, and the two would eventually disagree.

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
| **Rows** | **30.** How many *distinct* things that is: a stack is one row whether it holds 1 or 100, and two axes are two rows because gear does not stack. Roomy against a catalogue of 29 materials and 5 draughts, on purpose — see below. | `Balance::BAG_ROWS` |

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

- **Rarity** — six rungs, sets the power ceiling, the colour, and where the thing
  can be made.
- **Mintable** — whether it may be converted into an NFT and withdrawn from the
  game (§3.3). Not implied by rarity, and not a property the item has while it
  is in a bag: everything in a bag is in play, and everything in play can be
  destroyed (§8.2).

### 8.0 The rarity ladder

| Rarity | Colour | Stat ceiling | Option rolls | Bench | Shop | Mintable |
|---|---|---|---|---|---|---|
| Common | grey | +3% | 0 | village | village+ | no |
| Uncommon | green | +5% | 0–1 | city | city+ | no |
| Rare | blue | +8% | 1 | capital | never | no |
| Epic | violet | +11% | 2 | capital | never | **yes** |
| Legendary | gold | +14% | 3 | guild hall | never | **yes** |
| Unique | ember | +15% | 3 + fixed perk | never | never | no — soulbound |

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

Higher rarities roll extra stat lines when made or bought, so two of the same item
are never identical. Counts are in the table above.

- Rolled **server-side** from a seed, like every other outcome.
- Small values (+1–3%), drawn from the same `StatKey` pool.
- **Options are inside the ceiling, not on top of it.** They feed the same
  aggregate and clamp as the base stat. An option that breached the cap would
  reintroduce pay-to-win through the back door.
- The capital shop sometimes stocks pre-rolled goods. Villages and cities never do.

**A worn line may name one gathering line, and is worth more for it.** Armor,
boots and gloves work all five lines at once, so a flat *+2% yield* on them is
five bonuses in a coat. A roll may instead come out **scoped** — *+4% mining
yield*, *+3% off woodcutting time* — worth **+2–5%** on the line it names and
**nothing on the other four**. Same ceiling, same clamp, same aggregate: what a
narrower line buys is a steeper climb to +15%, never a higher one.

The gap between the two ranges is the whole rule. Without it a scoped roll would
be strictly the worse outcome and the pool would read as a bad-luck table; with
it, *+2% everywhere* against *+4% mining* is a real choice for a prospector who
knows which line they actually work. It is the same argument §8.5 makes for
scoping potions — seventy flat draughts would be a power ladder you can drink.

**Only the two trip stats scope**, `yield` and `tripReduction`: `travelSpeed`
has no line to belong to, and processing is scoped by the recipe already.

**Tools never carry a scope.** An axe is line-locked by its slot (§8 rule 1), so
its yield is already woodcutting's yield — storing that twice would only be
somewhere for the two to disagree. The screen still says *woodcutting yield* on
an axe; it reads the line back off the slot.

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
   before every trip is friction, not a decision. The interesting decision is which
   lines you *invest* in — that is already capped by §7.2 skill points.
4. **Every line gets the same ladder** — village basic, city basic, crafted starter,
   crafted, NFT — and the same ceiling. Specialisation must come from the skill point
   cap, never from one line having better tools on offer than another.
5. **`Weapon` is combat only and never gathers.** Combat gear must not be able to
   stand in for a gathering tool, or fighting becomes a shortcut around the mining ladder.
   One slot holds **three families** — shield, sword and focus — and the family decides
   which battle job levels (§9.5.4). It carries flat `attack` and `defence`, which are
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
- Discard returns a **small % salvage** — clears inventory bloat, gives obsolete gear an exit
- **Sell it back for gold** — half the shelf price, multiplied by the fraction of
  durability left. A piece has three exits and they are deliberately different:
  repair *keeps* it, salvage returns a fraction of what went *into* it, and a
  sale returns gold scaled by what is *left* of it.

  Four refusals, each closing a hole rather than being a nicety. Not away from a
  settlement — the trader is an NPC who stands somewhere (§6). Not off your own
  belt: a sale is a trade, and losing the worn tool to a mistap is worse than
  losing one out of the pack, so stow it first. Not what the trader does not
  stock — gold buys the bottom two rungs and never the top (§3.2), so a crafted
  or NFT piece has no shelf price to halve and salvage is its exit. And not for
  nothing: a piece worn past the point where half its price still rounds to a
  coin is refused rather than taken for zero.

  **Two numbers hold this in place and neither is optional.** The round trip must
  lose money, or a trader is a gold faucet with no work in it. And repairing must
  stay cheaper than selling-and-rebuying at *every* wear level, or the largest
  sink in the game (§11.1) quietly switches itself off. Both are pinned by tests,
  because the resale rate and the NPC's repair rate are set independently and
  nothing else would keep them in the right order.
- **Nothing is destroyed without warning.** Durability is on the item, and any
  action that could take the last of it says so first — the fight preview
  (§9.5.5) and the hex preview alike. An idle game may take something expensive
  from a player; it may never take it by surprise.
- **It reaches the trip, not only the fight.** Mining and hunting wear the
  line's tool, and at zero that tool is gone the same way a weapon is. The
  warning is line-locked exactly as the wear is: the axe on your back is not at
  risk while you are down a mine.
- Nothing minted can be destroyed, because minting takes it out of the game
  (§3.3). Destruction reaches every rung that is actually *in* a bag.
- **Tuning decision still open:** repair cost must be cheaper than crafting new, but not
  dramatically so, or the crafting-materials sink stalls at endgame.

### 8.3 Example recipes (starting values)

`travelSpeed` **divides the travel clock** — +8% boots really are 8% faster over
any distance. It used to buy hexes of reach, and §5.6 removed reach, so the stat
now does the thing it is named after.

Worn gear:
```
Leather Armor     = 6 Leather + 2 Cloth                        → +6% trip reduction
Reinforced Boots  = 4 Cut Stone + 3 Leather                    → +8% travel speed
Work Gloves       = 3 Cloth + 2 Planks                         → +4% processing speed
Ironwood Armor    = 3 Ironwood + 2 Silkweave + 1 Shard         → +12% trip    [NFT]
Beastfang Boots   = 2 Beastfang + 1 Obsidian + 1 Relic         → +15% travel  [NFT]
```

Gathering tools, §8.0 — one ladder, repeated per line. Yield only, and only on
that line. The crafted starter is single-line on purpose: it is what a player can
build straight off the opening arc's first processing run (§12 step 6).

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

| Line | Village +3% | City +5% | Starter +4% | Crafted +6% | NFT +12% |
|---|---|---|---|---|---|
| Woodcutting | Stone Axe | Iron Hatchet | Hewn Axe | Ironbound Axe | Ironwood Axe |
| Mining | Chipped Pick | Miner's Pick | Wood Pickaxe | Iron Pickaxe | Mythril Pickaxe |
| Hunting | Crude Bow | Recurve Bow | Shortbow | Sinew Longbow | Beastfang Bow |
| Quarrying | Stone Mallet | Iron Sledge | Stone Maul | Banded Sledge | Obsidian Sledge |
| Harvesting | Bent Sickle | Steel Sickle | Reed Sickle | Toothed Sickle | Silkweave Sickle |

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
kitting out a second line is a cross-map project, not a shopping trip — the same
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

**One craft per settlement.** A bench is a place, not a queue you can stack five
deep — and the real limit is not that number, it is the walking.

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
the three benches are their own building and queue against nobody.

### 8.5 Consumables — potions and buffs

- **Stackable, never equipped.** They live in their own table, not with
  equipment: a potion has no durability and no slot, so a row per object would
  be wrong.
- Using one spends it and **arms one action** with a **charge** on one stat. A
  potion is not a flat stat increase; it is bought for a specific thing you do.
  The actions are the five §7.2 gathering lines plus `travel`, `processing` and
  `battle` (§9.5.8) — the last of them the only place `power` and `defence` are
  worth drinking for.
- **A charge waits, and taking the action spends it** — the first woodcutting
  trip after the draught, whenever that is. It does not run on a clock.

  *(It used to. A 30-minute window meant a woodcutting draught drunk in the
  mountains was simply thrown away, which made scoping a trap rather than a
  choice: the potion you had bought for one line could only be drunk while
  already standing on it. Waiting is what turns the scope back into a decision.)*
- **Being spent is the sink** (§11.1). Nothing here may ever be permanent — a
  permanent effect only accumulates, which the north star forbids. A charge is
  not permanent: it survives until it pays out exactly once.
- **As many different effects at once as you like; the same effect never twice.**
  A woodcutting draught and a mining draught are *different things you are better
  at*, so both may be held, and so may a road tonic on top. Two draughts on the
  same stat and the same action are one thing twice.
- **When they are the same thing twice, the stronger wins.** Charges on a stat
  contribute their **highest** value, never their sum — so no combination of
  potions is a way of buying the ceiling in instalments, and a `global` charge
  cannot quietly double up with a line-scoped one on the same trip.
- **A weaker draught is refused before the flask is opened.** Pouring a common
  draught on top of a legendary philtre would be paid for and never felt, and an
  idle game must not take something away for nothing. The refusal reads as *you
  already have better*, and the flask stays in the bag.
- One charge per (stat, action) is still enforced by a unique index rather than
  by code, and that index is also the cap on hoarding: a cellar of seventy
  draughts is still four stats across eight actions once drunk. The ceiling on
  any one action is exactly what it was, because the clamp applies to that
  action's aggregate alone.
- **Seventy of them, fourteen a rung**, across the five rungs a bench can
  reach: yield and trip time on each of the five lines, plus the road, the
  bench, and the fight — `power` and `defence` scoped to `battle` (§9.5.8).
  Scoping is what makes that many potions safe; seventy flat stat boosts would
  be a power ladder you can drink.
- **Recipes get shorter as they climb.** The number of *different* materials a
  potion wants never rises with rarity: a common draught is a muddle of four
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
  each: the glyph is the *action* it is waiting on, the colour is the *stat* it
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

It happens in two places. **Dungeons** (§9.1–§9.4) are the deliberate trip: you
kit up, spend a charge, and go. **The road** (§9.5) is the other one, and it
comes to you — packs stand on hexes and stop travellers. Dungeons are the ladder
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
is the only place a player learns what attack, defence and durability wear cost
them, and it costs a walk rather than a crafted charge to find out.

### 9.5 Map combat — a pack, a pin, and one roll

Monsters stand on hexes. They stop travellers, they block the ground they are
standing on, and they are settled in **one action** with **no health bar on
either side**.

This is the section that wakes four things that have been defined and dead since
the start: the empty **`weapon` slot** (§8.0 rule 5), the three **battle jobs**
(§7.4), the **`power` and `defence`** stats, and §3.2's listed **monster gold**,
which nothing currently produces.

**There is no elemental cycle in v1.** Eight monsters with explicit attack and
defence already make "am I built for this one" a real question, and a matchup
table on top would be a second system answering it. §14 keeps it as a later
layer, where it can tie into Shard types.

#### 9.5.1 Packs — derived, cleared, and never farmed

Same machinery as the herd (§5.5), so a pack that nobody has met costs no
storage: a **time-bucketed hash** of `(col, row, bucket)`, with a **per-hex
offset folded into the bucket** so the world does not blink all at once every
two hours.

| Ring | Spawn chance per hex per bucket | Pool |
|---|---|---|
| Outer (villages) | 0.02 — the road is nearly safe | 2 |
| Mid (cities) | 0.10 | 4 |
| Inner (capitals) | 0.18 | 4 |
| Centre (dungeon mouths) | 0.22 | 4 |

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

#### 9.5.2 The roster — eight monsters, four overlapping pools

| Ring | Pool | New here | Carried in from |
|---|---|---|---|
| Outer | 2 | 2 | — |
| Mid | 4 | 2 | outer |
| Inner | 4 | 2 | mid |
| Centre | 4 | 2 | inner |

Eight in total, and the overlap is the design: walking inward you meet two you
know how to fight and two you do not, so every ring is **legible and dangerous
at the same time**. It also gives §5.2's barren centre something to be, on the
walk to a dungeon mouth.

Each carries its own `attack`, `defence` and a profile — a brute is high attack
and low defence, a carapace the reverse, a fast one is middling in both and
wears a weapon harder. The profile is what a player reads, not a level number.

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

#### 9.5.4 Attack and defence — flat numbers, not the percentage stats

Combat needs a base, and §8.1's ceiling makes percentages useless as one: a
±15% swing cannot decide a fight on its own. So gear carries **two flat values**
alongside its work stat.

| | Where it comes from |
|---|---|
| **Weapon** | the `weapon` slot, by rarity rung, split by family |
| **Armor · boots · gloves** | a smaller pair each, beside their work stat |
| **Battle job level** | added directly — the proof you have fought |
| **`power` / `defence`** | percentages **multiplying** the gear total, inside the same +15% ceiling |

**One weapon slot, three families**, and the family is your class — the three
§7.4 already names:

| Family | Job | Profile |
|---|---|---|
| Shield | Shieldbearer | defence-heavy |
| Sword | Swordhand | balanced |
| Focus | Runecaster | attack-heavy |

One slot rather than three, because you fight with one thing. The five
**gathering** tools are all equipped at once precisely because they never
compete (§8 rule 3); a weapon competes with itself. Which family you carry is
what decides **which battle job earns the XP** — §7.4 has always said a battle
job levels by fighting with a shield, a sword or a focus, and this is what
finally gives that sentence something to count.

**Armor is one set with two axes, not a second wardrobe.** Every armor, boots
and gloves item gains attack and defence next to its work stat, and
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

**Work gear reaches the contested ring; the centre asks for battle gear.** A
full legendary *work* set handles a tier-3 pack comfortably — around 80% — and
is a coin flip at best against the centre's two, around a third, while a
legendary battle set clears them at roughly three in four. That is the intended
shape of the last step inward: it is a kit decision rather than a level one, and
it is the first time in the game that giving up trip time and travel speed is
the right call.

**Every worn piece carries the pair, at every rung including the top two.** The
legendary and unique work pieces were written before §9.5.4 and had none, which
made a Wardencoat worth less in a fight than a common travel cloak. They are far
under the battle piece of the same rarity — 1/9 against 2/18 for armor — and
that gap *is* the trade above, rather than an accident of tuning.

#### 9.5.5 One roll, and it is a margin

```
strike = myAttack  - itsDefence
hold   = myDefence - itsAttack
margin = (strike + hold) / 2

odds   = clamp(0.5 + margin / (2 * BAND), 0.05, 0.95)   // BAND = 20
win    if U(0, 1) < odds                                // U seeded, server-side
```

**The band is what makes it a decision.** A straight comparison decides every
fight before it is tapped — scout the number and you either always win or never
engage — and there is nothing left to choose. A band turns it into a *known
risk*, and the 5%/95% clamp means never certain and never hopeless, which is the
same instinct as §7.3's floor.

**`BATTLE_BAND` is the one number that decides whether any of that is true.** It
started at 10 and had to be 20. Monster stats are twenty to forty points apart
(§9.5.2), so at 10 every matchup saturated on a clamp and the band collapsed
back into the straight comparison it exists to replace: 95% or 5%, with almost
nothing between. At 20 a rung beats its own tier around 60–80%, is a real risk
one tier up at 30–50%, and is properly outmatched two tiers up. Widen it further
and the ladder flattens the other way — at 40 a common kit is a third of the way
to killing a centre monster, which makes the ladder decorative.

**The roll is against the odds, not against the margin**, and at the edges those
are two different games. Adding U(−10, +10) to a margin of +15 wins every throw,
while the number the player was shown says 95% — so the clamp would be a claim
the die does not honour. The preview is a promise, which makes the number on it
the one the fight has to be settled by.

**A fight takes time, and you are held on that hex while it runs.** It used to
be instant, which made a pack a button rather than an engagement: tap, read the
plate, walk on. The clock is short — three minutes plus two a tier, so five at
the treeline and eleven in the centre, well under the cheapest bench run (§8.4)
because the pin (§9.5.3) is already holding you and a long clock on top would
make one pack a lost afternoon.

Three things follow, and all three are the same rule the rest of the game runs
on:

- **The roll happens when you close, and is stored.** Exactly as a trip records
  the material its tool could reach: the kit that took the fight is the kit that
  fought it, and swapping to a better sword while the timer runs buys nothing.
- **The pack is spent on engagement, not on resolution.** While you are swinging
  at it there is no pack for anybody else either.
- **A fight cannot be abandoned.** §9.5.3 offers exactly two exits from a pack
  and once the first is chosen there is no third — dropping it would be a way to
  duck a loss, and a loss is a death (§9.5.7).

**The odds are shown before you commit**, off a preview like `previewGather`,
alongside the monster's numbers and any warning about gear that will not survive
(§8.2). Server-computed and server-seeded, like every other outcome (§16).

**What a loss costs is printed beside them, every time.** Not as a condition
that sometimes applies — losing is dying (§9.5.7), so it is the terms of the
fight rather than a hazard to check for. The odds are half the decision; what
they are odds *of* is the other half.

#### 9.5.6 Durability wear *is* the combat system

There is no health, so the fight's cost lands on the gear, and it scales with
how badly matched you were. Two wear rolls per fight, both driven by a gap.

**The weapon wears on the gap to their defence.**

```
gap  = itsDefence - myAttack                       // positive: outclassed
wear = (WEAR_BASE + max(0, gap) * WEAR_PER_GAP) * (won ? 1.0 : 1.5)
       capped at 15% of maxDurability
```

`WEAR_BASE` 2, `WEAR_PER_GAP` **0.4**. A matched weapon costs 2 a fight; the
same weapon ten points under a carapace costs 6, and 9 if it loses. Hitting a
wall chips the blade — which means **bringing the wrong class is expensive even
when you win**, and that is the entire reason the profiles in §9.5.2 matter.

The 15% cap is not optional now that zero is fatal (§8.2): without it one
hopeless fight snaps a legendary outright, and the warning in §9.5.5 would be
the only thing standing between a player and losing a week of work to a mistap.

**One random worn piece takes the hit.**

```
piece  = seededPick([armor, boots, gloves])        // an empty slot protects nothing
excess = itsAttack - piece.defence
wear   = WEAR_BASE + max(0, excess) * WEAR_PER_EXCESS
```

Random rather than spread, for two reasons: the repair bills **stagger** instead
of arriving together, and a weak piece is eventually **found out** rather than
never. An empty slot takes no wear — and contributed nothing to `hold`, so going
without is cheap and loses.

#### 9.5.7 Death — when nothing was left to take the hit

Death is not a third outcome. It is what losing *is*:

> **A loss is a death.** Whatever you were wearing when you took the fight.

*(It used to be narrow — a loss only killed you when nothing absorbed it, no
armor at all or the piece that would have taken the hit going with it. That made
the interesting question "am I wearing anything" rather than "should I take this
fight", and the second one is what the odds exist to be read for. **Armor still
decides whether you lose** — defence feeds the hold and the hold is half the
margin — it simply no longer decides what losing costs.)*

What it costs:

1. You wake at the **nearest settlement**. The walk back is the first bill, and
   at ten minutes a hex it is a real one.
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
and "random row" is no defence at all:

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
| **Looted gear** | the kit the monster was using, at **5–50% durability**, rarity capped at **rare** |
| **Battle job XP** | on a win only (§9.5.3) |
| **Never** | Tier 3, Tier 4, or anything mintable |

**The rarity cap on loot is a §2 rule, not a tuning value.** Epic is where gear
becomes mintable (§8.0), and a monster that drops one is precisely the
grind→NFT faucet the threat model exists to close.

**Harder packs roll better options, not better rarity.** A tougher monster
grants **extra option slots** (§8.0.1) on what it drops — the same mechanism the
capital bazaar already uses — so a centre-ring kill can hand you a rare carrying
three lines, and never an epic.

Battle draughts come off the ichor line and want a new **`battle` buff scope**
(§8.5) moving `power` and `defence`: twelve more on the shelf, two a rung.

---

## 10. Guilds

Scoped to v1: **raid co-op + shared consumable pool + feature ownership.** Territory war,
guild-vs-guild, and politics come later.

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
| `gather` | units that **landed in the bag** off a trip | a material, or any |
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
(§7.6) pointed the other way, and the colour is the whole distinction: ember is
what a *problem* looks like — a full bag, a broken tool — and a reward wearing it
reads as an alarm going off over good news.

Gold is taken too, and deliberately not reused for this: gold is the currency,
it is already on every reward figure in the panel, and spending it on status as
well would make "20g" and "done" the same colour saying two different things.

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

### 13.1 Icon system
| Axis | Encoding |
|---|---|
| Equipment slot | One base silhouette (axe, pickaxe, bow, hammer, sickle, armor, boots, gloves, weapon) |
| Rarity | Six colours: grey → green → blue → violet → gold → ember. Owns the hex frame and the glow; ornamentation starts at rare. |
| Material | Accent stays on the body, so "what it is made of" and "how good it is" never compete for the same colour |

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
- **Beyond sight a tile gets its terrain colour and, if anybody lives there, a
  settlement glyph — tier only, no name.** Nothing else: no props, no slot pips,
  no labels. The glyph is what makes deciding to walk somewhere possible; the
  absence of everything else is what makes arriving worth something.
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
Depleted tiles use a darker/desaturated variant of their **own biome color**, never grey —
the land is drained, not dead, and it will regrow.

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