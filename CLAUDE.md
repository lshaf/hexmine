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
| Gold has no NFT bridge | Gold buys *basic* items only. Gold can never be converted to NFT value. |
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
- Buys **basic tier items only** — never convertible to NFT

### 3.3 NFT
- The *only* externally tradeable value
- Categories: bundles, rare materials, top-tier equipment, cosmetics
- Sourced from: marketplace purchase, or crafting with Tier 3 + Tier 4 materials
- **Never** a grind reward

---

## 4. Materials (20 total)

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

| Tier | Source | Materials | Stat ceiling |
|---|---|---|---|
| Basic | Gold (NPC shop) | none | +3–5%, universal |
| Crafted | Player crafting | Tier 1–2 | +6–8%, universal |
| NFT | Marketplace / rare crafting | Tier 3 + Tier 4 | +12–15% (hard cap) |

### 8.1 Anti-imbalance rules (all mandatory)
1. **Hard % cap per slot** regardless of rarity. Rarity changes *durability and reliability*,
   not the power ceiling.
2. **Diminishing returns on stacking** — a 2nd yield item gives less than the 1st. Blocks
   buying 3 identical bundles for linear scaling.
3. **Durability decays with use** (raiding drains faster than mining). Equipment is never
   "buy once, dominate forever."
4. NFT gear and best gold/crafted gear sit on the **same capped power curve**, differing in
   acquisition speed and durability cost. This is what keeps F2P viable — and F2P viability
   is what sustains the active playerbase that drives NFT demand.

### 8.2 Repair vs discard
- At 0 durability equipment becomes **broken/inactive**, not destroyed
- Repair costs refined + raid materials, scaled to rarity tier
- Discard returns a **small % salvage** — clears inventory bloat, gives obsolete gear an exit
- **Tuning decision still open:** repair cost must be cheaper than crafting new, but not
  dramatically so, or the crafting-materials sink stalls at endgame.

### 8.3 Example recipes (starting values)
```
Iron Pickaxe      = 5 Ingots + 3 Planks                        → +6% yield
Leather Armor     = 6 Leather + 2 Cloth                        → +6% trip reduction
Reinforced Boots  = 4 Cut Stone + 3 Leather                    → +8% travel speed
Work Gloves       = 3 Cloth + 2 Planks                         → +4% processing speed
Mythril Pickaxe   = 3 Mythril + 2 Reinforced Frame + 1 Essence → +12% yield   [NFT]
Ironwood Armor    = 3 Ironwood + 2 Silkweave + 1 Shard         → +12% trip    [NFT]
Beastfang Boots   = 2 Beastfang + 1 Obsidian + 1 Relic         → +15% travel  [NFT]
```

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

1. Collect branch in a Forest hex → teaches hex mining, biome locking
2. Sell branch for gold → teaches NPC shop, gold faucet (deliberately poor rate)
3. Buy basic axe → teaches gold-tier equipment, instant visible payoff
4. Mine wood with axe → equipment value demonstrated immediately
5. Sell more wood → reinforces gold loop
6. Process wood → planks → teaches processing + presence bonus
7. Craft wood pickaxe → teaches material crafting, gold→crafted tier ladder
8. Mine with new pickaxe → loop closes with visible improvement
9. Sell some, process some → teaches the **sell-vs-process decision** players make forever

End on a soft hook toward the contested ring ("the forest edge holds rarer wood, but it's
contested") so depth is signposted without overwhelming onboarding.

---

## 13. Art direction — no artist required

All visuals are **procedural SVG**. The full equipment icon set is generated from
`5 base shapes × 3 tier treatments × 5 material palettes` via fill/stroke swaps.

### 13.1 Icon system
| Axis | Encoding |
|---|---|
| Equipment slot | One base silhouette (pickaxe, armor, boots, gloves, weapon) |
| Tier | Fill treatment: flat grey (basic) → solid color (crafted) → gradient + border glow (NFT) |
| Material | Accent color: wood=brown, iron=steel, pelt=tan, stone=slate, fiber=cream |
| Rarity | Hex-shaped border frame, ornamentation increases per tier |

### 13.2 Map rendering (critical implementation notes)
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