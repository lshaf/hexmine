<script setup lang="ts">
/**
 * §7.4 -- four shapes the skill screen could be, drawn side by side.
 *
 * A pitch, not the rules. Nothing here is implemented and nothing here reads a
 * character: every tree on this page is drawn from a small sample so the SHAPE
 * is what is being compared, which is the only thing a mock-up is good for.
 *
 * The question it exists to answer is the one the current screen cannot: today
 * there are seventeen trees of thirty nodes, reachable one at a time behind a
 * picker, and a career's hundred points buys about three of them. "One tree"
 * could mean four quite different things, and they differ most in what happens
 * to the other four hundred and sixty-five nodes.
 */
import { computed, ref } from 'vue'
import { ACTION_PATHS } from '@/icons/actions'

type State = 'owned' | 'open' | 'locked'

interface Node {
  name: string
  icon: keyof typeof ACTION_PATHS
  state: State
  /** A gate beyond the row's own: what §9.5.9's battle skills already do. */
  needs?: string
}

const icons = [
  'effectStat', 'effectSeam', 'effectBagSlots', 'effectGold', 'battle',
  'effectCraftDurability', 'effectPresence', 'effectBatch', 'effectSight',
  'effectBrew', 'effectRunSlot', 'effectCostReduction',
] as const

/** Deterministic filler so two runs of the page look the same. */
function nodes(count: number, owned: number, open: number, seed: number): Node[] {
  return Array.from({ length: count }, (_, i) => ({
    name: `Node ${i + 1}`,
    icon: icons[(i + seed) % icons.length]!,
    state: i < owned ? 'owned' : i < owned + open ? 'open' : 'locked',
  }))
}

// ------------------------------------------------------------------ shape A

/**
 * One ladder for the whole character. Five rows, gated by CHARACTER level, and
 * individual nodes may carry a second gate naming a job -- which is exactly
 * what a battle skill does today at depth I, II and III.
 */
const A_ROWS = [
  { depth: 'I', level: 1, nodes: nodes(6, 4, 2, 0) },
  { depth: 'II', level: 5, nodes: nodes(8, 2, 3, 3) },
  {
    depth: 'III',
    level: 12,
    nodes: [
      ...nodes(6, 0, 2, 5),
      { name: 'Sunder', icon: 'battle', state: 'locked', needs: 'Swordhand 12' },
      { name: 'Deep Seam', icon: 'effectSeam', state: 'locked', needs: 'Mining 12' },
    ] as Node[],
  },
  {
    depth: 'IV',
    level: 20,
    nodes: [
      ...nodes(4, 0, 0, 7),
      { name: 'Riposte', icon: 'battle', state: 'locked', needs: 'Swordhand 20' },
      { name: 'Tinker’s Roll', icon: 'effectBagSlots', state: 'locked', needs: 'Explorer 20' },
    ] as Node[],
  },
  { depth: 'V', level: 28, nodes: nodes(2, 0, 0, 9) },
]

// ------------------------------------------------------------------ shape B

/** Every job kept, drawn as branches off one spine instead of behind a picker. */
const B_BRANCHES = [
  { name: 'Woodcutting', gate: 'lv 1', nodes: nodes(5, 3, 1, 0) },
  { name: 'Mining', gate: 'lv 1', nodes: nodes(5, 1, 2, 2) },
  { name: 'Sawyer', gate: 'lv 5', nodes: nodes(5, 0, 1, 4) },
  { name: 'Smith', gate: 'lv 5', nodes: nodes(5, 0, 0, 6) },
  { name: 'Swordhand', gate: 'lv 12', nodes: nodes(5, 0, 0, 8) },
  { name: 'Explorer', gate: 'lv 2', nodes: nodes(5, 2, 0, 10) },
]

// ------------------------------------------------------------------ shape C

