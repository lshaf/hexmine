<script setup lang="ts">
/**
 * The trades, §7.4.
 *
 * Eleven trees of thirty nodes, one chain of five, and the panel's one job is
 * to answer "what can I take right now" without the player counting anything.
 *
 * The chain is Explorer (§7.5), and it is the one sheet with no price on it:
 * its five skills arrive as the job levels and cost no point. The panel says so
 * rather than drawing a Learn button that would only ever be refused.
 *
 * ── Why strata, and why no wires ─────────────────────────────────────────────
 * A skill tree is usually drawn as a graph of curved edges. That would be the
 * wrong drawing here twice over. First, this is a mining game and the tree
 * genuinely *is* layered: tiers gate on depth, each one needs the one above cut
 * first, and 30 nodes narrow to 2 at the bottom. Strata say that. Second, a
 * curved-edge graph collapses on a phone -- nodes become dots and the wires
 * become spaghetti the moment a row wraps.
 *
 * So the structure is read from the strata, and lineage is shown *on demand*:
 * touch a node and its parents light up through the bands above. Parents are
 * always exactly one tier up (§7.4.2), so there is nothing a permanent wire
 * would tell you that the band above does not.
 *
 * Everything the server decides stays server-decided (§16): this draws state and
 * posts one key. It never computes affordability as truth, only as a hint about
 * which button to light.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { ACTION_PATHS } from '@/icons/actions'
import { MATERIAL_PALETTE } from '@/theme/palette'
import { STAT_LABEL } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import type { NodeDef, NodeEffect } from '@/api/types'
import type { StatKey } from '@/game/types'

const game = useGame()

/** Woodcutting first: it is the line the tutorial puts in your hands. */
const job = ref<string>('woodcutting')
const picked = ref<string | null>(null)

onMounted(() => void game.loadTree())

/** A different job is a different sheet; keep nothing selected across it. */
watch(job, () => {
  picked.value = null
})

const tree = computed(() => game.tree)

/**
 * Grouped in the order a character meets them: you walk and gather from the
 * first minute, craft once there is something to craft with, and raid when
 * raiding exists. The server sends them in this order already; this only cuts
 * them into their three bands so the panel does not read as twelve equal
 * choices.
 *
 * "Gathering" rather than "Mining" for the first band, because one of the jobs
 * inside it is called Mining and a heading that repeats a row underneath it is
 * a heading nobody trusts.
 */
const KIND_LABEL: Record<string, string> = {
  gathering: 'Gathering',
  craft: 'Crafting',
  battle: 'Battle',
}

/**
 * Which heading a job files under, which is not always its kind.
 *
 * Explorer is a wayfaring job in every rule that matters -- granted nodes, XP
 * from hexes rather than from a bench (§7.5) -- but a band of its own for a
 * single sheet would read as a fourth system to learn. It sits with the
 * gathering lines because that is what it is beside in play: the things you
 * level by being out on the map rather than at a bench or in a dungeon.
 */
const BAND: Record<string, string> = {
  wayfaring: 'gathering',
}

const bandOf = (kind: string): string => BAND[kind] ?? kind

/** §7.5 -- this sheet's skills are granted, so nothing here costs a point. */
const automatic = computed(() => (tree.value?.automatic ?? []).includes(job.value))

const groups = computed(() => {
  const t = tree.value
  if (!t) return []

  const out: Array<{ kind: string; label: string; keys: string[] }> = []
  for (const [key, def] of Object.entries(t.jobs)) {
    const kind = bandOf(def.kind)
    const band = out.find((g) => g.kind === kind)
    if (band) band.keys.push(key)
    else out.push({ kind, label: KIND_LABEL[kind] ?? kind, keys: [key] })
  }
  return out
})

const jobDef = computed(() => tree.value?.jobs[job.value] ?? null)

const jobRow = computed(
  () => game.jobLevels.find((j) => j.key === job.value) ?? { level: 1, xp: 0, xpToNext: 1 },
)

const accent = computed(() =>
  jobDef.value ? (MATERIAL_PALETTE[jobDef.value.palette as keyof typeof MATERIAL_PALETTE] ?? '#c1793f') : '#c1793f',
)

