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
| Soft caps | Storage caps, per-wallet rare-material caps, AP regen limits. A bot with 1000 wallets gets 1000× capped, non-liquid output. |
| Server authority | All timers are **server-side**. Client never asserts elapsed time. |

**Rule for any new feature:** if it creates a path from "grind time" to "external value,"
it's wrong. Route it through a cap, a decay, or a market chokepoint.

---

## 3. Three-currency model

The three currencies are strictly separated. No backdoor converts one into another.

### 3.1 Resources (raw / refined / rare)
- Mined from hex tiles, biome-locked
- **Non-tradeable** between players
- Decay when over storage cap
- Consumed by: crafting, repair, building upgrades, raid charges

### 3.2 Gold
- **In-game only**, NPC-facing, freely inflatable/deflatable by design (no on-chain consequence)
- Faucets: selling excess resources to NPC (deliberately bad rate), monster gold drops, quests/dailies
- Sinks: NPC repair, basic equipment, settlement upgrades, **guild capital bidding** (largest sink)
- Buys **common and uncommon only** (§8.0) — never convertible to NFT

### 3.3 NFT
- The *only* externally tradeable value
- Categories: bundles, rare materials, top-tier equipment, cosmetics
- Sourced from: marketplace purchase, or crafting with Tier 3 + Tier 4 materials
- **Never** a grind reward

---

## 4. Materials (20 total, plus 5 scrap)

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

Scrap sits outside the 20 deliberately: it never enters the economy the §11 sinks
have to balance.

### Tier 1 — Raw (5, biome-locked, decays over cap)
| Material | Biome |
|---|---|
| Wood | Forest |
| Iron ore | Mountain |
| Pelt | Plains/Tundra |
| Stone | Badlands |
| Fiber | Grassland |

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
- Hex grid, ~5000×5000
- **Exactly 2 mining slots per hex.** When both are full, the tile is closed to others.
- Tiles are **depletable**, then **regrow after ~9h** (tune). Depleted tiles keep their
  biome color (drained, not dead) and show remnant/sapling props.

### 5.2 Ring layout (concentric, drives generation)
| Ring | Contents |
|---|---|
| Outer | Villages (dense), safe mining (low yield), most spawns |
| Mid | Cities, mixed safe/contested mining |
| Inner | Contested PvP-yield mining, **rare materials spawn here** |
| Center (capital ring) | **Barren of resources.** Capitals + dungeon entrances only. |

The two opposing pulls (outward for resources, inward for processing + dungeons)
force constant traffic through the contested middle ring. This is intentional.

### 5.3 Biomes
Clustered regions (Voronoi-style from seed points), **not** random noise — players need a
mentally navigable map. Rare-material biome variants sit inside/near the PvP ring.

### 5.4 Player spawning
- **Auto-assigned**, not player-chosen (prevents spot-sniping and landgrabbing)
- Placement favors **under-populated regions** by local density (hexes per active wallet
  in a radius). Fills outward naturally.

### 5.5 Hunting grounds
Not a tile type. **Temporary herd markers** spawn on open Plains/Grassland hexes and decay
after ~4h. Yields Pelt (Tier 1) + small Essence (Tier 4) — the only activity bridging the
mining and raid material tracks. No party, no raid charge, just AP + time.

---

## 6. Settlements — shared infrastructure, NOT per-player bases

**Critical:** settlements are *shared world locations*, not personal bases. Players do not
place or own buildings. This keeps map space for resources and removes base-building
cognitive load from an idle game.

| Tier | Processing lines | Location | Notes |
|---|---|---|---|
| Village | 1 of 5 | Outer ring | Slowest, cheapest |
| City | 2 of 5 | Mid ring | Moderate |
| Capital | All 5 | Center ring | Fastest, most expensive, adjacent to dungeons |

Village count > City count > Capital count. This is a **cost curve outcome**, not a map-slot
system — no extra implementation needed, just tune upgrade costs.

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

---

## 7. Character

**One character per wallet. Soulbound (non-transferable).** Gear and land are the tradeable
things, not the character — this prevents power-account selling.

