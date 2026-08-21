<script setup lang="ts">
/**
 * The selected hex, sitting just above the dock.
 *
 * Deliberately separate from the dock: the dock answers "what can I do here",
 * this answers "what am I pointing at, and is it worth walking to". Travel lives
 * here rather than in the dock for exactly that reason -- it is the one action
 * that is about somewhere else, and putting it beside the haul and trip time is
 * what turns those numbers into a decision.
 *
 * Compact by default. The §7.3 breakdown expands on request, because it matters
 * exactly once -- the first time gear fails to make a trip shorter and the
 * player needs to see the floor clamp doing it.
 */
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS, RING_LABEL, SKILL_BY_KEY, skillForMaterial } from '@/game/catalog'
import { formatDuration, formatSpan } from '@/game/formulas'
import { BIOME_LABEL } from '@/theme/palette'
import { hexDistance } from '@/map/hexGeometry'
import { materialIcon } from '@/icons/procedural'
import HexAction from '@/shell/HexAction.vue'
import SvgIcon from './SvgIcon.vue'

const game = useGame()

const tile = computed(() => game.selectedTile)
const preview = computed(() => game.preview)
const mat = computed(() => (preview.value?.material ? MATERIALS[preview.value.material] : null))

const open = ref(false)

const depleted = computed(() => Boolean(tile.value && tile.value.regrowsAt > game.now))

/**
 * The server costs a trip for any workable hex, standing on it or not, so the
 * card can be read as a scouting report: what this seam is worth, and what it
 * would take. Whether you may act on it is a separate line.
 */
const trip = computed(() => (preview.value && preview.value.seconds > 0 ? preview.value : null))

/** Honest game time explains the rule; wall time is what you actually wait. */
const gameTime = (seconds: number) => seconds * 1000
const wallTime = (seconds: number) => (seconds * 1000) / game.timeScale
const compressed = computed(() => game.timeScale > 1)

/* ------------------------------------------------------------------ travel */

const onSelected = computed(() => {
  const char = game.character
  return Boolean(char && tile.value && char.col === tile.value.col && char.row === tile.value.row)
})

const distance = computed(() => {
  const char = game.character
  return char && tile.value ? hexDistance(char.col, char.row, tile.value.col, tile.value.row) : 0
})

/** A trip pins you to the hex you are working until you claim or drop it. */
const working = computed(() => game.miningJob)

const canTravel = computed(
  () =>
    Boolean(tile.value) &&
    !onSelected.value &&
    !working.value &&
    !game.travel &&
    distance.value <= game.travelRange,
)

const travelHint = computed(() => {
  if (onSelected.value) return 'You are already here'
  if (game.travel) return 'You are on the road — stop before setting a new course'
  if (working.value) {
    return working.value.endsAt <= game.now
      ? 'Claim your haul before you move on'
      : 'You are working this hex — claim or drop it first'
  }
  if (distance.value > game.travelRange) return 'Out of range — move in shorter hops'
  return `${distance.value} hexes`
})

const title = computed(() => {
  const t = tile.value
  if (!t) return ''
  return t.settlement?.name ?? t.dungeon?.name ?? BIOME_LABEL[t.biome]
})

// A new hex is a fresh question; do not carry the previous one's expansion.
watch(tile, () => {
  open.value = false
})
</script>