/** Roman numerals, because a stratum is named not counted. */
const DEPTH = ['', 'I', 'II', 'III', 'IV', 'V']

interface Band {
  tier: number
  jobLevel: number
  nodes: Array<{ key: string; def: NodeDef }>
  owned: number
  reached: boolean
}

const bands = computed<Band[]>(() => {
  const t = tree.value
  if (!t) return []

  const mine = Object.entries(t.nodes).filter(([, n]) => n.job === job.value)
  const out: Band[] = []

  for (const tier of [1, 2, 3, 4, 5]) {
    const nodes = mine
      .filter(([, n]) => n.tier === tier)
      .map(([key, def]) => ({ key, def }))
    if (!nodes.length) continue

    out.push({
      tier,
      jobLevel: nodes[0]!.def.jobLevel,
      nodes,
      owned: nodes.filter((n) => game.ownedNodes.has(n.key)).length,
      reached: jobRow.value.level >= nodes[0]!.def.jobLevel,
    })
  }
  return out
})

type NodeState = 'owned' | 'open' | 'no-points' | 'locked-level' | 'locked-parent' | 'waiting'

function stateOf(key: string, def: NodeDef): NodeState {
  if (game.ownedNodes.has(key)) return 'owned'
  if (jobRow.value.level < def.jobLevel) return 'locked-level'
  // §7.5 -- a granted node that is not owned yet is simply not reached yet.
  // It can never be 'open': there is no button, so offering one would be a lie.
  if (automatic.value) return 'waiting'
  if (!def.requires.every((r) => game.ownedNodes.has(r))) return 'locked-parent'
  if (game.skillPoints.available < 1) return 'no-points'
  return 'open'
}

/** Why a node is not takeable, in the player's terms. */
function reasonFor(key: string, def: NodeDef): string {
  switch (stateOf(key, def)) {
    case 'owned':
      return 'Learned.'
    case 'locked-level':
      return automatic.value
        ? `Arrives at ${jobDef.value?.name} level ${def.jobLevel}. You are ${jobRow.value.level}.`
        : `Needs ${jobDef.value?.name} level ${def.jobLevel}. You are ${jobRow.value.level}.`
    case 'waiting':
      return 'Arrives on its own. Nothing to spend.'
    case 'locked-parent': {
      const missing = def.requires
        .filter((r) => !game.ownedNodes.has(r))
        .map((r) => tree.value?.nodes[r]?.name ?? r)
      return `Needs ${missing.join(' and ')} first.`
    }
    case 'no-points':
      return 'No points left. Level up to earn one.'
    default:
      return 'Ready to learn.'
  }
}

/** The selected node's lineage, so its parents glow through the bands above. */
const lineage = computed(() => {
  const t = tree.value
  const out = new Set<string>()
  if (!t || !picked.value) return out

  const walk = (key: string) => {
    out.add(key)
    for (const parent of t.nodes[key]?.requires ?? []) walk(parent)
  }
  walk(picked.value)
  return out
})

const chosen = computed(() =>
  picked.value && tree.value ? { key: picked.value, def: tree.value.nodes[picked.value]! } : null,
)

const EFFECT_ICON: Record<NodeEffect['kind'], string> = {
  sight: 'effectSight',
  stat: 'effectStat',
  unlock: 'effectUnlock',
  craftOption: 'effectCraftOption',
  craftDurability: 'effectCraftDurability',
  costReduction: 'effectCostReduction',
  batch: 'effectBatch',
}

/** Plain terms. A node says what it gives you, never what field it writes. */
function effectText(effect: NodeEffect): string {
  switch (effect.kind) {
    case 'stat':
      return `${formatPercent(effect.value)} ${STAT_LABEL[effect.stat as StatKey] ?? effect.stat}`
    case 'unlock':
      return 'Unlocks a raid ability'
    case 'craftOption':
      return `${formatPercent(effect.value)} chance of an extra bonus line on what you make`
    case 'craftDurability':
      return `${formatPercent(effect.value)} durability on what you make`
    case 'costReduction':
      return `${formatPercent(-effect.value)} materials per craft`
    case 'batch':
      return `+${effect.value} made per craft`
    case 'sight':
      return `+${effect.value} hex of sight`
  }
}

