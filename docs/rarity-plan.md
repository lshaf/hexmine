# Rarity, station gating and the crafting split — execution plan

> Working document. Seven steps, ordered so each one ships and is testable on its
> own. Tick a step only when `phpunit`, `npm run typecheck` and `npm run parity`
> are all green and the screen it touches has been looked at.
>
> Design decisions live in CLAUDE.md. This file is only the order of work.

---

## The shape of it

Two axes that are deliberately **not** the same thing:

| Axis | Values | Drives |
|---|---|---|
| **Rarity** | common · uncommon · rare · epic · legendary · unique | Power ceiling, colour, where it can be made |
| **Tradeable** | yes / no | Whether it is an NFT (§3.3) |

They were one field (`tier: basic\|crafted\|nft`) doing both jobs. Splitting them
is step 1, and everything else depends on it.

### The rarity ladder

| Rarity | Colour | Stat ceiling | Option rolls | Durability |
|---|---|---|---|---|
| common | grey `#8d948e` | +3% | 0 | 40 |
| uncommon | green `#6f9a5e` | +5% | 0–1 | 70 |
| rare | blue `#6f9ec4` | +8% | 1 | 120 |
| epic | violet `#7d5fa8` | +11% | 2 | 190 |
| legendary | gold `#d8b34a` | +14% | 3 | 280 |
| unique | ember `#b8453f` | +15% | 3 + fixed perk | 400 |

+15% stays the **hard ceiling for the whole game** (§8.1). Rarity now walks up to
it instead of every tier sharing it. Options roll *inside* the ceiling — they can
never push a stat past it.

### Where each rarity comes from

| Rarity | Gold shop | Bench | Notes |
|---|---|---|---|
| common | village+ | village | |
| uncommon | city+ | city | |
| rare | never | capital | gold never buys past uncommon (§3.2) |
| epic | never | capital | tradeable NFT — T3+T4 inputs |
| legendary | never | guild hall | tradeable NFT, needs a skill tree unlock |
| unique | never | never | dungeon drop only, **soulbound** |

A bench reaches **exactly** as far as its tier, whatever you carry to it. Because
a city stops at uncommon, **rare is capital-only** — the jump from city to capital
is the biggest step on the ladder, and that is what makes the walk worth it.

The capital's shop edge is **options, not rarity** — it stocks the same common and
uncommon goods as a city, but some of its stock comes with rolled bonus lines.

### Two conflicts with the existing doc, and how they resolve

1. **§8.1 rule 1** says "hard % cap per slot *regardless of rarity*. Rarity changes
   durability and reliability, not the power ceiling." That is now false. Rule 1 is
   rewritten: the cap is a single global ceiling and rarity climbs toward it.
   F2P viability (rule 4) is preserved by the gap being 12 points, not by the
   curve being flat — plus every rarity below unique is craftable without spending.

2. **§2 forbids NFTs as drops** ("NFTs are **never** dropped by mining or raiding").
   "Unique drops from dungeons" would break the hardest rule in the threat model.
   Resolution: **unique items are soulbound, not NFTs.** They are prestige, not
   liquidity. Tradeability stops at legendary. This keeps the grind→external-value
   path closed, which is the whole point of §2.

---

## Step 1 — Rarity replaces the three-tier system

The rename everything else sits on. No new mechanics.

- `EquipTier = basic|crafted|nft` → `Rarity` with the six values, PHP + TS.
- `ItemDef.tier` → `ItemDef.rarity`; add `ItemDef.tradeable: boolean`.
- `Balance::STAT_CAP` / `EQUIPMENT.statCap` keyed by the six rarities.
- Re-home all 32 existing items onto the ladder. The current `basic` split becomes
  common (village) / uncommon (city); `crafted` starter → uncommon, `crafted` →
  rare; `nft` → epic.
- Six-colour treatment in `icons/procedural.ts`, replacing `TIER_TREATMENT`.
- Rarity name + colour on every item row: shop, craft, hero, bag.
- CLAUDE.md: rewrite §8 opening table, §8.1 rule 1, §13.1 rarity row.

**Done when** the ladder renders in six distinct colours and the stat cap a player
actually gets is the one their best item's rarity allows.

**Watch for** `Formulas::aggregateStat` — it picks `$bestCap` from the best tier
present. That logic survives unchanged; only the key set grows.

---

## Step 2 — Station gating for shop and craft

- `shopStock` filters by rarity, not just station rank: village common, city and
  capital common + uncommon.
- `craftItem` enforces a rarity ceiling per station: village common, city uncommon,
  capital epic. Guild and dungeon rarities refuse with a reason that names why.
- Gold price curve by rarity, so better gear costs more.
- Craft screen groups by rarity and greys what this station cannot reach, with the
  station that could.

