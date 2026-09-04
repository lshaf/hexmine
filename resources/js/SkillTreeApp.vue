<script setup lang="ts">
/**
 * §7.4 -- the skill screen as a short list of LEVELLED skills.
 *
 * A pitch, not the rules. Nothing here is built.
 *
 * Today a job is thirty discrete nodes drawn as a diagram, and each node is a
 * separate named thing you buy once. But the thirty are not thirty ideas --
 * they are a handful of effects repeated. Explorer's fifteen nodes are exactly
 * two: straps, thirteen times, and sight, twice. Woodcutting's thirty are four.
 * Every name in between is a different word for the same +2.
 *
 * So a skill is one entry with RANKS. Each rank carries its own level gate --
 * which is what the tiers already do, one row at a time -- and a rank may sit
 * behind a level of its own, which is what §9.5.9's battle skills already do.
 * Tapping one answers the three questions a player actually has: when can I
 * take the next one, what does it give, and what am I getting right now from
 * every rank I already own.
 *
 * The data is the REAL tree, collapsed (skillSample.ts). The argument is
 * arithmetic about the tree that exists, and invented names could not show it.
 */
import { computed, ref } from 'vue'
import { ACTION_PATHS } from '@/icons/actions'
import { SAMPLE, type Skill } from './skillSample'

const ICON: Record<string, keyof typeof ACTION_PATHS> = {
  bagSlots: 'effectBagSlots',
  sight: 'effectSight',
  stat: 'effectStat',
  pair: 'effectStat',
  bite: 'effectStat',
  toolWear: 'effectCraftDurability',
  battleWear: 'effectCraftDurability',
  weaponWear: 'effectCraftDurability',
  seamGrade: 'effectSeam',
  goldFind: 'effectGold',
  lootOption: 'effectCraftOption',
  skillPower: 'effectSkillPower',
  skillCooldown: 'effectSkillCooldown',
  skillStun: 'effectSkillStun',
  battleSkill: 'battle',
}

const JOBS = [
  { key: 'explorer', name: 'Explorer', level: 11, note: 'Granted by walking — costs no point.' },
  { key: 'woodcutting', name: 'Woodcutting', level: 14, note: 'Bought with skill points.' },
  { key: 'swordhand', name: 'Swordhand', level: 13, note: 'Bought with skill points.' },
]

const job = ref(JOBS[0]!)
const skills = computed<Skill[]>(() => SAMPLE[job.value.key] ?? [])

/** How many ranks the level you have already reaches. */
const takenOf = (s: Skill) => s.ranks.filter((r) => r.level <= job.value.level).length

/**
 * §7.4.3 -- a count is a count and a share is a percentage, and which one is a
 * fact about the EFFECT rather than about a format string. Straps, hexes, bite
 * and the solid pair are whole things you can point at; the rest are shares of
 * some work.
 *
 * It read the printf template to decide, and every template has a `%` in it --
 * so ten straps printed as "1000%".
 */
const COUNTS: Record<string, string> = {
  bagSlots: 'straps',
  sight: 'hexes of sight',
  bite: 'attack',
  pair: 'points',
  skillCooldown: 'rounds',
  skillStun: 'round',
  battleSkill: '',
}

const amount = (s: Skill, v: number) => {
  const unit = COUNTS[s.kind]
  if (unit === undefined) return `${(v * 100).toFixed(1).replace(/\.0$/, '')}%`
  if (s.kind === 'battleSkill') return v ? 'Learned' : 'Not learned'

  return `+${v} ${unit}`.trimEnd()
}

/** §7.4 -- what every rank you own already adds up to. */
const total = (s: Skill) =>
  s.ranks.slice(0, takenOf(s)).reduce((n, r) => n + r.value, 0)

const nextRank = (s: Skill) => s.ranks[takenOf(s)] ?? null

const picked = ref<string | null>('Straps')
const chosen = computed(() => skills.value.find((s) => s.name === picked.value) ?? null)

function pick(s: Skill): void {
  picked.value = picked.value === s.name ? null : s.name
}
</script>