/** Learned, and out of how many -- the trees are no longer all the same size. */
const progress = computed(() => {
  const t = tree.value
  if (!t) return { owned: 0, total: 0 }

  const mine = Object.keys(t.nodes).filter((k) => t.nodes[k]!.job === job.value)

  return { owned: mine.filter((k) => game.ownedNodes.has(k)).length, total: mine.length }
})

async function learn(): Promise<void> {
  // §7.5 -- the server refuses a granted node, and so does the button that
  // never renders for one. This is the third guard, for the keyboard path.
  if (!picked.value || automatic.value) return
  await game.buyNode(picked.value)
}
</script>

<template>
  <div class="page">
    <div v-if="!tree" class="inset empty">
      <p class="tiny muted" style="margin: 0">Opening the job sheets…</p>
    </div>

    <template v-else>
      <!-- The one number a player checks before anything else. -->
      <div class="inset purse">
        <div class="row-between">
          <div>
            <span class="label">Skill points</span>
            <div class="points">
              <strong>{{ game.skillPoints.available }}</strong>
              <span class="tiny muted">of {{ game.skillPoints.total }} unspent</span>
            </div>
          </div>
          <p class="tiny muted note">
            One point a level, and a point once spent stays spent.
          </p>
        </div>
      </div>

      <!-- Eleven jobs in three bands. Count on each so an untouched tree is
           obvious before you open it. -->
      <div v-for="group in groups" :key="group.kind" class="band-of-jobs">
        <span class="label kind">{{ group.label }}</span>
        <div class="trades">
          <button
            v-for="key in group.keys"
            :key="key"
            type="button"
            class="trade"
            :class="{ on: job === key }"
            :style="{ '--accent': MATERIAL_PALETTE[(tree.jobs[key]!.palette) as keyof typeof MATERIAL_PALETTE] }"
            @click="job = key"
          >
            {{ tree.jobs[key]!.name }}
            <span class="tally">{{
              Object.keys(tree.nodes).filter((k) => tree!.nodes[k]!.job === key && game.ownedNodes.has(k)).length
            }}</span>
          </button>
        </div>
      </div>

      <section v-if="jobDef" class="sheet" :style="{ '--accent': accent }">
        <header class="head">
          <div class="row-between">
            <h3>{{ jobDef.name }}</h3>
            <span class="chip tiny" :class="jobDef.kind === 'battle' ? 'chip-off' : ''">
              {{ jobDef.kind === 'battle' ? 'dormant' : jobDef.source }}
            </span>
          </div>
          <p class="tiny muted note">{{ jobDef.description }}</p>

          <div class="row-between meta">
            <span class="tiny">
              Level <strong>{{ jobRow.level }}</strong
              ><span class="muted">&nbsp;of {{ tree.jobMaxLevel }}</span>
            </span>
            <span class="tiny muted">{{ progress.owned }} of {{ progress.total }} learned</span>
          </div>
          <div class="bar" :title="`${jobRow.xp} / ${jobRow.xpToNext} xp`">
            <span :style="{ width: `${Math.min(100, (jobRow.xp / Math.max(1, jobRow.xpToNext)) * 100)}%` }" />
          </div>
          <p v-if="jobDef.kind === 'battle'" class="tiny dormant">
            Levels by raiding, and raiding is not built yet. The tree is here so
            it is ready when combat is — nothing else will move it.
          </p>
          <p v-else-if="automatic" class="tiny granted">
            Levels on hexes walked, and walking earns no character XP — this is
            the only thing a long road pays out. Its five skills are not bought:
            each arrives the moment the level does.
          </p>
        </header>

        <!-- The strata. Depth in the gutter, the seam beside it. -->
        <div v-for="band in bands" :key="band.tier" class="band" :class="{ sealed: !band.reached }">
          <div class="gutter">
            <span class="depth">{{ DEPTH[band.tier] }}</span>
            <span class="label lv">lv {{ band.jobLevel }}</span>
            <span class="tiny cut">{{ band.owned }}/{{ band.nodes.length }}</span>
          </div>

          <div class="seam">
            <button
              v-for="n in band.nodes"
              :key="n.key"
              type="button"
              class="node"
              :class="[stateOf(n.key, n.def), {
                picked: picked === n.key,
                lineage: lineage.has(n.key) && picked !== n.key,
              }]"
              :title="`${n.def.name} — ${reasonFor(n.key, n.def)}`"
              :aria-label="`${n.def.name}. ${reasonFor(n.key, n.def)}`"
              @click="picked = picked === n.key ? null : n.key"
            >
              <span class="hex">
                <span class="face">
                  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                       stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path :d="ACTION_PATHS[EFFECT_ICON[n.def.effect.kind]]" />
                  </svg>
                </span>
              </span>
            </button>
          </div>
        </div>
      </section>

      <!-- What you tapped, and the one button that acts on it. -->
      <div v-if="chosen" class="inset detail">
        <div class="row-between">
          <strong class="name">{{ chosen.def.name }}</strong>
          <span class="label tier">Depth {{ DEPTH[chosen.def.tier] }}</span>
        </div>
        <p class="tiny muted note">{{ chosen.def.description }}</p>
        <p class="tiny gives">{{ effectText(chosen.def.effect) }}</p>

        <div class="row-between foot">
          <span class="tiny" :class="stateOf(chosen.key, chosen.def) === 'open' ? 'ready' : 'muted'">
            {{ reasonFor(chosen.key, chosen.def) }}
          </span>
          <button
            v-if="!automatic && stateOf(chosen.key, chosen.def) !== 'owned'"
            class="btn btn-sm"
            :class="{ 'btn-primary': stateOf(chosen.key, chosen.def) === 'open' }"
            type="button"
            :disabled="game.busy || stateOf(chosen.key, chosen.def) !== 'open'"
            @click="learn"
          >
            Learn · 1 point
          </button>
        </div>
      </div>

      <p v-else class="tiny muted hint">Tap a skill to see what it gives and what it needs.</p>

      <p class="tiny muted footnote">
        A job level opens the depths; points are what you spend in them. Nothing
        here can push a stat past the same ceiling gear stops at — what a tree
        buys is a different road to it.
      </p>
    </template>
  </div>
