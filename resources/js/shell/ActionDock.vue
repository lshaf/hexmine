<script setup lang="ts">
/**
 * The bottom dock: what you can do, here.
 *
 * "Here" is literal. This reads the hex under the character's feet, never the
 * one that happens to be selected -- aiming at a hex is the tile card's job, and
 * so is travelling to it. The dock is only ever about the ground you stand on.
 *
 * Mining is one trip at a time and it pins you in place until you deal with it,
 * so Mine and Claim are a single slot at two moments of the same trip rather
 * than two buttons competing for attention. Trading, crafting and processing
 * appear only at a settlement (§6) -- there is no trader in the middle of a
 * forest, and greying one out would imply there could be.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { RECIPES, RING_LABEL, SKILL_BY_KEY } from '@/game/catalog'
import { VARIANT_LABEL } from '@/game/variants'
import { formatDuration } from '@/game/formulas'
import { worldParams } from '@/game/worldgen'
import HexAction from './HexAction.vue'

const game = useGame()

const here = computed(() => game.currentSettlement)

/** The hex underfoot, costed by the server. Everything in the dock keys off it. */
const underfoot = computed(() => game.underfoot)

/** The same hex from the local generator, for naming open country. */
const standing = computed(() => {
  const char = game.character
  return char ? game.tileAt(char.col, char.row) : undefined
})

/** One trip at a time, so this is a single job or nothing. */
const trip = computed(() => game.miningJob)
const ready = computed(() => Boolean(trip.value && trip.value.endsAt <= game.now))

/** Ground worth a pick. Settlement tiles and the barren centre have no seam. */
const seam = computed(() => Boolean(underfoot.value?.material))

const mineHint = computed(() => underfoot.value?.reason ?? `${underfoot.value?.yield ?? 0} units`)

/**
 * §5.5 -- a herd standing on this hex right now. Temporary and time-bucketed,
 * so the cell appears and leaves on its own; there is nothing to un-spawn.
 */
const hunt = computed(() => underfoot.value?.hunt)
const herd = computed(() => Boolean(hunt.value?.herdUntil))

/**
 * How long the herd stays. The only clock on this dock counting something that
 * is not yours: a seam waits, a herd leaves (§5.5). That is the whole reason
 * the cell earns a countdown when Mine never has.
 *
 * Hidden while a trip runs -- one trip at a time means the herd is unactionable
 * then, and a countdown you cannot act on is noise.
 */
const herdLeaves = computed(() => {
  const until = hunt.value?.herdUntil
  if (!until || trip.value) return null

  return formatDuration(until - game.now)
})

/**
 * The last quarter of the herd's stay: the offer is closing.
 *
 * A fraction of the lifetime rather than a fixed number of minutes, because
 * GAME_TIME_SCALE compresses the lifetime and an absolute threshold would read
 * "closing" for the whole window at a fast clock. worldParams() already carries
 * the scaled value the server generated the marker from.
 */
const herdGoing = computed(() => {
  const until = hunt.value?.herdUntil
  if (!until) return false

  return until - game.now < worldParams().herdLifetimeMs * 0.25
})

const huntHint = computed(() => {
  const h = hunt.value
  if (!h) return ''
  if (h.reason) return h.reason
  // Both halves of the offer, because the essence is the reason to care and
  // the pelts are the reason to bother -- §5.5 is the bridge between them.
  const essence = h.essenceChance > 0
    ? `, ${Math.round(h.essenceChance * 100)}% essence`
    : ' — no bow, so no essence'

  return `${h.yield} units${essence}`
})

const claimHint = computed(() => {
  if (!trip.value) return ''
  return ready.value ? `${trip.value.quantity} units waiting` : 'Still working this hex'
})

/** Processing lines this settlement runs, §6. */
const lines = computed(() =>
  here.value ? RECIPES.filter((r) => here.value!.lines.includes(r.skill)) : [],
)

const lineNames = computed(() =>
  [...new Set(lines.value.map((r) => SKILL_BY_KEY[r.skill].name))].join(' · '),
)

const processHint = computed(() => {
  if (!lines.value.length) return 'No lines run here'
  if (game.processingJob) return 'Already helping with a job'
  return lineNames.value
})

/**
 * What you are doing here, under where you are. A trip locks you to this hex,
 * so its countdown belongs on the dock rather than hidden in a tooltip.
 */
const doing = computed(() => {
  if (!trip.value) return null
  return ready.value ? 'Reward ready' : `Working · ${formatDuration(trip.value.endsAt - game.now)}`
})

function mine(): void {
  const char = game.character
  if (char) void game.startMining(char.col, char.row)
}

