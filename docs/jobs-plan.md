# Jobs and skill trees — execution order

Design lives in **CLAUDE.md §7.4**. This file is only the order of work; when a
decision gets made, it goes in the design doc, not here.

> **Closed, then extended.** All eight steps below are done. A twelfth job —
> Explorer (**CLAUDE.md §7.5**) — landed afterwards alongside the sight rework
> (§5.6), and it is shaped nothing like these: five nodes in a chain, granted
> rather than bought, levelling on hexes walked. It reuses this system's tier
> gates and node effects and adds one effect kind, `sight`.

Eleven jobs, 30 tree nodes each, bought with one skill point per character level
to a cap of 100. Five gathering lines take their level from the §7.2 skill they
already had; three craft jobs level by crafting; three battle jobs are built but
dormant until raiding exists (§9).

The load-bearing constraint, restated because every step has to respect it:
**a skill point may never take a stat past `STAT_CEILING` (+15%)**. Tree `stat`
nodes feed the same aggregate and clamp as gear, rolled options and potions.
Everything else a node can do is capability, or is capped to protect a §11 sink.

---

## Step 1 — Balance foundation

Constants and curves only; no new behaviour.

- `MAX_LEVEL` 60 → **100**, and `xpForLevel(L) = round(40 + 2.1 * L^1.7)`.
  Sized against measured income (~1,080 char XP/day across a career) to land
  level 100 at ~182 days of unbroken play at speed 1.
- `JOB_MAX_LEVEL = 30`, `jobXpForLevel(L) = round(17 * L^1.5)`.
- `SKILL_POINTS_PER_LEVEL = 1`, so points = character level, 100 at cap.
- Node effect caps: `SKILL_OPTION_CHANCE_CAP`, `SKILL_DURABILITY_CAP`,
  `SKILL_COST_REDUCTION_CAP`, `SKILL_BATCH_CAP`.
- Mirror all of it in `resources/js/game/balance.ts`.
- Tests: the curve reaches 100 in the intended window; **XP never passes through
  `Balance::scaled()`** — a trip pays the same at speed 1 and speed 100.

## Step 2 — Job and node catalog

- `Catalog::JOBS` — six entries: key, name, kind (`craft` / `battle`), the craft
  category or battle role it draws XP from, palette.
- `Catalog::JOB_NODES` — 180 nodes: key, job, tier, cost 1, `requiresJobLevel`,
  `requires[]` (parent node keys), effect, description.
- Tier shape per tree: **6 / 8 / 8 / 6 / 2** at job levels 1 / 5 / 12 / 20 / 28.
  Tier 5 capstones require **two** tier-4 parents.
- **Served, not mirrored.** 180 nodes hand-copied into `catalog.ts` would triple
  the drift surface the "Open" note below already calls overdue. The catalog goes
  out over `GET /api/jobs` and the client caches it — one source of truth, and
  ~5KB gzipped fetched once when the panel first opens.
- Tests: every tree has exactly 30 nodes in the right tier shape; every
  `requires` names a real node in the same job and a strictly lower tier; no
  cycles; every effect is a known type and inside its cap.

## Step 3 — Persistence

- `character_jobs` (character_id, job_key, level, xp) — one row per job, seeded
  at level 1 on character creation.
- `character_nodes` (character_id, node_key) — unique per pair. Bought, never
  refunded (§7.4.2).
- Spent points = `count(character_nodes)`; available = level − spent.
- Models, relations, and the migration.

## Step 4 — Earning job XP

- Crafting grants job XP to the job matching the item's category (§8.4 already
  derives category from slot, so nothing new is stored).
- Amount scales with what was made: `10 * (rarityRank + 1)`.
- Battle jobs get **no** XP source. A test asserts mining and crafting cannot
  move a battle job, so the dormancy is enforced rather than merely absent.

## Step 5 — Effects

- Resolve unlocked nodes into an effect bundle per character.
- `stat` nodes join `Formulas::aggregateStat` **inside** the existing falloff and
  clamp — they must not be a separate term added after it.
- `unlock` gates recipe availability in `craftItem` and the workshop list.
- `craftOption` / `craftDurability` / `costReduction` / `batch` apply at the
  craft site, each clamped to its Step 1 cap.

## Step 6 — API

- `GET` trees + owned nodes + points in the state payload.
- `POST /api/skills/nodes` — buy one node. Server checks: point available, job
  level met, every parent owned, not already owned.
- Every check server-side; the client only renders (§16).

## Step 7 — UI

- A tree panel: six trees, tier rows, parent lines, owned / affordable / locked.
- **Load the `frontend-design` skill before drawing anything**, and screenshot it.
- Procedural icons: one silhouette family per job, tier for ornament — the §13.1
  system, no new art.

## Step 8 — Balance proof

- A script that buys every node in the three most stat-heavy trees and asserts no
  stat exceeds `STAT_CEILING`, no cap is breached.
- Re-run the pacing figure and record it.

---

## Progress

- [x] 1 Balance foundation — level cap 100, curve fitted to ~182 days, job + effect caps, 4 tests
- [x] 2 Job and node catalog — 180 nodes in app/Game/Jobs.php, served not mirrored, 9 validator tests
- [x] 3 Persistence — character_jobs + character_nodes, spent points counted not stored
- [x] 4 Earning job XP — crafts teach their bench's job; battle dormancy enforced by test
- [x] 5 Effects — stats fold into the one gear clamp; cost/durability/option/batch capped per job
- [x] 6 API — GET /api/jobs-tree, POST /api/jobs-tree/nodes, state carries points+jobs+nodes
- [x] 7 UI — SkillsView panel: strata bands, lineage on select, sticky detail plate
- [x] 8 Balance proof — full-tree + gear + potion still clamps at +15%; per-tree sink caps pinned
- [x] + Explorer (§7.5) — 12th job, granted 5-node chain, XP from hexes walked, `sight` effect kind

## Open

- Explorer's chain writes +25% travelSpeed against a +15% ceiling, so a maxed
  Explorer has that stat spent for them and boots stop adding to it. Deliberate
  — it is the trade for a free tree — but it is the one place a tree alone fills
  a stat, and `JobTreeTest` exempts it from the "leave gear something to add"
  guard because of it. If travel gear is ever meant to matter again, this is the
  number to revisit, not the ceiling.
- Battle job XP needs raid combat (§9, §14.2). Until then three of the six trees
  are visible, gated, and unreachable — which is honest, but means half the
  system cannot be play-tested. Each battle tree carries 8 dormant `unlock`
  nodes named for the ability they will grant, so combat has hooks to build
  against rather than a blank sheet.
- The 24 ability keys are declared and resolve to nothing. When combat lands,
  `nodeEffects()['unlocks']` is where they are already collected.
- Nothing verifies the PHP and TS *item* catalogs stay in sync (45 items, hand-
  kept both sides). Jobs dodge this by being served rather than mirrored, which
  is the pattern the items catalog should probably follow too.