/** The seventeen collapsed into their five kinds. */
const C_KINDS = [
  { name: 'Gathering', rows: [nodes(6, 4, 2, 0), nodes(6, 0, 1, 3)] },
  { name: 'Processing', rows: [nodes(6, 1, 2, 5), nodes(6, 0, 0, 7)] },
  { name: 'Craft', rows: [nodes(6, 0, 2, 2), nodes(6, 0, 0, 9)] },
  { name: 'Battle', rows: [nodes(6, 0, 1, 4), nodes(6, 0, 0, 1)] },
  { name: 'Wayfaring', rows: [nodes(3, 3, 0, 8)] },
]

// ------------------------------------------------------------- today, for scale

const TODAY_JOBS = [
  'Explorer', 'Woodcutting', 'Mining', 'Hunting', 'Quarrying', 'Harvesting',
  'Sawyer', 'Smelter', 'Tanner', 'Mason', 'Weaver',
  'Smith', 'Armorer', 'Alchemist', 'Shieldbearer', 'Swordhand', 'Runecaster',
]

const TODAY_ROWS = [
  { depth: 'I', level: 1, nodes: nodes(6, 0, 6, 0) },
  { depth: 'II', level: 5, nodes: nodes(8, 0, 0, 3) },
  { depth: 'III', level: 12, nodes: nodes(8, 0, 0, 5) },
  { depth: 'IV', level: 20, nodes: nodes(6, 0, 0, 7) },
  { depth: 'V', level: 28, nodes: nodes(2, 0, 0, 9) },
]

const shown = ref<'today' | 'a' | 'b' | 'c'>('a')

const SHAPES = [
  { key: 'today', label: 'Today' },
  { key: 'a', label: 'A — one ladder' },
  { key: 'b', label: 'B — one tree, jobs as branches' },
  { key: 'c', label: 'C — one per kind' },
] as const

/** The arithmetic each shape implies, so the trade is a number not a feeling. */
const COST = computed(() => ({
  today: { nodes: '495', trees: '17', points: '100 points buys ~3 trees', pick: 'Which three jobs' },
  a: { nodes: '~34', trees: '1', points: '100 points, ~34 nodes', pick: 'Nothing — a career fills it' },
  b: { nodes: '495', trees: '1 diagram', points: '100 points buys ~3 branches', pick: 'Which branches' },
  c: { nodes: '~150', trees: '5', points: '100 points buys ~3 kinds', pick: 'Which kinds' },
}[shown.value]))
</script>