### 7.1 Level
Level unlocks **capacity, not power**: AP pool, storage cap, travel range, access to
higher-tier hexes and dungeon floors. A whale can out-scale logistics but never out-damage
a grinder.

### 7.2 Skill trees (5, one per material line)
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

---

## 8. Equipment

Equipment has **two axes that are not the same thing**, and conflating them is the
easiest way to break the economy:

- **Rarity** — six rungs, sets the power ceiling, the colour, and where the thing
  can be made.
- **Tradeable** — whether it is an NFT (§3.3). Not implied by rarity.

### 8.0 The rarity ladder

| Rarity | Colour | Stat ceiling | Option rolls | Bench | Shop | Tradeable |
|---|---|---|---|---|---|---|
| Common | grey | +3% | 0 | village | village+ | no |
| Uncommon | green | +5% | 0–1 | city | city+ | no |
| Rare | blue | +8% | 1 | capital | never | no |
| Epic | violet | +11% | 2 | capital | never | **yes** |
| Legendary | gold | +14% | 3 | guild hall | never | **yes** |
| Unique | ember | +15% | 3 + fixed perk | never | never | no — soulbound |

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
Tradeability stops at legendary. Unique is prestige, not liquidity.

### 8.0.1 Options — rolled bonus lines

Higher rarities roll extra stat lines when made or bought, so two of the same item
are never identical. Counts are in the table above.

- Rolled **server-side** from a seed, like every other outcome.
- Small values (+1–3%), drawn from the same `StatKey` pool.
- **Options are inside the ceiling, not on top of it.** They feed the same
  aggregate and clamp as the base stat. An option that breached the cap would
  reintroduce pay-to-win through the back door.
- The capital shop sometimes stocks pre-rolled goods. Villages and cities never do.

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
5. **`Weapon` is raid combat only and never gathers.** Combat gear must not be able to
   stand in for a gathering tool, or raiding becomes a shortcut around the mining ladder.
   *(Raid combat is not yet designed — see §14.2. The slot exists and stays empty.)*

### 8.1 Anti-imbalance rules (all mandatory)
1. **One global % ceiling per stat**, +15%, and rarity climbs toward it. Nothing may
   pass it: not a rarity, not an option roll, not a buff, not a future tier. The
   ceiling is the load-bearing rule — rarity is only how far up you have climbed.
2. **Diminishing returns on stacking** — a 2nd yield item gives less than the 1st. Blocks
   buying 3 identical bundles for linear scaling.
3. **Durability decays with use** (raiding drains faster than mining). Equipment is never
   "buy once, dominate forever."
4. The gap from common to unique is **12 points, not an order of magnitude**, and every
   rarity below unique is reachable by crafting without spending. That is what keeps F2P
   viable — and F2P viability is what sustains the active playerbase that drives NFT
   demand. If a change widens that gap, it is wrong.

### 8.2 Repair vs discard
- At 0 durability equipment becomes **broken/inactive**, not destroyed
- Repair costs refined + raid materials, scaled to rarity tier
- Discard returns a **small % salvage** — clears inventory bloat, gives obsolete gear an exit
- **Tuning decision still open:** repair cost must be cheaper than crafting new, but not
  dramatically so, or the crafting-materials sink stalls at endgame.

### 8.3 Example recipes (starting values)

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
build straight off the tutorial's first processing run (§12 step 7).

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
| Weapon | axe · pickaxe · bow · hammer · sickle · weapon | The five gathering tools plus the dormant raid slot |
| Armor | armor · boots · gloves | Worn gear, never line-locked |
| Consumable | *none* | Having no slot is exactly what makes it the third category |

### 8.5 Consumables — potions and buffs

- **Stackable, never equipped.** They live in their own table, not with
  equipment: a potion has no durability and no slot, so a row per object would
  be wrong.
- Using one spends it and starts a **timed buff** on one stat.
- **Buffs expire, and that expiry is the sink** (§11.1). Nothing here may ever be
  permanent — a permanent effect only accumulates, which the north star forbids.
- **One buff per stat.** A second of the same kind refreshes the clock rather
  than stacking, or a player could bank an afternoon of potions into one window.
