<script setup lang="ts">
/**
 * §13 -- which corners the chamfer cuts, and whether the choice could mean
 * something.
 *
 * A pitch, not the rules. Nothing here is built.
 *
 * The game allows two shapes: a hexagon for what the map is made of, and a
 * chamfer for everything a hexagon cannot survive being stretched into — a
 * panel, a button, a chip. But the chamfer has only ever been cut on ONE
 * diagonal, top-left and bottom-right, on every one of them. Seventeen
 * hand-written `polygon()`s and one `--plate-clip` token, all pointing the same
 * way.
 *
 * That is a choice nobody made; it is the first one that got written down. So
 * the question is not "should it be mirrored" but **what is the second diagonal
 * FOR** — and a shape that means nothing is worse than a shape that means one
 * thing, however handsome.
 *
 * Each tab below re-cuts the SAME markup by swapping one custom property, which
 * is also the honest answer to "what would this cost": one token, and the
 * seventeen bespoke polygons that would have to come home to it first.
 */
import { computed, ref } from 'vue'
import { ACTION_PATHS } from '@/icons/actions'

type Key = 'today' | 'even' | 'sized' | 'deep'

const SHAPES: Array<{ key: Key; label: string; blurb: string; cost: string }> = [
  {
    key: 'today',
    label: 'Today — two corners',
    blurb:
      'One diagonal, top-left and bottom-right, on every plate, chip, tab and button. '
      + 'Two corners cut and two left square, which is what makes the shape read as a stone '
      + 'that was set down at an angle rather than as a shape with a rule.',
    cost: 'Nothing. This is the game.',
  },
  {
    key: 'even',
    label: 'All four — one radius',
    blurb:
      'Every corner cut, at the radius each element already uses. It stops being a diagonal '
      + 'and becomes an octagon: no direction, no leading corner, and the same shape whichever '
      + 'way you meet it. The cost is that a plate and a chip now differ only in size.',
    cost: 'One token, and 17 bespoke polygons that never used it.',
  },
  {
    key: 'sized',
    label: 'All four — the cut scales with the thing',
    blurb:
      'Every corner cut, but the radius is a share of the smaller side rather than a fixed '
      + 'pixel count. A tall plate gets a deep bevel and a 24px chip a shallow one, so the '
      + 'shape reads the same at every size — which a fixed 16px does not, because on a chip '
      + 'it eats the whole corner and turns an octagon into a lozenge.',
    cost: 'One token that takes a size, and the 17 polygons.',
  },
  {
    key: 'deep',
    label: 'All four — deep',
    blurb:
      'The same octagon with the cut pushed as far as it goes. Worth seeing once: it is where '
      + 'the shape stops being a chamfered rectangle and starts being its own outline, and it '
      + 'is where text begins fighting the corners it sits next to.',
    cost: 'The same, plus padding everywhere to clear it (§13).',
  },
]

const shape = ref<Key>('even')

const active = computed(() => SHAPES.find((s) => s.key === shape.value)!)

/**
 * The two corners the game cuts today, and the octagon that cuts all four.
 *
 * Written as functions of a radius rather than as strings, because the whole
 * question on this page is what the radius should be a function OF.
 */
const two = (r: number) =>
  `polygon(${r}px 0, 100% 0, 100% calc(100% - ${r}px), calc(100% - ${r}px) 100%, 0 100%, 0 ${r}px)`

const four = (r: number) =>
  `polygon(${r}px 0, calc(100% - ${r}px) 0, 100% ${r}px, 100% calc(100% - ${r}px),`
  + ` calc(100% - ${r}px) 100%, ${r}px 100%, 0 calc(100% - ${r}px), 0 ${r}px)`

/**
 * What each part gets. `base` is the radius the game uses there today; `min` is
 * the element's own smaller side, which is what a scaled cut is a share of.
 */
function clip(base: number, min: number): string {
  switch (shape.value) {
    case 'today':
      return two(base)
    case 'even':
      return four(base)
    case 'sized':
      // A sixth of the short side, floored so a chip still shows a cut and
      // capped so a tall plate does not become a hexagon.
      return four(Math.max(3, Math.min(14, Math.round(min / 6))))
    case 'deep':
      return four(Math.round(min / 3))
  }
}

const plate = computed(() => clip(16, 90))
const inset = computed(() => clip(10, 46))
const control = computed(() => clip(7, 34))
const chip = computed(() => clip(5, 24))