<template>
  <div class="page">
    <header class="head">
      <div>
        <h1>Skill tree — four shapes</h1>
        <p class="tiny muted">
          A pitch, not the rules. Nothing here is built; the trees are sample data so the
          SHAPE is what is being compared. Pick one and I will build it.
        </p>
      </div>
    </header>

    <nav class="tabs">
      <button
        v-for="s in SHAPES"
        :key="s.key"
        type="button"
        :class="{ on: shown === s.key }"
        @click="shown = s.key"
      >{{ s.label }}</button>
    </nav>

    <dl class="facts">
      <div><dt>Nodes</dt><dd>{{ COST.nodes }}</dd></div>
      <div><dt>Trees</dt><dd>{{ COST.trees }}</dd></div>
      <div><dt>Points</dt><dd>{{ COST.points }}</dd></div>
      <div><dt>You choose</dt><dd>{{ COST.pick }}</dd></div>
    </dl>

    <!-- ------------------------------------------------------------ today -->
    <section v-if="shown === 'today'" class="plate">
      <div class="inner">
        <p class="lede">
          Seventeen trees behind a picker. You see one at a time, and a career's hundred
          points buys about three of them — which three is most of what a character is.
        </p>

        <div class="picker">
          <button v-for="j in TODAY_JOBS" :key="j" type="button" class="job" :class="{ on: j === 'Woodcutting' }">
            {{ j }} <span class="muted">0</span>
          </button>
        </div>

        <div class="rows">
          <div v-for="r in TODAY_ROWS" :key="r.depth" class="row">
            <div class="gutter">
              <span class="depth">{{ r.depth }}</span>
              <span class="tiny muted">LV {{ r.level }}</span>
            </div>
            <div class="seam">
              <span v-for="(n, i) in r.nodes" :key="i" class="node" :class="n.state">
                <span class="hex"><span class="face">
                  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                       stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="ACTION_PATHS[n.icon]" />
                  </svg>
                </span></span>
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ---------------------------------------------------------------- A -->
    <section v-if="shown === 'a'" class="plate">
      <div class="inner">
        <p class="lede">
          <strong>One ladder for the character.</strong> No picker at all. The rows gate on
          your own level the way a job's tiers do today, and a node may carry a second gate
          naming a job — which is what a battle skill already does. The seventeen jobs survive
          as XP counters and as those gates; they stop owning trees.
        </p>

        <div class="rows">
          <div v-for="r in A_ROWS" :key="r.depth" class="row">
            <div class="gutter">
              <span class="depth">{{ r.depth }}</span>
              <span class="tiny muted">LV {{ r.level }}</span>
            </div>
            <div class="seam wrap">
              <span v-for="(n, i) in r.nodes" :key="i" class="cell">
                <span class="node" :class="n.state">
                  <span class="hex"><span class="face">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                      <path :d="ACTION_PATHS[n.icon]" />
                    </svg>
                  </span></span>
                </span>
                <span v-if="n.needs" class="needs">{{ n.needs }}</span>
              </span>
            </div>
          </div>
        </div>

        <p class="tiny muted foot">
          <strong>The cost:</strong> ~460 nodes go away, and with them §7.2's specialisation —
          a hundred points against thirty-four nodes fills the tree, so every character ends
          up the same. Keeping a build means either far fewer points than nodes, or letting
          the job gates be the real limit.
        </p>
      </div>
    </section>

    <!-- ---------------------------------------------------------------- B -->
    <section v-if="shown === 'b'" class="plate">
      <div class="inner">
        <p class="lede">
          <strong>One tree, the jobs as branches.</strong> Every node kept and every job kept;
          what goes is the picker. One diagram, each branch gated by its own job level, so what
          you can reach is still what you have actually done.
        </p>

        <div class="spine">
          <div v-for="b in B_BRANCHES" :key="b.name" class="branch">
            <span class="tag">{{ b.name }} <span class="muted">{{ b.gate }}</span></span>
            <span class="wire" />
            <span v-for="(n, i) in b.nodes" :key="i" class="node" :class="n.state">
              <span class="hex"><span class="face">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <path :d="ACTION_PATHS[n.icon]" />
                </svg>
              </span></span>
            </span>
            <span class="tiny muted more">+25 more</span>
          </div>
        </div>

        <p class="tiny muted foot">
          <strong>The cost:</strong> nothing is lost and nothing is simplified either — 495 nodes
          is 495 nodes, and seventeen branches down one page is a long scroll on a phone. This
          simplifies the SCREEN, not the system.
        </p>
      </div>
    </section>

    <!-- ---------------------------------------------------------------- C -->
    <section v-if="shown === 'c'" class="plate">
      <div class="inner">
        <p class="lede">
          <strong>One tree per kind — five, not seventeen.</strong> Whichever job you did feeds
          its kind's tree: felling and quarrying both level Gathering, the saw pit and the
          tannery both level Processing. A middle path.
        </p>

        <div class="kinds">
          <div v-for="k in C_KINDS" :key="k.name" class="kind">
            <span class="tag">{{ k.name }}</span>
            <div class="rows tight">
              <div v-for="(row, i) in k.rows" :key="i" class="seam">
                <span v-for="(n, j) in row" :key="j" class="node" :class="n.state">
                  <span class="hex"><span class="face">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                      <path :d="ACTION_PATHS[n.icon]" />
                    </svg>
                  </span></span>
                </span>
              </div>
            </div>
          </div>
        </div>

        <p class="tiny muted foot">
          <strong>The cost:</strong> the five lines stop being told apart — a Sawyer node and a
          Weaver node become one Processing node, so §7.4's "no two trees are the same tree"
          survives across kinds and dies inside them. Specialisation is still real: three kinds
          of five.
        </p>
      </div>
    </section>

    <p class="tiny muted tail">
      Whichever shape wins, two things stay: a row opens at a level, and a node may name a
      second gate. That is what §9.5.9's battle skills already do, and it is the one part of
      today's design every shape here keeps.
    </p>
  </div>