function hunted(): void {
  const char = game.character
  if (char) void game.startHunt(char.col, char.row)
}
</script>

<template>
  <div class="dock plate">
    <div class="inner">
      <!-- Where you are, and what you are doing about it. -->
      <div class="where">
        <span class="label">{{ here ? 'You are at' : 'Open country' }}</span>
        <h2 class="place">
          {{ here ? here.name : standing ? VARIANT_LABEL[standing.variant] : 'Unsurveyed' }}
        </h2>
        <span v-if="doing" class="label doing" :class="{ ready }">{{ doing }}</span>
        <!-- §5.5 -- perishable, so it says when it goes rather than that it is
             here. "Herd" alone would read as scenery. -->
        <span v-else-if="herdLeaves" class="label herd" :class="{ going: herdGoing }">
          Herd moves on in {{ herdLeaves }}
        </span>
        <span v-else class="tiny muted meta">
          <template v-if="here">{{ here.tier }} · {{ lineNames || 'no lines' }}</template>
          <template v-else-if="standing">{{ RING_LABEL[standing.ring] }}</template>
        </span>
      </div>

      <div class="actions">
        <!-- Mine and Claim are the same slot at two moments of one trip. -->
        <template v-if="trip">
          <HexAction
            icon="claim"
            label="Claim"
            :primary="ready"
            :disabled="!ready || game.busy"
            :hint="claimHint"
            @activate="game.collect(trip.id)"
          />
          <HexAction
            icon="drop"
            label="Drop"
            danger
            :disabled="game.busy"
            hint="Forfeits the reward, and frees you to move"
            @activate="game.abandon(trip.id)"
          />
        </template>

        <template v-else>
          <HexAction
            v-if="seam"
            icon="mine"
            label="Mine"
            :primary="Boolean(underfoot?.canMine) && !herd"
            :disabled="!underfoot?.canMine"
            :hint="mineHint"
            @activate="mine"
          />

          <!-- §5.5 -- present only while a herd is. Beside Mine rather than
               instead of it: a hunt takes no tile slot, so both verbs are
               genuinely available on the same hex at the same time. -->
          <HexAction
            v-if="herd"
            icon="hunt"
            label="Hunt"
            :primary="Boolean(hunt?.canHunt)"
            :disabled="!hunt?.canHunt"
            :hint="huntHint"
            @activate="hunted"
          />
        </template>

        <!-- Settlement-only. Absent in the field rather than greyed: the point
             is that these people are not out here. -->
        <template v-if="here">
          <span v-if="trip || seam || herd" class="rule" aria-hidden="true" />
          <HexAction icon="trade" label="Trade" @activate="game.openPanel('shop')" />
          <HexAction icon="craft" label="Craft" @activate="game.openPanel('craft')" />
          <HexAction
            icon="process"
            label="Process"
            :disabled="!lines.length"
            :hint="processHint"
            @activate="game.openStation()"
          />
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * Sized to its contents: the dock is narrow in the field and wide at a
 * settlement, so its silhouette changes with location before you read a word
 * of it.
 */
.dock {
  width: fit-content;
  max-width: min(620px, calc(100vw - 24px));
}

.inner {
  padding: 11px 16px 13px;
  display: flex;
  align-items: center;
  gap: 18px;
}

.where {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 108px;
  max-width: 150px;
}

.place {
  font-size: 16px;
  color: var(--vellum);
}

.meta {
  line-height: 1.3;
}

.doing {
  color: var(--copper);
}

.doing.ready {
  color: var(--gold);
}

/* Gold, because a herd is an opportunity rather than work in progress -- the
   same reading the map already gives gold in §13.1. Ember once it is closing. */
.herd {
  color: var(--gold);
}

.herd.going {
  color: var(--ember);
}

.actions {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-left: auto;
}

.rule {
  width: 1px;
  align-self: stretch;
  background: var(--hud-line-soft);
  margin: 2px 4px 12px;
}

/*
 * Phones keep the same shape rather than stacking. Three cells is the most the
 * dock ever shows at once -- a settlement has no seam to mine, and a trip can
 * only run out in the field -- so where-you-are and what-you-can-do still fit
 * on one line, and the dock stays a band rather than growing into a panel.
 */
@media (max-width: 560px) {
  .dock,
  .card {
    width: 100%;
    min-width: 0;
    max-width: none;
  }

  .inner {
    gap: 8px;
    padding: 8px 10px 9px;
  }

  .where {
    min-width: 0;
    max-width: none;
    gap: 2px;
  }

  .place {
    font-size: 13.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .meta {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .actions {
    gap: 2px;
  }

  .rule {
    margin: 2px 1px 11px;
  }
}
</style>