- Buffs feed the same aggregate as gear and are **clamped by the same ceiling**.
  A potion that could push a stat past `STAT_CEILING` would be a power ladder
  you can drink.
- Expiry is an absolute server-clock deadline, compared and never ticked — an
  hour offline and an hour idle must produce the same result (§16).

---

## 9. Dungeons (PvE raiding)

Raiding is **PvE only** — no player-vs-player combat. This removes the snowball problem
(winners farming losers' gear) entirely, leaving only loot-table tuning.

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
`mine → hunt → dungeon floors 1–3 → deep floors → boss`
Each step introduces exactly **one** new mechanic (combat, then charges, then parties).

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
- Storage overflow decay on raw resources
- Building/feature degradation requiring refined-material repair
- Equipment durability drain from mining and raiding
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

## 12. Onboarding / tutorial

The tutorial is the **actual game loop**, not scripted fake mechanics — nothing to unlearn.

1. Collect branches in a Forest hex → teaches hex mining, biome locking, and §4.0: bare hands get scrap
2. Sell branches for gold → teaches NPC shop, gold faucet (1g a branch, deliberately poor)
3. Buy a Stone Axe → teaches gold-tier equipment, and that the axe is the *forest* tool (§8.0)
4. Mine wood with axe → the same hex now gives wood, not branches. This is the payoff
5. Sell more wood → reinforces gold loop
6. Process wood → planks → teaches processing + presence bonus
7. Craft a Hewn Axe → teaches material crafting, gold→crafted tier ladder
8. Mine with the new axe → loop closes with visible improvement
9. Sell some, process some → teaches the **sell-vs-process decision** players make forever

End on a soft hook toward the contested ring ("the forest edge holds rarer wood, but it's
contested") so depth is signposted without overwhelming onboarding.

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
  Transparency causes ghost-hex artifacts through neighbors.
- Tiling: flat-top hexes, `colStep = W * 0.75`, `rowStep = H`, odd columns offset by `H/2`.
- **Layout with inline styles, not Tailwind arbitrary values** (`w-[390px]` etc. silently
  failed in the artifact sandbox and collapsed the viewport to zero height). Use a flex
  column: header `flex: 0 0 auto` → map `flex: 1 1 auto; min-height: 0` → tab bar `0 0 auto`.

### 13.3 Palette
```
ink        #141b18   inkPanel  #1d2622   line    #3a463f
vellum     #ece3cd   vellumDim #c9bd9e
copper     #c1793f   ember     #b8453f   gold    #d8b34a   violet #7d5fa8
forest     #5f8058   mountain  #6d8399   plains  #b08a5a
badlands   #96604c   grassland #a8a05c
```
Depleted tiles use a darker/desaturated variant of their **own biome color**, never grey —
the land is drained, not dead, and it will regrow.

---

## 14. Open items — not yet designed

Ordered roughly by leverage:

1. **Crafting recipe tree in full** — the chokepoint every other system feeds into
2. **Combat resolution** — stat-check vs dice vs elemental cycle (a Fire/Water/Earth/Wind +
   Neutral/Light/Dark cycle was considered and may map onto Shard types)
3. **Loot table math** — drop odds per floor, pity-timer thresholds
4. **NPC shop catalog** — full gold-sink list and price curve
5. **Championship trigger thresholds** — what telemetry values prompt an admin event
6. **Guild formation** — member cap, roles, join/leave flow
7. **Marketplace mechanics** — listing fees (another sink), anti-wash-trading, floor manipulation
8. **Catch-up for late joiners** — early dungeon tiers scaling to server age, not just level
9. **Notification design** — resource ready, raid available, equipment broken (critical for
   an idle game's retention)
10. **Provable fairness** — on-chain verifiable seeds for loot and championship outcomes
11. **Public queue congestion** at popular capitals — priority rules, if any
12. **Fairness of permanent guild ownership** — should there be a periodic "slot up for
    grabs" event so new guilds always have something contestable?

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
- Start with the tutorial loop (§12) as the first vertical slice. It touches mining,
  processing, gold, NPC shop, and crafting — roughly 60% of the core systems in one flow.