**Done when** a village shop shows only common, and a capital refuses to craft
legendary with a message naming the guild hall.

### How step 2 settled the two open questions

1. **RESOLVED — the tutorial's craft step vs village-common.** The crafted
   starters became **common**, so a village can still make them. To keep §12
   step 8's "visible improvement" real, **shop commons dropped to +2%** while
   crafted commons sit at the +3% cap. So the tutorial still reads: buy a Stone
   Axe (+2%, 40 durability, gold), then craft a Hewn Axe (+3%, 60 durability, no
   gold). Crafted beats bought on both axes, inside one rung, and the village
   rule is untouched.

2. **STILL OPEN — nothing checks that the two catalogs agree.** `npm run parity`
   covers worldgen only. The PHP and TS item catalogs are 32 items × 9 fields of
   hand-kept duplication, and steps 1 and 2 both needed an ad-hoc script to
   confirm they matched. Worth an artisan fixture + a parity check **before**
   step 5 grows the catalog.

### Also fixed in step 2

**The price ladder was inverted.** Travel Cloak was common at 65g while Hide
Shoes was uncommon at 55g — so "better equip is higher price" was false at the
till, and the rarity colour was lying to the player. Cloak dropped to 16g, and a
test now asserts the cheapest item of each rung beats the priciest of the rung
below.

### Known gap left behind

**CLOSED in step 5.** Rare had no craftable recipe; the capital bench now
carries eight (five tools, armor, boots, gloves) built from Reinforced Frame,
the one tier-2 that needs two processing lines.

---

## Step 3 — Tell the three settlements apart on the map

Already deterministic: `WorldGen::TIER_FOR_RING` derives tier from the ring lattice,
and `npm run parity` pins the client to the same 304 settlements. **No backend work
is needed for the client to know where they are — this step is purely visual.**

- Redraw `settlementProp` so village / city / capital are unmistakable at map scale,
  not just progressively taller.
- Distinct silhouette per tier, the way the tools got distinct reads: a scatter of
  huts, a walled block with one tower, a multi-tower skyline.
- Check it at real zoom, not in isolation.

---

## Step 4 — Item options (rolled bonus lines)

The first step that needs a migration.

- Migration: `options` JSON column on `character_items`.
- Roll on craft and on purchase, server-side, seeded so it is auditable.
- Count by rarity per the ladder table. Values small (+1–3%), drawn from `StatKey`.
- **Options feed `aggregateStat` and are clamped by the same ceiling.** An option
  must never breach the rarity cap — that is the threat-model guardrail.
- Show rolled lines under the base stat wherever an item is rendered.
- Capital shop stock sometimes carries pre-rolled options; village and city never.

**Watch for** the salvage and repair maths (`Formulas::salvageYield`,
`repairCost`) — both read `def.inputs` and need to stay sane for an item whose
value is partly instance-level.

---

## Step 5 — Split crafting into weapon / armor / consumable

- Craft screen gains three categories. Weapon covers the five gathering tools plus
  the dormant raid `weapon` slot; armor covers armor/boots/gloves; consumable is
  new and empty until step 6.
- Fill out the recipe tree so each category has something at every rarity its
  station can reach (this is CLAUDE.md §14 open item 1, finally addressed).

---

## Step 6 — Consumables: potions and buffs

The largest genuinely new subsystem. Nothing like it exists yet.

- Consumables are stackable and **not** equipment — they need their own storage,
  not `character_items`.
- Migration: `character_consumables` (key, quantity) and `character_buffs`
  (key, stat, value, expires_at).
- A use action that spends one and starts a timed buff, server-authoritative
  expiry like every other timer.
- Buffs feed `bonuses()` alongside equipment, and are subject to the same ceiling.
- Recipes at village (common) through capital (epic).

**Watch for** the §11 sink rule: a consumable that only accumulates is wrong.
Buffs expire, which is the sink — do not add a permanent-effect potion.

---

## Step 7 — Reserve legendary and unique

Guilds (§10) and dungeons (§9) are not built. Both rarities stay unreachable, but
the gates must exist so nothing accidentally leaks them.

- Guild-hall station type defined and refused with "no guild hall exists yet".
- Unique flagged non-craftable and soulbound everywhere; a test asserts no recipe
  and no shop can ever produce one.
- Test: nothing tradeable is obtainable without spending T3+T4 materials.

---

## Progress

- [x] 1 — Rarity replaces the three-tier system
- [x] 2 — Station gating for shop and craft
- [x] 3 — Tell the three settlements apart
- [x] 4 — Item options
- [x] 5 — Weapon / armor / consumable split
- [x] 6 — Consumables and buffs
- [x] 7 — Reserve legendary and unique