</template>

<style scoped>
.page {
  max-width: 940px;
  margin: 0 auto;
  padding: 22px 16px 60px;
  color: var(--vellum);
}

.head h1 {
  font-family: Bitter, Georgia, serif;
  font-size: 24px;
  font-weight: 600;
  margin: 0 0 4px;
}

.tiny { font-size: 12px; line-height: 1.55; }
.muted { color: var(--vellum-dim); }

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 18px 0 12px;
}

.tabs button {
  clip-path: var(--plate-clip);
  background: var(--ink-panel);
  border: 0;
  color: var(--vellum-dim);
  padding: 8px 14px;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}

.tabs button.on {
  background: var(--copper);
  color: var(--ink);
}

.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 1px;
  background: var(--line);
  margin: 0 0 14px;
}

.facts div {
  background: var(--ink-panel);
  padding: 8px 10px;
}

.facts dt {
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #8a938d;
}

.facts dd { margin: 2px 0 0; font-size: 13px; }

.plate {
  background: var(--line);
  clip-path: var(--plate-clip);
}

.plate > .inner {
  background: var(--ink-panel);
  margin: 1px;
  clip-path: var(--plate-clip);
  padding: 16px;
}

.lede { font-size: 13px; line-height: 1.6; margin: 0 0 14px; max-width: 64ch; }
.foot { margin: 14px 0 0; max-width: 64ch; border-top: 1px solid var(--line); padding-top: 10px; }
.tail { margin: 16px 0 0; max-width: 64ch; }

/* ------------------------------------------------------------- the picker */
.picker {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 5px;
  margin-bottom: 16px;
}

.job {
  clip-path: var(--plate-clip);
  background: var(--ink-raised);
  border: 0;
  color: var(--vellum-dim);
  padding: 7px 8px;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}

.job.on { color: var(--vellum); background: var(--line); }

/* --------------------------------------------------------------- the rows */
.rows { display: flex; flex-direction: column; }
.rows.tight { gap: 4px; }

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid var(--line);
}

.gutter {
  width: 56px;
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  border-right: 2px solid var(--line);
  padding-right: 8px;
}

.depth { font-family: Bitter, Georgia, serif; font-size: 17px; color: var(--vellum-dim); }

.seam { display: flex; gap: 6px; align-items: center; }
.seam.wrap { flex-wrap: wrap; row-gap: 10px; }

.cell { display: flex; flex-direction: column; align-items: center; gap: 3px; }

/*
 * §9.5.9 -- a node with a gate of its own says so on the node, because that is
 * the fact that decides whether the point can be spent at all.
 */
.needs {
  font-size: 9px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--copper);
  white-space: nowrap;
}

/* ------------------------------------------------------- nodes, as they are */
.node { color: #6d7770; }

.node .hex {
  display: block;
  width: 46px;
  height: 40px;
  background: var(--line);
  clip-path: var(--hex-clip);
}

.node .face {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  background: var(--ink-panel);
  clip-path: var(--hex-clip);
  transform: scale(0.88);
}

.node.owned { color: #1a120a; }
.node.owned .hex, .node.owned .face { background: var(--copper); }

.node.open { color: var(--vellum); }
.node.open .hex { background: var(--vellum); }
.node.open .face { background: var(--ink-raised); }

/* ------------------------------------------------------------- B: branches */
.spine { display: flex; flex-direction: column; gap: 8px; }

.branch {
  display: flex;
  align-items: center;
  gap: 6px;
  padding-left: 12px;
  border-left: 2px solid var(--copper);
}

.tag {
  width: 108px;
  flex: 0 0 auto;
  font-size: 12px;
  color: var(--vellum-dim);
}

.wire { width: 14px; height: 2px; background: var(--line); flex: 0 0 auto; }
.more { margin-left: 4px; }

/* ---------------------------------------------------------------- C: kinds */
.kinds { display: flex; flex-direction: column; gap: 12px; }

.kind {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--line);
}

.kind .tag { padding-top: 12px; }
</style>
