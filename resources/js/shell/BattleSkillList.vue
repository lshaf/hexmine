<script setup lang="ts">
/**
 * §9.5.9 -- the three your weapon can know, named before anything happens.
 *
 * THEY ARE LEARNED, as ordinary nodes of the battle job's own tree -- one at
 * each of the first three depths. The family in the slot still decides WHICH
 * three (§9.5.4), but knowing them is a point spent against the stat nodes
 * beside them, and a fighter who has spent nothing swings and does nothing
 * else.
 *
 * §9.5.9 also puts them on the fight PREVIEW, because whether to close at all
 * is the decision and against a long fight these are half of it. Same rows on
 * the pin, on the bench, and on the plate afterwards -- the last of those adds
 * a count, since how often one actually went is the thing a receipt knows that
 * a preview cannot.
 */
import { computed } from 'vue'
import SvgIcon from '@/components/SvgIcon.vue'
import { skillGlyph } from '@/icons/skills'
import { timesFired, type SkillLike } from '@/game/battle'
import type { BattleRound } from '@/game/types'

/** A drawable skill, plus whatever detail the caller happens to have. */
type Row = SkillLike & {
  description?: string
  stats?: Array<{ label: string; value: string }>
  /**
   * §9.5.9 -- learned, or still to learn, and what learning it would take.
   *
   * All optional, and absent everywhere a skill cannot be learned: the pin, the
   * bench and the receipt draw the same rows without any of this. They are here
   * rather than only in the Jobs panel's own type so the `action` slot hands
   * back something the caller can act on.
   */
  known?: boolean
  node?: string
  jobLevel?: number
  canLearn?: boolean
}

const props = withDefaults(
  defineProps<{
    skills: Row[]
    /** The word for the thing in your hand, so the header names it. */
    family?: string | null
    /** Present once a fight has run: how often each one actually went. */
    log?: BattleRound[] | null
    /** The labelled rows are the detail; a pin has no room for them. */
    detail?: boolean
  }>(),
  { family: null, log: null, detail: true },
)

const rows = computed(() =>
  props.skills.map((s) => ({
    ...s,
    svg: skillGlyph(s.glyph, 15),
    fired: props.log ? timesFired(props.log, s.key) : null,
  })),
)
</script>

<template>
  <div v-if="rows.length" class="skills">
    <p class="tiny muted lead">
      <template v-if="family">Your {{ family }} can know these.</template>
      <template v-else>These are learned in the job's tree.</template>
      One node each, at depths I, II and III.
    </p>

    <div v-for="row in rows" :key="row.key" class="skill" :class="{ unknown: row.known === false }">
      <div class="head">
        <SvgIcon :svg="row.svg" class="mark" />
        <strong class="tiny grow">{{ row.name }}</strong>
        <span v-if="row.fired !== null" class="label">×{{ row.fired }}</span>
        <span v-else class="label">{{ row.cooldown }} cd</span>
        <!--
          The Jobs panel puts a Learn button here; the pin, the bench and the
          receipt put nothing. A slot rather than a prop, because what belongs
          beside a skill is the caller's business and this component's only job
          is to draw the skill.
        -->
        <slot name="action" :row="row" />
      </div>

      <p v-if="row.effect" class="tiny does">{{ row.effect }}</p>

      <dl v-if="detail && row.stats?.length" class="rails">
        <div v-for="stat in row.stats" :key="stat.label" class="rail">
          <dt class="label">{{ stat.label }}</dt>
          <dd class="tiny">{{ stat.value }}</dd>
        </div>
      </dl>
    </div>
  </div>
</template>

<style scoped>
/* §9.5.9 -- not learned yet. Dimmed rather than hidden: a skill you cannot use
   is the reason to keep levelling, and hiding it would make a job sheet say a
   Runecaster has nothing until it suddenly has three. */
.skill.unknown {
  opacity: 0.62;
}

.skills {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lead {
  margin: 0;
  line-height: 1.5;
}

.skill {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 7px 9px;
  background: rgba(0, 0, 0, 0.24);
  border-left: 2px solid var(--copper);
}

.head {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--copper);
}

.mark {
  flex: 0 0 auto;
  display: block;
}

.grow {
  flex: 1 1 auto;
  min-width: 0;
  color: var(--vellum);
}

.does {
  margin: 0;
  line-height: 1.45;
  color: var(--vellum-dim);
}

/* The almanac's rail: caps label, hairline, figure. Every number a skill has
   lives in one of these rows and none of them are in the prose above -- which
   is what keeps the hand-written sentence safe from drift while the figures
   stay derived. */
.rails {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 2px 0 0;
}

.rail {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  padding-left: 7px;
  border-left: 1px solid var(--line);
}
</style>