<template>
  <Transition name="rise">
    <div v-if="tile" class="card plate">
      <div class="inner">
        <div class="head">
        <button class="summary" type="button" @click="open = !open">
          <SvgIcon v-if="mat" :svg="materialIcon(mat, 26)" class="mat" />
          <span v-else class="pin" aria-hidden="true" />

          <span class="grow text">
            <span class="label">
              {{ RING_LABEL[tile.ring] }} · {{ tile.col }},{{ tile.row }}
            </span>
            <span class="name">{{ title }}</span>
          </span>

          <span v-if="trip" class="stats">
            <span class="stat">
              <span class="label">Haul</span>
              <span class="readout">{{ trip.yield }}</span>
            </span>
            <span class="stat">
              <span class="label">Mine</span>
              <span class="readout">{{ formatSpan(wallTime(trip.seconds)) }}</span>
            </span>
            <span class="stat">
              <span class="label">Slots</span>
              <span class="readout">{{ tile.slotsUsed }}/2</span>
            </span>
          </span>
          <span v-else class="reason tiny">
            {{ depleted ? `Regrows in ${formatDuration(tile.regrowsAt - game.now)}` : preview?.reason }}
          </span>

          <span v-if="trip" class="chevron" :class="{ open }" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <path d="m6 15 6-6 6 6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </span>
        </button>

        <!-- The one action that is about somewhere else. -->
        <HexAction
          small
          icon="travel"
          label="Travel"
          :primary="canTravel && Boolean(trip)"
          :disabled="!canTravel || game.busy"
          :hint="travelHint"
          @activate="tile && game.travelTo(tile.col, tile.row)"
        />
        </div>

        <!-- Workable hex, wrong place to be standing. Says the next move
             rather than repeating what the greyed button already implies. -->
        <p v-if="trip && !trip.canMine" class="tiny blocked">
          {{ trip.reason }}
        </p>

        <!-- §4.0 -- not a refusal: the hex is workable, it just will not give up
             its material to bare hands. Sits apart from `blocked` for that
             reason, and names the tool that would fix it. -->
        <p v-if="trip && trip.scrap" class="tiny bare">
          {{ trip.note }}
        </p>

        <div v-if="open && trip && mat" class="detail">
          <!-- The line comes from the server, not from the material: a scrap
               haul still belongs to the hex's own line, §4.0. -->
          <p class="tiny muted lede">
            {{ mat.name }} · trains {{ SKILL_BY_KEY[trip.skill ?? skillForMaterial(mat.key)].name }}
          </p>

          <div class="inset breakdown tiny">
            <div class="row-between">
              <span class="muted">Base tile time</span>
              <span class="readout">{{ formatSpan(gameTime(trip.baseSeconds)) }}</span>
            </div>
            <div class="row-between">
              <span class="muted">Skill reduction</span>
              <span class="readout good">−{{ formatSpan(gameTime(trip.skillReduction)) }}</span>
            </div>
            <div class="row-between">
              <span class="muted">Equipment reduction</span>
              <span class="readout good">−{{ formatSpan(gameTime(trip.equipReduction)) }}</span>
            </div>
            <hr class="divider" />
            <div class="row-between">
              <span>Mine time</span>
              <span class="readout">{{ formatSpan(gameTime(trip.seconds)) }}</span>
            </div>
            <p v-if="trip.clamped" class="note clamp">
              Clamped to the 30-minute floor. More reduction is wasted on this hex.
            </p>
            <p v-if="compressed" class="note">
              Development clock ×{{ game.timeScale }} — it finishes in
              {{ formatSpan(wallTime(trip.seconds)) }}.
            </p>
          </div>
        </div>

        <p v-if="tile.dungeon" class="tiny muted note dungeon">
          A dungeon entrance. Raiding is not built yet — this is where the tier 4
          materials will come from.
        </p>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.card {
  width: fit-content;
  min-width: 340px;
  max-width: min(620px, calc(100vw - 24px));
}

.inner {
  padding: 0;
}

.head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 12px 10px 14px;
}

.summary {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 11px;
  text-align: left;
}

.mat {
  flex: 0 0 auto;
}

.pin {
  width: 22px;
  height: 22px;
  flex: 0 0 auto;
  background: var(--hud-line);
  clip-path: var(--hex-clip);
}

.text {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.name {
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 600;
}

.stats {
  display: flex;
  gap: 14px;
  flex: 0 0 auto;
}

.stat {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
}

.stat .readout {
  font-size: 13px;
}

.reason {
  flex: 0 1 auto;
  max-width: 210px;
  text-align: right;
  color: var(--vellum-dim);
  line-height: 1.35;
}

.chevron {
  color: var(--vellum-dim);
  transition: transform 0.16s ease;
  flex: 0 0 auto;
}

.chevron.open {
  transform: rotate(180deg);
}

.blocked {
  margin: 0;
  padding: 8px 14px 10px;
  border-top: 1px solid var(--hud-line-soft);
  color: var(--copper);
  line-height: 1.35;
}

/* A warning, not a refusal -- muted rather than copper, so it never reads as
   the hex saying no. */
.bare {
  margin: 0;
  padding: 8px 14px 10px;
  border-top: 1px solid var(--hud-line-soft);
  color: var(--vellum-dim);
  line-height: 1.35;
}

.detail {
  padding: 0 14px 12px;
}

.lede {
  margin: 0 0 8px;
}

.breakdown .row-between {
  padding: 1px 0;
}

.good {
  color: #8fbf7f;
}

.note {
  margin: 8px 0 0;
  font-size: 11px;
  line-height: 1.45;
  color: var(--vellum-dim);
}

.clamp {
  color: #e8a06a;
}

.dungeon {
  margin: 0;
  padding: 0 14px 12px;
}

@media (max-width: 560px) {
  .dock,
  .card {
    width: 100%;
    min-width: 0;
    max-width: none;
  }

  .stats {
    gap: 10px;
  }

  .reason {
    max-width: 130px;
  }
}
</style>
