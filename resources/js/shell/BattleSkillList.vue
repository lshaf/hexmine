<script setup lang="ts">
/**
 * §9.5.9 -- the three your weapon knows, named before anything happens.
 *
 * THEY COME WITH THE WEAPON, NOT WITH A SKILL POINT, and that is the one thing
 * this list exists to say out loud. §7.4.1 keeps job level as the proof you
 * have done the work rather than a reward for it, so carrying a sword is what
 * makes you a Swordhand and the three sword skills are simply what a sword is.
 * A player who has spent no points and sees three skills armed is looking at
 * the rule working, and nothing on screen said so before this.
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
      <template v-if="family">Your {{ family }} knows these.</template>
      <template v-else>Your weapon knows these.</template>
      They come with the weapon, not with a skill point — a point buys the tree
      that sharpens them.
    </p>

    <div v-for="row in rows" :key="row.key" class="skill">
      <div class="head">
        <SvgIcon :svg="row.svg" class="mark" />
        <strong class="tiny grow">{{ row.name }}</strong>
        <span v-if="row.fired !== null" class="label">×{{ row.fired }}</span>
        <span v-else class="label">{{ row.cooldown }} cd</span>
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
