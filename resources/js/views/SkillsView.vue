<script setup lang="ts">
/**
 * The trades, §7.4.
 *
 * A job is a short list of levelled SKILLS, and the panel's one job is to
 * answer "what can I take right now" without the player counting anything.
 *
 * ── Why a list, and why not a tree ───────────────────────────────────────────
 * It was thirty nodes drawn as five strata, and the thirty were never thirty
 * ideas: they were a handful of effects repeated under different names.
 * Explorer's fifteen were two -- straps thirteen times and sight twice -- and
 * Deep Pockets, Second Strap, Rolled Blanket, Even Load, Side Pouch, Bindle,
 * Sorted Kit, Tump Line, Packer's Knot, Outer Pockets and Long Haul were eleven
 * different words for the same +2. A diagram of that is a diagram of a list.
 *
 * So a skill is one row that ranks up, and tapping it answers the three
 * questions a player actually has, in the order they are asked: what you have
 * now, when the next one comes, and the whole ladder underneath -- so "when can
 * I take it" is answered for every rank at once rather than one at a time.
 *
 * The ranks draw as a pip strip rather than a bar. An empty rank is the same
 * shape as a full one, so what is left is seen rather than subtracted, which is
 * §7.6's own argument about the bag comb.
 *
 * Everything the server decides stays server-decided (§16): this draws state and
 * posts one key. It never computes affordability as truth, only as a hint about
 * which button to light.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { ACTION_PATHS, JOB_PATHS } from '@/icons/actions'
import { MATERIAL_PALETTE } from '@/theme/palette'
import { formatPercent, formatStat } from '@/game/formulas'
import { STAT_LABEL } from '@/game/catalog'
import type { NodeEffect, SkillDef } from '@/api/types'
import type { StatKey } from '@/game/types'

const game = useGame()

/** Woodcutting first: it is the line the opening arc puts in your hands. */
const job = ref<string>('woodcutting')

/**
 * §7.4 -- which band of jobs is open.
 *
 * The four used to stack; they are tabs now, so one is showing at a time and
 * the picker is two rows instead of eight. It follows the selected job rather
 * than being independent, because picking a job in another band and finding the
 * tab still on this one would be the two disagreeing about what you are looking
 * at.
 */
const band = ref<string>('gathering')

onMounted(() => {
  void game.loadTree()
  void game.loadBattleSkills()
})


/** A different job is a different sheet; keep nothing selected across it. */
watch(job, (key) => {
  picked.value = null

  // Follow the job into its own band, so the tab and the sheet never disagree
  // about what is being looked at.
  const kind = tree.value?.jobs[key]?.kind
  if (kind) band.value = bandOf(kind)
})

const tree = computed(() => game.tree)

/**
 * Grouped in the order a character meets them: you walk and gather from the
 * first minute, refine what you gathered, craft once there is something to
 * craft with, and raid when raiding exists. The server sends them in this order
 * already; this only cuts them into their four bands so the panel does not read
 * as seventeen equal choices.
 *
 * "Gathering" rather than "Mining" for the first band, because one of the jobs
 * inside it is called Mining and a heading that repeats a row underneath it is
 * a heading nobody trusts.
 */