</template>

<style scoped>
.page {
  padding: 0;
}

.empty {
  padding: 22px 16px;
  text-align: center;
}

.purse {
  margin-bottom: 12px;
}

.points {
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin-top: 2px;
}

.points strong {
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 1;
  color: var(--copper);
}

.note {
  margin: 0;
  line-height: 1.45;
  max-width: 34ch;
  text-align: right;
}

/* ------------------------------------------------------------------ trades */

.band-of-jobs + .band-of-jobs {
  margin-top: 9px;
}

.kind {
  display: block;
  margin-bottom: 4px;
  color: #6d7770;
}

.trades {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
  margin-bottom: 0;
}

.band-of-jobs:last-of-type {
  margin-bottom: 14px;
}

.trade {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 6px;
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font-family: inherit;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  clip-path: polygon(6px 0, 100% 0, 100% calc(100% - 6px), calc(100% - 6px) 100%, 0 100%, 0 6px);
}

.trade:hover {
  color: var(--vellum);
}

.trade.on {
  background: var(--ink-raised);
  border-color: var(--accent);
  color: var(--vellum);
}

.tally {
  font-size: 10px;
  font-weight: 700;
  color: #7b8580;
}

.trade.on .tally {
  color: var(--accent);
}

/* ------------------------------------------------------------------- sheet */

.head h3 {
  font-size: 16px;
}

.head .note {
  text-align: left;
  max-width: none;
  margin: 4px 0 9px;
}

.meta {
  margin-bottom: 4px;
}

.dormant,
.granted {
  margin: 8px 0 0;
  padding: 7px 9px;
  border: 1px dashed var(--line);
  color: #8a938c;
  line-height: 1.45;
}

/* Not a warning like .dormant is -- this sheet works, it just has no price. */
.granted {
  border-color: var(--accent);
  color: var(--vellum-dim);
}

/* ------------------------------------------------------------------ strata */