<template>
  <div class="page">
    <header class="head">
      <h1>Skills, with levels</h1>
      <p class="tiny muted">
        A pitch, not the rules — nothing here is built. Thirty nodes a job become a short
        list of skills that <em>rank up</em>. The data is the real tree, collapsed.
      </p>
    </header>

    <nav class="tabs">
      <button
        v-for="j in JOBS"
        :key="j.key"
        type="button"
        :class="{ on: job.key === j.key }"
        @click="job = j; picked = null"
      >
        {{ j.name }} <span class="muted">{{ SAMPLE[j.key]?.length }} skills</span>
      </button>
    </nav>

    <p class="tiny muted was">
      <strong>{{ job.name }}</strong> — {{ job.note }}
      Today: <span class="strike">{{ job.key === 'explorer' ? 15 : 30 }} separate nodes</span>.
      Here: {{ skills.length }} skills, {{ skills.reduce((n, s) => n + s.ranks.length, 0) }} ranks between them.
      You are level {{ job.level }}.
    </p>

    <div class="split">
      <!-- ------------------------------------------------------ the list -->
      <ul class="list">
        <li v-for="s in skills" :key="s.name">
          <button type="button" class="skill" :class="{ on: picked === s.name }" @click="pick(s)">
            <span class="node" :class="{ owned: takenOf(s) > 0 }">
              <span class="hex"><span class="face">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <path :d="ACTION_PATHS[ICON[s.kind] ?? 'effectStat']" />
                </svg>
              </span></span>
            </span>

            <span class="body">
              <span class="name">{{ s.name }}</span>
              <span class="tiny muted what">{{ s.what }}</span>
            </span>

            <span class="right">
              <!-- The one number a list of skills is scanned for. -->
              <span class="rank">{{ takenOf(s) }}<span class="muted"> / {{ s.ranks.length }}</span></span>
              <span class="pips" aria-hidden="true">
                <i v-for="i in s.ranks.length" :key="i" :class="{ got: i <= takenOf(s) }" />
              </span>
            </span>
          </button>
        </li>
      </ul>

      <!-- ----------------------------------------------------- the detail -->
      <aside v-if="chosen" class="plate detail">
        <div class="inner">
          <h2>{{ chosen.name }}</h2>
          <p class="tiny muted">{{ chosen.what }}</p>

          <!-- The three questions, in the order they are asked. -->
          <dl class="answers">
            <div class="answer now">
              <dt>You have now</dt>
              <dd v-if="chosen.kind === 'battleSkill'">
                <strong>{{ takenOf(chosen) ? 'Learned' : 'Not learned' }}</strong>
                <span class="tiny muted">owning it is the whole effect</span>
              </dd>
              <dd v-else>
                <strong>{{ amount(chosen, total(chosen)) }}</strong>
                <span class="tiny muted">from {{ takenOf(chosen) }} rank<span v-if="takenOf(chosen) !== 1">s</span></span>
              </dd>
            </div>

            <div v-if="nextRank(chosen)" class="answer next">
              <dt>Next rank</dt>
              <dd>
                <strong v-if="chosen.kind === 'battleSkill'">Learn it</strong>
                <strong v-else>{{ amount(chosen, nextRank(chosen)!.value) }} more</strong>
                <span class="tiny">at {{ job.name }} level {{ nextRank(chosen)!.level }}</span>
              </dd>
            </div>
            <div v-else class="answer done">
              <dt>Next rank</dt>
              <dd><strong>Maxed</strong><span class="tiny muted">every rank taken</span></dd>
            </div>
          </dl>

          <!-- Every rank, so "when can I take it" is answered for all of them
               at once rather than one at a time. -->
          <ol class="ladder">
            <li
              v-for="(r, i) in chosen.ranks"
              :key="i"
              :class="{ got: i < takenOf(chosen), next: i === takenOf(chosen) }"
            >
              <span class="lv">lv {{ r.level }}</span>
              <span class="gain">{{ chosen.kind === 'battleSkill' ? 'learn' : amount(chosen, r.value) }}</span>
              <span v-if="chosen.ranks.length > 1" class="run tiny muted">
                {{ amount(chosen, chosen.ranks.slice(0, i + 1).reduce((n, x) => n + x.value, 0)) }} total
              </span>
            </li>
          </ol>
        </div>
      </aside>

      <aside v-else class="plate detail empty">
        <div class="inner"><p class="tiny muted">Tap a skill.</p></div>
      </aside>
    </div>

    <p class="tiny muted tail">
      Every rank carries its own level, which is what the five tiers already do a row at a
      time. A one-rank skill behind a level of its own is what §9.5.9's battle skills already
      are — Onslaught, Sunder and Riposte are on the Swordhand tab, sitting in the same list
      as the levelled ones.
    </p>
  </div>