const KIND_LABEL: Record<string, string> = {
  gathering: 'Gathering',
  processing: 'Processing',
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

/**
 * §7.4 -- this job's skills, each with what you hold and what comes next.
 *
 * Ordered by the level that opens the first rank, which is the order a player
 * meets them in; the server already sends them that way and this only filters.
 */
interface Row {
  key: string
  def: SkillDef
  rank: number
  ranks: number
  /** The level that opens the next rank, or null at the top. */
  nextLevel: number | null
  /** Reachable right now: the job level is there and, if bought, a point is. */
  open: boolean
  maxed: boolean
}

const rows = computed<Row[]>(() => {
  const t = tree.value
  if (!t) return []

  const level = jobRow.value.level
  const points = game.skillPoints.available

  return Object.entries(t.skills)
    .filter(([, def]) => def.job === job.value)
    .map(([key, def]) => {
      const rank = game.rankOf(key)
      const next = def.ranks[rank] ?? null

      return {
        key,
        def,
        rank,
        ranks: def.ranks.length,
        nextLevel: next?.level ?? null,
        maxed: next === null,
        open:
          next !== null && level >= next.level && (automatic.value || points >= 1),
      }
    })
})

/** What a skill is worth to you now: every rank you hold, added up. */
function heldValue(row: Row): number {
  const t = tree.value
  if (!t) return 0

  let sum = 0
  for (let i = 0; i < row.rank; i++) {
    const node = t.nodes[row.def.ranks[i]!.node]
    if (node && 'value' in node.effect) sum += node.effect.value
  }

  return sum
}

/**
 * What every rank you hold adds up to, said as a phrase.
 *
 * A helper rather than a spread in the template: spreading a discriminated
 * union widens `value` off the variant, and the compiler stops being able to
 * see which effect it is holding.
 */
function heldPhrase(row: Row): string {
  const effect = rankEffect(row, 1)
  if (effect === null) return ''

  const total = heldValue(row)

  return effectPhrase(
    'value' in effect ? ({ ...effect, value: total } as NodeEffect) : effect,
    scopedName.value,
  )
}

/**
 * What you would hold at a given rank: every rank up to it, added up.
 *
 * The ladder used to print each rank's own gain, which is the wrong number to
 * read down a column -- "+2, +2, +2" says nothing about where you end up, and
 * where you end up is the question a ladder is read for.
 */
function totalAt(row: Row, rank: number): string {
  const effect = rankEffect(row, 1)
  if (effect === null || !('value' in effect)) return ''

  const t = tree.value
  let sum = 0
  for (let i = 0; i < rank; i++) {
    const node = t?.nodes[row.def.ranks[i]!.node]
    if (node && 'value' in node.effect) sum += node.effect.value
  }

  return effectValue({ ...effect, value: sum } as NodeEffect)
}

/** The effect one rank carries, read off the node that carries it. */
function rankEffect(row: Row, rank: number): NodeEffect | null {
  const entry = row.def.ranks[rank - 1]

  return entry ? (tree.value?.nodes[entry.node]?.effect ?? null) : null
}

/** Why this rank is not takeable, in the player's terms. */
function reasonFor(row: Row): string {
  if (row.maxed) return 'Every rank taken.'
  if (jobRow.value.level < row.nextLevel!) {
    return `Needs ${jobDef.value?.name} level ${row.nextLevel}.`
  }
  if (!automatic.value && game.skillPoints.available < 1) return 'No points left.'

  return automatic.value ? 'The road has paid for this.' : 'One point.'
}

const picked = ref<string | null>(null)

const chosen = computed(() => rows.value.find((r) => r.key === picked.value) ?? null)

/** §7.5 -- ranks the road has paid for and not yet handed over. */
function claimable(jobKey: string): number {
  const t = tree.value
  if (!t || !(t.automatic ?? []).includes(jobKey)) return 0

  const level = game.jobLevels.find((j) => j.key === jobKey)?.level ?? 1
  let waiting = 0

  for (const [key, def] of Object.entries(t.skills)) {
    if (def.job !== jobKey) continue
    const held = game.rankOf(key)
    const earned = def.ranks.filter((r) => level >= r.level).length
    waiting += Math.max(0, earned - held)
  }

  return waiting
}

/**
 * §9.5.9 -- what a skill does, in its own words.
 *
 * A battle skill's sentence is the server's, built from this character's tree,
 * so it is read off the battle-skill list rather than off the generated blurb:
 * every one of the three would otherwise say "one of the three a Swordhand
 * carries into a fight", which is true of all of them and useful about none.
 */
function describe(row: Row): string {
  if (row.def.kind !== 'battleSkill') return row.def.description

  const effect = rankEffect(row, 1)

  return effect !== null && effect.kind === 'battleSkill'
    ? skillLine(effect.skill)
    : row.def.description
}

/**
 * The glyph for a skill, which is its kind except where the kind holds two.
 *
 * §9.5.4 -- a `pair` skill is attack or defense, and two rows a line apart
 * carrying the same drawing is the one thing that stops a list being
 * scannable.
 */
function iconFor(row: Row): string {
  if (row.def.kind !== 'pair') return EFFECT_ICON[row.def.kind]

  const effect = rankEffect(row, 1)

  return effect !== null && effect.kind === 'pair' && effect.stat === 'defense'
    ? 'effectDefense'
    : 'effectAttack'
}

/** The jobs on the open tab. */
const shownJobs = computed<string[]>(
  () => groups.value.find((g) => g.kind === band.value)?.keys ?? [],
)

/**
 * §7.5 -- free ranks waiting anywhere behind a tab.
 *
 * A closed tab is the one place a claim can hide, and the whole reason the
 * count is coloured is that a wayfaring rank costs nothing and is worth being
 * told about.
 */
function waitingIn(kind: string): number {
  const keys = groups.value.find((g) => g.kind === kind)?.keys ?? []

  return keys.reduce((n, key) => n + claimable(key), 0)
}

/** Ranks held in a job, for the picker's tally. */
function learnedIn(jobKey: string): number {
  const t = tree.value
  if (!t) return 0

  let held = 0
  for (const [key, def] of Object.entries(t.skills)) {
    if (def.job === jobKey) held += game.rankOf(key)
  }

  return held
}

/** How much of this job is learned, for the header. */
const progress = computed(() => ({
  owned: rows.value.reduce((n, r) => n + r.rank, 0),
  total: rows.value.reduce((n, r) => n + r.ranks, 0),
}))

const EFFECT_ICON: Record<NodeEffect['kind'], string> = {
  // §9.5.9 -- the battle glyph, so a skill reads as a skill in the seam rather
  // than as one more stat node with a different tooltip.
  battleSkill: 'battle',
  sight: 'effectSight',
  stat: 'effectStat',
  // §9.5.4 -- attack and defense are two skills now, so they are two glyphs.
  // The kind alone cannot tell them apart; iconFor() reads the stat.
  pair: 'effectStat',
  // §9.5.6 -- two streams, two marks. They shared the durability shield, which
  // put three shields in a row once Defense took that shape (§9.5.4).
  battleWear: 'effectKitWear',
  weaponWear: 'effectBladeWear',
  toolWear: 'effectCraftDurability',
  bite: 'effectStat',
  skillPower: 'effectSkillPower',
  skillCooldown: 'effectSkillCooldown',
  skillStun: 'effectSkillStun',
  seamGrade: 'effectSeam',
  presence: 'effectPresence',
  runSlot: 'effectRunSlot',
  goldFind: 'effectGold',
  lootOption: 'effectCraftOption',
  craftOption: 'effectCraftOption',
  optionTier: 'effectCraftOption',
  craftDurability: 'effectCraftDurability',
  brewExtra: 'effectBrew',
  stackCap: 'effectShelfDepth',
  costReduction: 'effectCostReduction',
  batch: 'effectBatch',
  bagSlots: 'effectBagSlots',
}

/**
 * §7.4.3 -- what a node does, with no figure anywhere in it.
 *
 * The convention the genre settled on and §9.5.9 already follows on the fight
 * plate: a plain verb-first line saying what happens, and every number in a
 * LABELLED ROW under it. GW2 writes "Bash your foe with your shield and stun
 * them", then `Stun: 2 seconds`.
 *
 * It used to be one sentence with the figure buried in the middle of it --
 * "+1.5% of mines come up a grade better" -- which is the hardest possible
 * place to compare two nodes, because the eye has to find the number before it
 * can weigh anything. Split, three nodes read down a column.
 */
/**
 * What the node does, as one phrase with the number in it.
 *
 * "+1% woodcutting yield", not "Raises your Yield, on this job's work only."
 * over a separate row reading "+1%". Two nodes are compared by reading two of
 * these side by side, and a sentence with the figure buried in it is the
 * hardest possible shape to do that from.
 *
 * The job's name is in the phrase wherever the effect is line-locked (§7.4.3),
 * because "+1% yield" and "+1% yield ON THIS LINE" are different offers and the
 * lock is the whole reason a tree is worth choosing between.
 */
/**
 * §7.4.3 -- the kinds whose value is a BILL SPARED rather than a gain.
 *
 * `battleWear` is "a share of what a fight takes off the worn kit, spared", and
 * the other two read the same way: fewer materials, less wear on the blade. The
 * stored value is positive because it is a share, so every figure drawn from it
 * has to be negated -- and it has to be negated in ONE place, or the sentence
 * and the rows disagree. They did: the card read "+2.5% armor wear in a fight"
 * over a row reading "−2.5% of −15%", which is the same node arguing with
 * itself about whether it helps.
 */
const REDUCES: ReadonlySet<NodeEffect['kind']> = new Set(['battleWear', 'weaponWear', 'costReduction'])

/** The figure as it should READ: negative where the node spares a bill. */
function signed(effect: NodeEffect): number {
  const value = 'value' in effect ? effect.value : 0

  return REDUCES.has(effect.kind) ? -value : value
}

function effectPhrase(effect: NodeEffect, jobName?: string): string {
  // §9.5.9 -- the one effect with no figure of its own: what it teaches has its
  // own sentence, built server-side from this character's tree.
  if (effect.kind === 'battleSkill') return skillLine(effect.skill)

  const on = jobName ? `${jobName.toLowerCase()} ` : ''
  const pct = formatPercent(signed(effect))
  const n = effect.value

  switch (effect.kind) {
    case 'stat':
      // formatStat, not the local sign: a StatKey may be stored as a share of
      // what it removes rather than what it adds, and that rule belongs to the
      // one function every other screen reads a stat through.
      return `${formatStat(effect.stat, effect.value)} ${on}${STAT_LABEL[effect.stat as StatKey].toLowerCase()}`
    case 'pair':
      return `+${n} ${effect.stat}`
    case 'bite':
      return `+${n} ${on}mining attack`
    case 'battleWear':
      return `${pct} armor wear in a fight`
    case 'weaponWear':
      return `${pct} weapon wear on your blade`
    case 'costReduction':
      return `${pct} materials per craft`
    case 'toolWear':
      return `${pct} chance a mine spares the tool`
    case 'seamGrade':
      return `${pct} chance of a grade better`
    case 'presence':
      return `${pct} bench speed while you stand there`
    case 'runSlot':
      return `+${n} run of this line at once`
    case 'goldFind':
      return `${pct} gold from a pack`
    case 'lootOption':
      return `${pct} chance of an extra line on loot`
    case 'craftOption':
      return `${pct} chance of an extra line on what you make`
    case 'optionTier':
      return `${pct} chance a line is drawn a grade deeper`
    case 'craftDurability':
      return `${pct} durability on what you make`
    case 'brewExtra':
      return `${pct} chance of an extra flask`
    case 'stackCap':
      return `+${n} to every potion stack`
    case 'batch':
      return `${pct} output per craft and run`
    case 'skillPower':
      return `${pct} on your weapon's three skills`
    case 'skillCooldown':
      return `−${n} round${n === 1 ? '' : 's'} on every skill cooldown`
    case 'skillStun':
      return `+${n} round on a stun`
    case 'sight':
      return `+${n} hex of sight`
    case 'bagSlots':
      return `+${n} strap${n === 1 ? '' : 's'} on your bag`
  }

  return ''
}

/** What a battle skill does, in the words the fight plate uses. */
function skillLine(skillKey: string): string {
  const rows = game.battleSkills[job.value] ?? []

  return rows.find((r) => r.key === skillKey)?.effect ?? 'A skill your weapon carries.'
}

/** The figure by itself, formatted the way its kind is counted. */
function effectValue(effect: NodeEffect): string {
  // §9.5.9 -- a skill node has no figure of its own; what it teaches carries
  // its own. Nothing asks this for one, and the guard says why.
  if (effect.kind === 'battleSkill') return ''

  switch (effect.kind) {
    case 'stat':
      return formatStat(effect.stat, effect.value)
    case 'battleWear':
    case 'weaponWear':
    case 'costReduction':
      // signed() is where a spared bill becomes negative, for the sentence and
      // this row alike.
      return formatPercent(signed(effect))
    case 'toolWear':
    case 'seamGrade':
    case 'presence':
    case 'goldFind':
    case 'lootOption':
    case 'craftOption':
    case 'optionTier':
    case 'craftDurability':
    case 'brewExtra':
    case 'skillPower':
      return formatPercent(effect.value)
    case 'skillCooldown':
      return `${effect.value} round${effect.value === 1 ? '' : 's'} sooner`
    case 'skillStun':
      return `+${effect.value} round`
    case 'sight':
      return `+${effect.value} hex`
    default:
      return `+${effect.value}`
  }
}

/**
 * What this node's kind adds up to across everything already learned, and what
 * it stops at.
 *
 * The one question the panel could not answer before, and the one §7.4.3 cares
 * most about: the caps are what keep a maxed specialist from switching off a
 * §11 sink, and a cap nobody is shown reads as a bug the day it binds. Counted
 * off owned nodes rather than asked for, because the tree and what you own are
 * both already here -- and clamped, because the server clamps.
 */
function effectTotal(row: Row): { now: string; cap: string } | null {
  const t = tree.value
  const cap = t?.caps?.[row.def.kind]
  const effect = rankEffect(row, 1)
  if (!t || cap === undefined || effect === null) return null

  // Both sides bare, because this row is a progress figure against a ceiling
  // and neither half is a change: "2.5% of 15%" spared, not "-2.5% of -15%".
  // The sentence above already says which direction the skill pushes.
  const shape = (v: number) =>
    effectValue({ ...effect, value: Math.min(v, cap) } as NodeEffect).replace(/^[+-]/, '')

  return { now: shape(heldValue(row)), cap: shape(cap) }
}

/**
 * §7.4.3 -- the job's name, but only for the kinds that are locked to it.
 *
 * A gathering skill's yield counts in its forest and nowhere else; a strap
 * counts everywhere. Naming the job on both would make the lock meaningless by
 * saying it about things that do not have one.
 */
const SCOPED: ReadonlySet<string> = new Set(['stat', 'bite', 'toolWear', 'seamGrade', 'presence', 'runSlot'])

const scopedName = computed(() =>
  chosen.value && SCOPED.has(chosen.value.def.kind) ? jobDef.value?.name : undefined,
)

async function learn(): Promise<void> {
  if (!picked.value) return
  await game.buySkillRank(picked.value)
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

      <!--
        §7.4 -- a band is a tab and a job is a mark.
        
        It was four labelled bands stacked, seventeen text buttons between them,
        which is most of a phone spent saying what you could be looking at
        rather than showing it. Two rows now: which kind of work, then which job.
      -->
      <div class="bands" role="tablist">
        <button
          v-for="group in groups"
          :key="group.kind"
          type="button"
          role="tab"
          class="band-tab"
          :class="{ on: band === group.kind }"
          :aria-selected="band === group.kind"
          @click="band = group.kind"
        >
          {{ group.label }}
          <!-- §13.3 -- sap when something free is waiting behind this tab,
               because a closed tab is the one place a claim can hide. -->
          <span v-if="waitingIn(group.kind) > 0" class="dot" aria-hidden="true" />
        </button>
      </div>

      <div class="trades">
        <button
          v-for="key in shownJobs"
          :key="key"
          type="button"
          class="trade"
          :class="{ on: job === key }"
          :style="{ '--accent': MATERIAL_PALETTE[(tree.jobs[key]!.palette) as keyof typeof MATERIAL_PALETTE] }"
          :title="tree.jobs[key]!.name"
          @click="job = key"
        >
          <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor"
               stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="JOB_PATHS[key] ?? ACTION_PATHS.skills" />
          </svg>
          <span class="trade-name">{{ tree.jobs[key]!.name }}</span>
          <!--
            §13.3 -- sap when something free is waiting on that job, the same
            green the quest ledger lights with. A wayfaring rank costs no point,
            so there is no reason not to take it and every reason to be told it
            is there; the count is otherwise what you have learned.
          -->
          <span class="tally" :class="{ ready: claimable(key) > 0 }">{{
            claimable(key) > 0 ? claimable(key) : learnedIn(key)
          }}</span>
        </button>
      </div>

      <section v-if="jobDef" class="sheet" :style="{ '--accent': accent }">
        <header class="head">
          <div class="row-between">
            <h3>{{ jobDef.name }}</h3>
            <!--
              §9.5 -- the battle jobs are not dormant any more. They level on
              the road, on a win and on nothing else, and which of the three
              earns it is the weapon family in the slot (§9.5.4). The chip said
              "dormant" and the sheet said raiding was not built yet, both left
              over from before packs stood on hexes.
            -->
            <span class="chip tiny">{{ jobDef.source }}</span>
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
          <!--
            One line, and only where it changes how the panel behaves. The job's
            own description is above; a second paragraph restating it, and a
            footnote restating that, were three copies of one sentence stacked
            around a tree nobody could see for the prose.
          -->
          <p v-if="automatic" class="tiny granted">Free — walk far enough and it is yours.</p>

        </header>

        <!--
          The list. One row per skill, and the pip strip is what a rank count
          looks like: an empty rank is the same shape as a full one, so what is
          left is seen rather than subtracted (§7.6's argument about the comb).
        -->
        <ul class="skills">
          <li v-for="row in rows" :key="row.key">
            <button
              type="button"
              class="skill"
              :class="{ on: picked === row.key, open: row.open, held: row.rank > 0 }"
              :aria-label="`${row.def.name}, rank ${row.rank} of ${row.ranks}. ${reasonFor(row)}`"
              @click="picked = picked === row.key ? null : row.key"
            >
              <span class="node" :class="{ owned: row.rank > 0, free: automatic && row.open }">
                <span class="hex">
                  <span class="face">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <path :d="ACTION_PATHS[iconFor(row)]" />
                    </svg>
                  </span>
                </span>
              </span>

              <span class="body">
                <span class="name">{{ row.def.name }}</span>
                <span class="tiny muted what">{{ describe(row) }}</span>
              </span>

              <span class="right">
                <!--
                  What you are GETTING, which is the number a list of skills is
                  actually scanned for. It read "5 / 13" -- a rank is a fact
                  about the ladder, and what a player wants off a row is what
                  the row is worth to them. The count moves under it, quiet.
                -->
                <span class="gain" :class="{ none: row.rank === 0 }">
                  <template v-if="row.def.kind === 'battleSkill'">
                    {{ row.rank > 0 ? 'Learned' : '—' }}
                  </template>
                  <template v-else-if="row.rank > 0">{{ totalAt(row, row.rank) }}</template>
                  <template v-else>—</template>
                </span>
                <span class="pips" aria-hidden="true">
                  <i
                    v-for="i in row.ranks"
                    :key="i"
                    :class="{ got: i <= row.rank, next: i === row.rank + 1 && row.open }"
                  />
                </span>
                <span v-if="row.ranks > 1" class="count" :class="{ maxed: row.maxed }">
                  {{ row.rank }}/{{ row.ranks }}
                </span>
              </span>
            </button>
          </li>
        </ul>
      </section>

      <!-- What you tapped: what you have, what comes next, and the ladder. -->
      <div v-if="chosen" class="inset detail">
        <div class="row-between">
          <strong class="name">{{ chosen.def.name }}</strong>
          <span class="label tier">Rank {{ chosen.rank }} of {{ chosen.ranks }}</span>
        </div>
        <p class="does">{{ describe(chosen) }}</p>

        <!-- §7.4 -- the three questions, in the order they are asked. -->
        <div class="answers">
          <div class="answer now">
            <span class="label">You have now</span>
            <span v-if="chosen.def.kind === 'battleSkill'" class="figure">
              {{ chosen.rank > 0 ? 'Learned' : 'Not learned' }}
            </span>
            <span v-else class="figure">{{ heldPhrase(chosen) }}</span>
            <span v-if="chosen.rank > 0 && chosen.ranks > 1" class="tiny muted">
              from {{ chosen.rank }} rank{{ chosen.rank === 1 ? '' : 's' }}
            </span>
          </div>

          <div v-if="!chosen.maxed" class="answer next">
            <span class="label">Next rank</span>
            <span class="figure">
              <template v-if="chosen.def.kind === 'battleSkill'">Learn it</template>
              <template v-else>
                {{ effectPhrase(rankEffect(chosen, chosen.rank + 1)!, scopedName) }}
              </template>
            </span>
            <span class="tiny muted">at {{ jobDef?.name }} level {{ chosen.nextLevel }}</span>
          </div>
          <div v-else class="answer done">
            <span class="label">Next rank</span>
            <span class="figure">Maxed</span>
            <span class="tiny muted">every rank taken</span>
          </div>

          <!-- §7.4.3 -- and where the kind itself stops, which is what keeps a
               maxed specialist from switching off a §11 sink. -->
          <div v-if="effectTotal(chosen)" class="answer cap">
            <span class="label">Kind stops at</span>
            <span class="figure" :class="{ capped: effectTotal(chosen)!.now === effectTotal(chosen)!.cap }">
              {{ effectTotal(chosen)!.now }} of {{ effectTotal(chosen)!.cap }}
            </span>
          </div>
        </div>

        <!-- Every rank at once, so "when can I take it" is answered for all of
             them rather than one at a time. -->
        <ol v-if="chosen.ranks > 1" class="ladder">
          <li
            v-for="i in chosen.ranks"
            :key="i"
            :class="{ got: i <= chosen.rank, next: i === chosen.rank + 1 }"
          >
            <span class="lv">lv {{ chosen.def.ranks[i - 1]!.level }}</span>
            <!-- What you would HAVE at this rank, not what the rank adds:
                 "+2, +2, +2" down a column says nothing about where it ends. -->
            <span class="run">{{ totalAt(chosen, i) }}</span>
          </li>
        </ol>

        <div class="row-between foot">
          <span class="tiny" :class="chosen.open ? 'ready' : 'muted'">{{ reasonFor(chosen) }}</span>
          <button
            v-if="!chosen.maxed"
            class="btn btn-sm"
            :class="{ 'btn-primary': chosen.open }"
            type="button"
            :disabled="game.busy || !chosen.open"
            @click="learn"
          >
            {{ automatic ? 'Take it' : 'Learn · 1 point' }}
          </button>
        </div>
      </div>

      <p v-else class="tiny muted hint">Tap a skill for what it gives.</p>
    </template>
  </div>
</template>

<style scoped>
/* ------------------------------------------------------------ the skill list */

.skills {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.skill {
  display: flex;
  align-items: center;
  gap: 11px;
  width: 100%;
  padding: 9px 12px 9px 8px;
  clip-path: var(--plate-clip);
  background: var(--ink-raised);
  border: 0;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: background 0.12s ease;
}

.skill:hover {
  background: #232d28;
}

.skill.on {
  background: var(--line);
}

.skill .body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.skill .name {
  font-size: 13px;
  line-height: 1.25;
  color: var(--vellum);
}

/*
 * Two lines at most, and it WRAPS rather than running on.
 *
 * `white-space: nowrap` made the longest sentence in the list the min-content
 * width of the whole panel, and `text-overflow` never got a chance because
 * nothing upstream was ever narrow enough to clip against. Wrapping puts the
 * floor back at the longest word; the clamp is what keeps a row from growing.
 */
.skill .what {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  line-height: 1.35;
  overflow: hidden;
}

/*
 * The right-hand column is one reading, top to bottom: what you have, how much
 * of the ladder that is, and the count. Right-aligned so the figures line up
 * down the list -- they are compared against each other, not against the names.
 */
.skill .right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: center;
  gap: 4px;
  flex: 0 0 auto;
  min-width: 84px;
  /* A one-rank skill has no count under it, and a row that is a line shorter
     than the ones around it breaks the column the figures are read down. */
  min-height: 44px;
}

.skill .gain {
  font-size: 13px;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
  color: var(--sap);
  white-space: nowrap;
}

/* Nothing held yet: an em dash rather than a zero, which would read as a
   figure worth comparing. */
.skill .gain.none {
  color: #5c655f;
}

.count {
  font-size: 10px;
  letter-spacing: 0.04em;
  font-variant-numeric: tabular-nums;
  color: #6d7770;
}

/* §13.3 -- sap for a thing finished, which is what a maxed skill is. */
.count.maxed {
  color: var(--sap);
}

/*
 * A rank strip rather than a bar. An empty rank is the same shape as a full
 * one, so what is left is seen rather than subtracted -- §7.6 makes exactly
 * that argument about the bag comb, and a rank count is the same question.
 */
.pips {
  display: flex;
  gap: 2px;
}

.pips i {
  width: 5px;
  height: 7px;
  background: #2b352f;
  display: block;
}

.pips i.got {
  background: var(--accent, var(--copper));
}

/* The one you could take right now, outlined rather than filled. */
.pips i.next {
  background: none;
  box-shadow: inset 0 0 0 1px var(--vellum-dim);
}

/* ---------------------------------------------------------------- the answers */

.answers {
  display: flex;
  flex-direction: column;
  gap: 1px;
  background: var(--line);
  margin: 10px 0 0;
}

.answer {
  background: var(--ink-panel);
  padding: 6px 9px;
  border-left: 2px solid var(--line);
  display: flex;
  align-items: baseline;
  gap: 7px;
  flex-wrap: wrap;
}

/*
 * Wide enough for "You have now" on one line. At 82px it wrapped, which put a
 * two-line label beside a one-line figure and threw the row's baseline out.
 */
.answer .label {
  flex: 0 0 96px;
  white-space: nowrap;
}

.answer .figure {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  color: var(--vellum);
}

/* §13.3 -- sap for what you already hold, copper for the work still ahead. */
.answer.now {
  border-left-color: var(--sap);
}

.answer.now .figure {
  color: var(--sap);
}

.answer.next {
  border-left-color: var(--copper);
}

.answer.next .figure {
  color: var(--copper);
}

.answer .figure.capped {
  color: var(--sap);
}

/* ----------------------------------------------------------------- the ladder */

.ladder {
  list-style: none;
  margin: 11px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

/*
 * §13 -- a chamfer cuts two opposing corners off the stone, so the corners are
 * exactly where a chip has least room. At 3px/7px the level and the total sat
 * against the cut edge; the padding has to clear the chamfer, not just the
 * bounding box.
 */
.ladder li {
  display: flex;
  align-items: baseline;
  gap: 6px;
  padding: 5px 11px;
  background: var(--ink-raised);
  clip-path: var(--plate-clip);
  color: #6d7770;
  font-size: 11px;
  line-height: 1.4;
  font-variant-numeric: tabular-nums;
}

.ladder li.got {
  color: var(--vellum);
}

.ladder li.got .gain {
  color: var(--sap);
}

.ladder li.next {
  color: var(--vellum);
  background: var(--line);
}

.ladder li.next .lv {
  color: var(--copper);
}

.ladder .lv {
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-size: 10px;
}

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

/* -------------------------------------------------------- the band tabs */

/*
 * §7.4 -- four bands, one open. They stacked, which put four headings and
 * seventeen buttons above every sheet; a tab row is the same choice in one
 * line, and it is the choice you make once rather than the one you re-read
 * every time the panel opens.
 */
.bands {
  display: flex;
  gap: 3px;
  margin-bottom: 7px;
}

.band-tab {
  position: relative;
  flex: 1;
  padding: 7px 4px;
  border: 0;
  background: var(--ink-panel);
  color: #6d7770;
  font-family: inherit;
  font-size: 11px;
  letter-spacing: 0.03em;
  cursor: pointer;
  clip-path: var(--plate-clip);
  transition: color 0.12s ease, background 0.12s ease;
}

.band-tab:hover {
  color: var(--vellum-dim);
}

.band-tab.on {
  background: var(--line);
  color: var(--vellum);
}

/* §13.3 -- something free is waiting behind a tab you cannot see into. */
.band-tab .dot {
  position: absolute;
  top: 5px;
  right: 7px;
  width: 5px;
  height: 5px;
  background: var(--sap);
  clip-path: var(--hex-clip);
}

/* ------------------------------------------------------------ the jobs */

.trades {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
  margin-bottom: 0;
}

/*
 * A mark and a name. The mark is the implement the job is about (JOB_PATHS),
 * which is what makes a row of six readable at a glance rather than six words
 * that all start with a capital letter.
 */
.trade {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 9px;
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font-family: inherit;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  clip-path: polygon(6px 0, 100% 0, 100% calc(100% - 6px), calc(100% - 6px) 100%, 0 100%, 0 6px);
}

.trade svg {
  flex: 0 0 auto;
  color: #6d7770;
  transition: color 0.12s ease;
}

.trade-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.trade:hover {
  color: var(--vellum);
}

.trade.on {
  background: var(--ink-raised);
  border-color: var(--accent);
  color: var(--vellum);
}

.trade.on svg {
  color: var(--accent);
}

.tally {
  flex: 0 0 auto;
  font-size: 10px;
  font-weight: 700;
  color: #7b8580;
}

.trade.on .tally {
  color: var(--accent);
}

/* ------------------------------------------------------------------- sheet */

/*
 * The gap between the job chips and the sheet belongs to the sheet, not to the
 * last chip band. It used to hang off `.band-of-jobs:last-of-type`, which stops
 * matching the moment a skill is tapped: the detail panel below is a div too,
 * so it becomes the last of its type and the sheet slides up against the chips.
 */
.sheet {
  margin-top: 14px;
}

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

/*
 * One line, so no box. It carried a paragraph and needed the dashed frame to
 * hold it; framing a single clause makes it look like a notice that lost its
 * notice. The accent does the work the border was doing.
 */
.granted {
  margin: 7px 0 0;
  color: var(--accent);
  line-height: 1.45;
}

/* ------------------------------------------------------------------ strata */

/*
 * The signature. Depth in a ruled gutter, the seam beside it: five bands that
 * narrow from six nodes to two, which is what the tree actually does.
 */
.band {
  display: grid;
  grid-template-columns: 64px 1fr;
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

/* §7.5 -- the wayfaring gutter prints a span ("lv 8–12"), which is two
   characters wider than the single number every bought tree needs. Kept on one
   line: a level range broken across two rows reads as two levels. */
.lv {
  color: #6d7770;
  white-space: nowrap;
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

/* §13.3 -- something free is waiting on this tree. */
.tally.ready {
  color: var(--sap);
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

/*
 * §13.3 -- a FREE skill sitting there unclaimed is the one thing on this panel
 * worth crossing the screen for, so it gets sap.
 *
 * A bought node's `open` means "you could spend a point here", which is an
 * invitation. A wayfaring one means "you have already paid for this by walking
 * and it is waiting" -- and while these were granted, a reached node was
 * FILLED, so the eye had something to land on. Claimed, it is an outline that
 * reads exactly like the locked rows under it, which is how three free skills
 * sat unnoticed on a tree the player was looking straight at.
 */
.node.open.free {
  color: var(--ink);
}

.node.open.free .hex {
  background: var(--sap);
}

.node.open.free .face {
  background: var(--sap);
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

/*
 * What the node does, figure included, and the only sentence on the card.
 *
 * The rule used to be the opposite -- no figure here, all of them in labelled
 * rows underneath -- which meant reading a node took a sentence plus a table.
 * Set at the size of a reading rather than a caption, because it IS the answer.
 */
.does {
  margin: 8px 0 0;
  color: var(--vellum);
  font-size: 13px;
  text-align: left;
  line-height: 1.4;
}

.stats {
  display: flex;
  flex-direction: column;
  gap: 1px;
  margin-top: 7px;
}

/* Label left, figure right, and the figures share a column so two nodes can be
   weighed against each other without reading either sentence. */
.stat {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  line-height: 1.6;
  text-align: left;
}

.stat .readout {
  text-align: right;
  color: var(--vellum);
}

/* §13.3 -- ember is a state to deal with, and a kind you have already maxed is
   exactly that: the next node of it is a point you will not feel. */
.stat .readout.capped {
  color: var(--ember);
}

/* Flavour is not a mechanic. Last, quietest, and the only italic here. */

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

/*
 * On a phone the panel is 390px wide and every horizontal pixel is spent, so
 * what gives way is the chrome rather than the figures: the trades drop to two
 * across, the marks and the gutters come in, and the note goes -- it repeats
 * what the big number above it already says.
 *
 * §13.2's rule about the artifact sandbox applies here too: the panel itself
 * has `min-width: 0` now (PanelOverlay), without which none of this binds and
 * the whole sheet simply ran off the right of the screen.
 */
@media (max-width: 560px) {
  .trades {
    grid-template-columns: repeat(2, 1fr);
  }

  .trade {
    padding: 8px 7px;
    gap: 6px;
  }

  /* The mark comes in a little, because the name is what is being read. */
  .node .hex {
    width: 36px;
    height: 31px;
  }

  .skill {
    gap: 9px;
    padding: 8px 9px 8px 6px;
  }

  /* The figures still line up, but they stop reserving room they do not need. */
  .skill .right {
    min-width: 66px;
  }

  /*
   * The label and the figure stack. Side by side, a 96px label against a
   * 390px screen left "+1% woodcutting yield" and "at Woodcutting level 1"
   * fighting over the same forty pixels.
   */
  .answer {
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
  }

  .answer .label {
    flex: none;
  }

  .purse .note {
    display: none;
  }
}
</style>