const JOBS = [
  { name: 'Explorer', icon: 'travel', n: 11 },
  { name: 'Woodcutting', icon: 'woodcutting', n: 0 },
  { name: 'Mining', icon: 'mining', n: 0 },
  { name: 'Hunting', icon: 'hunting', n: 0 },
]

const ROWS = [
  { name: 'Straps', what: 'Places on the bag to put something.', gain: '+20 straps', rank: '10/13' },
  { name: 'Horizon', what: 'How many hexes of live ground you can read.', gain: '+1 hex', rank: '1/2' },
]

const LADDER = [2, 4, 6, 8, 10, 14, 16]
</script>

<template>
  <div class="page">
    <header>
      <h1>Cutting every corner</h1>
      <p class="tiny muted">
        A pitch, not the rules — nothing here is built. §13 allows a hexagon and a chamfer,
        and the chamfer cuts two corners and leaves two square. This asks what happens when
        it cuts all four. Each tab re-cuts the same sample.
      </p>
    </header>

    <nav class="tabs">
      <button
        v-for="s in SHAPES"
        :key="s.key"
        type="button"
        :class="{ on: shape === s.key }"
        :style="{ clipPath: shape === s.key ? clip(7, 34) : two(6) }"
        @click="shape = s.key"
      >{{ s.label }}</button>
    </nav>

    <p class="lede">{{ active.blurb }}</p>
    <p class="tiny muted cost"><strong>What it would cost:</strong> {{ active.cost }}</p>

    <!-- The sample: a panel of the game's own parts, re-cut by the variant. -->
    <div class="sample" :style="{ clipPath: plate }">
      <div class="sample-in" :style="{ clipPath: plate }">
        <div class="caption">
          <strong>Skill</strong>
          <span class="tiny muted">a panel, an inset, tabs, rows and chips</span>
        </div>

        <div class="purse" :style="{ clipPath: inset }">
          <span class="key">skill points</span>
          <strong class="big">60</strong>
          <span class="tiny muted">of 60 unspent</span>
        </div>

        <div class="bands">
          <button
            v-for="b in ['Gathering', 'Processing', 'Crafting', 'Battle']"
            :key="b"
            type="button"
            :class="{ on: b === 'Gathering' }"
            :style="{ clipPath: control }"
          >{{ b }}</button>
        </div>

        <div class="trades">
          <button
            v-for="j in JOBS"
            :key="j.name"
            type="button"
            :class="{ on: j.name === 'Explorer' }"
            :style="{ clipPath: control }"
          >
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
              <path :d="ACTION_PATHS[j.icon] ?? ACTION_PATHS.skills" />
            </svg>
            <span class="grow">{{ j.name }}</span>
            <span class="tiny muted">{{ j.n }}</span>
          </button>
        </div>

        <ul class="rows">
          <li v-for="r in ROWS" :key="r.name">
            <button type="button" :style="{ clipPath: control }">
              <span class="hex"><span class="face" /></span>
              <span class="grow">
                <span class="name">{{ r.name }}</span>
                <span class="tiny muted">{{ r.what }}</span>
              </span>
              <span class="right">
                <span class="gain">{{ r.gain }}</span>
                <span class="tiny muted">{{ r.rank }}</span>
              </span>
            </button>
          </li>
        </ul>

        <div class="detail" :style="{ clipPath: inset }">
          <div class="caption">
            <strong class="tiny">Straps</strong>
            <span class="tiny muted">rank 10/13</span>
          </div>
          <div class="facts" :style="{ clipPath: chip }">
            <span class="fact now"><span class="key">now</span>+20 straps</span>
            <span class="fact next"><span class="key">next</span>+2 at Explorer 24</span>
          </div>
          <ol class="ladder">
            <li v-for="(lv, i) in LADDER" :key="lv" :class="{ got: i < 5 }" :style="{ clipPath: chip }">
              <span class="lv">{{ lv }}</span><span class="run">+{{ (i + 1) * 2 }}</span>
            </li>
          </ol>
          <div class="foot">
            <span class="tiny muted">One point.</span>
            <button type="button" class="go" :style="{ clipPath: control }">Learn · 1 point</button>
          </div>
        </div>
      </div>
    </div>

    <p class="tiny muted tail">
      Two corners or four, the work is the same and it is worth naming: the cut lives in one
      token (<code>--plate-clip</code>) and in <strong>seventeen hand-written polygons</strong>
      that never used it. Any variant past "today" means bringing those home first — worth
      doing on its own, because a shape §13 claims has one rule currently has eighteen.
      <br><br>
      The thing to judge is what the fourth and third corners cost: today's cut has a
      <em>direction</em>, and an octagon does not. What it buys is a shape that reads the same
      whichever corner you meet first, which matters most on the small things — a chip, a tab,
      a rank on the ladder — where a diagonal is too big a gesture to land at all.
    </p>
  </div>