/*
 * The signature. Depth in a ruled gutter, the seam beside it: five bands that
 * narrow from six nodes to two, which is what the tree actually does.
 */
.band {
  display: grid;
  grid-template-columns: 58px 1fr;
  gap: 10px;
  padding: 11px 0;
  border-top: 1px solid var(--hud-line-soft);
}

.band:first-of-type {
  margin-top: 12px;
}

.gutter {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding-right: 10px;
  border-right: 2px solid var(--accent);
  text-align: right;
}

.depth {
  font-family: var(--font-display);
  font-size: 17px;
  line-height: 1.1;
  color: var(--vellum);
}

.lv {
  color: #6d7770;
}

.cut {
  color: var(--accent);
  font-weight: 700;
}

/* A stratum the trade level has not reached yet reads as unopened ground. */
.band.sealed .depth,
.band.sealed .cut {
  color: #5f6b64;
}

.band.sealed .gutter {
  border-right-color: var(--line);
}

.seam {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-content: flex-start;
}

/* ------------------------------------------------------------------- nodes */

.node {
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
  color: #6d7770;
}

.node .hex {
  display: block;
  width: 46px;
  height: 40px;
  background: var(--line);
  transition: transform 0.12s ease;
}

.node .face {
  display: grid;
  place-items: center;
  background: var(--ink-panel);
  transition: background 0.12s ease, color 0.12s ease;
}

.node:hover .hex {
  transform: translateY(-1px);
}

/* Learned: filled, and the only solid shape in the seam. */
.node.owned {
  color: #1a120a;
}

.node.owned .hex {
  background: var(--accent);
}

.node.owned .face {
  background: var(--accent);
}

/* Takeable right now: lit edge, so affordability is a glance not a count. */
.node.open {
  color: var(--vellum);
}

.node.open .hex {
  background: var(--vellum);
}

.node.open .face {
  background: var(--ink-raised);
}

/* Everything else is dim, and the detail plate says which kind of dim. */
.node.no-points {
  color: #8a938c;
}

.node.no-points .hex {
  background: var(--hud-line);
}

.node.locked-level .face,
.node.locked-parent .face {
  background: #171d1a;
}

/* §7.5 -- reached but not yet arrived. Dim like the locked states, but with a
   lit rim: there is nothing to do about it, and nothing wrong either. */
.node.waiting {
  color: #8a938c;
}

.node.waiting .hex {
  background: var(--hud-line);
}

.node.waiting .face {
  background: #171d1a;
}

.node.picked .hex {
  background: var(--copper);
  transform: translateY(-2px);
}

/* Lineage: the parents of what you tapped, lit through the bands above. */
.node.lineage .hex {
  background: var(--copper);
}

.node:focus-visible .hex {
  background: var(--copper);
}

/* ------------------------------------------------------------------ detail */

/*
 * Sticks to the bottom of the panel's scroll. Tapping a node in the deepest
 * band and then having to scroll back down to press Learn is the one thing that
 * would make this panel annoying to use, and it is cheap to prevent.
 */
.detail {
  position: sticky;
  bottom: -18px;
  margin-top: 14px;
  background: var(--ink-raised);
  box-shadow: 0 -10px 18px rgba(8, 11, 10, 0.75);
}

.name {
  font-size: 13.5px;
}

.tier {
  color: #6d7770;
}

.detail .note {
  text-align: left;
  max-width: none;
  margin: 6px 0 0;
}

.gives {
  margin: 6px 0 0;
  color: var(--accent, var(--copper));
  font-weight: 600;
}

.foot {
  margin-top: 10px;
  gap: 10px;
}

.ready {
  color: #b7d6a4;
}

.hint {
  margin: 14px 0 0;
  text-align: center;
}

.footnote {
  margin: 18px 0 0;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  line-height: 1.55;
}

@media (max-width: 560px) {
  .trades {
    grid-template-columns: repeat(2, 1fr);
  }

  .band {
    grid-template-columns: 44px 1fr;
    gap: 8px;
  }

  .gutter {
    padding-right: 7px;
  }

  .node .hex {
    width: 42px;
    height: 37px;
  }

  .purse .note {
    display: none;
  }
}
</style>