</template>

<style scoped>
.page { max-width: 940px; margin: 0 auto; padding: 22px 16px 60px; color: var(--vellum); }

.head h1 { font-family: Bitter, Georgia, serif; font-size: 24px; font-weight: 600; margin: 0 0 4px; }
.tiny { font-size: 12px; line-height: 1.55; }
.muted { color: var(--vellum-dim); }
.strike { text-decoration: line-through; opacity: 0.7; }

.tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 18px 0 10px; }

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

.tabs button.on { background: var(--copper); color: var(--ink); }
.tabs button.on .muted { color: rgba(20, 27, 24, 0.7); }

.was { margin: 0 0 14px; max-width: 70ch; }

.split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 300px); gap: 12px; align-items: start; }

@media (max-width: 720px) { .split { grid-template-columns: minmax(0, 1fr); } }

/* ---------------------------------------------------------------- the list */
.list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }

.skill {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 7px 12px 7px 7px;
  clip-path: var(--plate-clip);
  background: var(--ink-panel);
  border: 0;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.skill.on { background: var(--line); }

.body { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.name { font-size: 14px; }
.what { display: block; }

.right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex: 0 0 auto; }
.rank { font-size: 13px; font-variant-numeric: tabular-nums; }

/* A rank strip rather than a bar: an empty rank is the same shape as a full
   one, so what is left is seen rather than subtracted (§7.6's own argument). */
.pips { display: flex; gap: 2px; }
.pips i { width: 5px; height: 9px; background: var(--line); display: block; }
.pips i.got { background: var(--copper); }

/* ------------------------------------------------------- nodes, as they are */
.node { color: #6d7770; flex: 0 0 auto; }
.node .hex { display: block; width: 40px; height: 35px; background: var(--line); clip-path: var(--hex-clip); }

.node .face {
  display: grid; place-items: center; width: 100%; height: 100%;
  background: var(--ink-panel); clip-path: var(--hex-clip); transform: scale(0.88);
}

.node.owned { color: #1a120a; }
.node.owned .hex, .node.owned .face { background: var(--copper); }

/* -------------------------------------------------------------- the detail */
.plate { background: var(--line); clip-path: var(--plate-clip); position: sticky; top: 16px; }
.plate > .inner { background: var(--ink-panel); margin: 1px; clip-path: var(--plate-clip); padding: 14px; }
.detail h2 { font-family: Bitter, Georgia, serif; font-size: 17px; font-weight: 600; margin: 0 0 2px; }
.empty .inner { padding: 20px 14px; }

.answers { margin: 12px 0 0; display: flex; flex-direction: column; gap: 1px; background: var(--line); }
.answer { background: var(--ink-panel); padding: 8px 10px; border-left: 2px solid var(--line); }

/* §13.3 -- sap for what you already have, copper for the work still ahead. */
.answer.now { border-left-color: var(--sap); }
.answer.next { border-left-color: var(--copper); }

.answer dt { font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: #8a938d; }
.answer dd { margin: 3px 0 0; display: flex; align-items: baseline; gap: 6px; }
.answer dd strong { font-size: 16px; font-variant-numeric: tabular-nums; }
.answer.now dd strong { color: var(--sap); }
.answer.next dd strong { color: var(--copper); }
.answer.next dd .tiny { color: var(--vellum-dim); }

/* ------------------------------------------------------------- the ladder */
.ladder { list-style: none; margin: 12px 0 0; padding: 0; display: flex; flex-direction: column; }

.ladder li {
  display: grid;
  grid-template-columns: 44px minmax(74px, auto) 1fr;
  gap: 6px;
  align-items: baseline;
  padding: 4px 0;
  border-top: 1px solid var(--line);
  color: #6d7770;
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}

.ladder li.got { color: var(--vellum); }
.ladder li.got .gain { color: var(--sap); }
.ladder li.next { color: var(--vellum); }
.ladder li.next .lv { color: var(--copper); }
.lv { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
.run { text-align: right; }

.tail { margin: 18px 0 0; max-width: 70ch; }
</style>