</template>

<style scoped>
.page {
  max-width: 720px;
  margin: 0 auto;
  padding: 22px 16px 60px;
  color: var(--vellum);
}

h1 {
  font-family: Bitter, Georgia, serif;
  font-size: 23px;
  font-weight: 600;
  margin: 0 0 4px;
}

.tiny { font-size: 12px; line-height: 1.55; }
.muted { color: var(--vellum-dim); }
.key { font-size: 9px; letter-spacing: 0.1em; text-transform: uppercase; color: #6d7770; }

.tabs { display: flex; flex-wrap: wrap; gap: 5px; margin: 18px 0 12px; }

.tabs button {
  background: var(--ink-panel);
  border: 0;
  color: var(--vellum-dim);
  padding: 8px 13px;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}

.tabs button.on { background: var(--copper); color: var(--ink); }

.lede { font-size: 13px; line-height: 1.6; margin: 0 0 6px; max-width: 66ch; }
.cost { margin: 0 0 16px; }
.tail { margin: 18px 0 0; max-width: 66ch; }
code { font-size: 11px; color: var(--copper); }

/* -------------------------------------------------------------- the sample */

.sample { background: var(--line); }
.sample-in { background: var(--ink-panel); margin: 1px; padding: 14px; }

/* `caption`, not `bar`: app.css already owns `.bar` as a 5px progress track
   with `overflow: hidden`, which swallowed the text and drew a copper line. */
.caption { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 10px; }

.purse {
  display: flex;
  align-items: baseline;
  gap: 8px;
  background: var(--ink-raised);
  padding: 10px 12px;
  margin-bottom: 10px;
}

.big { font-family: Bitter, Georgia, serif; font-size: 21px; color: var(--copper); line-height: 1; }

.bands { display: flex; gap: 3px; margin-bottom: 7px; }

.bands button {
  flex: 1;
  padding: 7px 4px;
  border: 0;
  background: var(--ink-panel);
  color: #6d7770;
  font: inherit;
  font-size: 11px;
  cursor: pointer;
}

.bands button.on { background: var(--line); color: var(--vellum); }

.trades { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; margin-bottom: 10px; }

.trades button {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 9px;
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font: inherit;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}

.trades button.on { background: var(--ink-raised); border-color: var(--copper); color: var(--vellum); }
.trades svg { flex: 0 0 auto; color: #6d7770; }
.grow { flex: 1; min-width: 0; text-align: left; }

.rows { list-style: none; margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 3px; }

.rows button {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 11px 8px 7px;
  border: 0;
  background: var(--ink-raised);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.rows .grow { display: flex; flex-direction: column; gap: 2px; }
.rows .name { font-size: 13px; }
.rows .right { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.gain { font-size: 13px; color: var(--sap); }

.hex { display: block; width: 36px; height: 31px; background: var(--line); clip-path: var(--hex-clip); flex: 0 0 auto; }
.face { display: block; width: 100%; height: 100%; background: var(--ink-panel); clip-path: var(--hex-clip); transform: scale(0.88); }

.detail { background: var(--ink-raised); padding: 11px; }

.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  padding: 7px 10px;
  background: var(--ink-panel);
}

.fact { display: inline-flex; align-items: baseline; gap: 5px; font-size: 13px; white-space: nowrap; }
.fact.now { color: var(--sap); }
.fact.next { color: var(--copper); }

.ladder { list-style: none; margin: 6px 0 0; padding: 0; display: flex; gap: 4px; overflow-x: auto; }

.ladder li {
  display: flex;
  align-items: baseline;
  gap: 5px;
  flex: 0 0 auto;
  padding: 4px 9px;
  background: var(--ink-panel);
  color: #6d7770;
  font-size: 11px;
}

.ladder li.got { color: var(--vellum); }
.ladder li.got .run { color: var(--sap); }
.ladder .lv { font-size: 10px; opacity: 0.75; }

.foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; }

.go {
  border: 0;
  background: var(--copper);
  color: var(--ink);
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  padding: 7px 14px;
  cursor: pointer;
}
</style>
