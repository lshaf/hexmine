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
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { api } from '@/api/client'
import { MONSTERS } from '@/game/monsters'
import { RECIPES, RING_LABEL, SKILL_BY_KEY } from '@/game/catalog'
import { VARIANT_LABEL } from '@/game/variants'
import { waterLabel } from '@/game/water'
import { formatDuration } from '@/game/formulas'
import { worldParams } from '@/game/worldgen'
import HexAction from './HexAction.vue'
import type { BattlePreview } from '@/api/types'

const game = useGame()

const here = computed(() => game.currentSettlement)

/** The hex underfoot, costed by the server. Everything in the dock keys off it. */
const underfoot = computed(() => game.underfoot)

/** The same hex from the local generator, for naming open country. */
const standing = computed(() => {
  const char = game.character
  return char ? game.tileAt(char.col, char.row) : undefined
})

/**
 * What this place is called. §5.3 water is named by biome, because a tarn and
 * an alkali pan are not the same body of water and calling both "Lake" throws
 * that away.
 */
const placeName = computed(() => {
  if (here.value) return here.value.name

  const tile = standing.value
  if (!tile) return 'Unsurveyed'

  return tile.water ? waterLabel(tile.biome, tile.water) : VARIANT_LABEL[tile.variant]
})

/**
 * One trip at a time, so this is a single job or nothing -- and a hunt is a
 * trip. Both pin you to the hex until you claim or drop, so both have to reach
 * this slot; reading only the mining job left a finished hunt with no way to
 * claim it and nothing on the dock saying why everything else was refused.
 */
const trip = computed(() => game.fieldJob)
const ready = computed(() => Boolean(trip.value && trip.value.endsAt <= game.now))

/** Ground worth working. Settlement tiles and the barren centre have neither. */
const seam = computed(() => Boolean(underfoot.value?.material))

/**
 * §4.0 -- the same hex worked by hand, costed by the server beside the seam.
 *
 * Bare hands are not a worse version of mining, they are the other verb, so
 * they get their own cell rather than quietly replacing the first one. Mining
 * names the tool it wants and refuses without it; this is the answer standing
 * next to that refusal, which is how the hex stays unblocked.
 */
const gather = computed(() => underfoot.value?.gather)

/**
 * Nothing here is greyed out for want of a tool, and that is the rule.
 *
 * A dead cell has to explain itself in a tooltip nobody opens on a phone, so
 * the cells stay live and the server answers -- once, in a toast, in the same
 * words the preview would have shown. The only thing that takes a verb off the
 * dock is the hex genuinely not having it: no seam, or no herd.
 */
const mineHint = computed(() => underfoot.value?.reason ?? `${underfoot.value?.yield ?? 0} units`)
const gatherHint = computed(() => gather.value?.reason ?? `${gather.value?.yield ?? 0} units by hand`)

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

/**
 * §9.5.3 -- something is standing on this hex, and until it is not there is no
 * work here and no road out.
 *
 * The dock says the two exits out loud, because a refusal that names neither
 * reads as a bug: fight it, or wait for its clock. Nothing is greyed out to
 * explain the pin -- the verbs are simply not what this hex is offering.
 */
const pinned = computed(() => Boolean(underfoot.value?.pinned))

/** The pack itself is derived client-side, so the name costs no request. */
const pack = computed(() => game.tileAt(game.character?.col ?? 0, game.character?.row ?? 0)?.pack)

const packLeaves = computed(() => {
  const until = pack.value?.until
  if (!until) return null

  return formatDuration(until - game.now)
})

/**
 * §9.5.5 -- the odds, before anything is committed.
 *
 * Fetched rather than derived: the client knows which monster is standing
 * there, but what a fight would cost depends on gear, a battle job and whatever
 * was drunk, and the server owns all three (§16).
 */
const battle = ref<BattlePreview | null>(null)

watch(
  [pinned, () => pack.value?.key],
  async ([isPinned]) => {
    battle.value = isPinned ? await api.previewBattle() : null
  },
  { immediate: true },
)

const packHint = computed(() => {
  const b = battle.value
  if (!b?.canFight) return 'Nothing here will let you work'

  return `${Math.round((b.odds ?? 0) * 100)}% · you ${b.attack}/${b.defence} · it ${b.monster?.attack}/${b.monster?.defence}`
})

const huntHint = computed(() => {
  const h = hunt.value
  if (!h) return ''
  if (h.reason) return h.reason
  return `${h.yield} units`
})

const claimHint = computed(() => {
  if (!trip.value) return ''
  if (ready.value) return `${trip.value.quantity} units waiting`
  return trip.value.kind === 'hunting' ? 'Still working this herd' : 'Still working this hex'
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
  if (ready.value) return 'Reward ready'

  const verb = trip.value.kind === 'hunting' ? 'Hunting' : 'Working'

  return `${verb} · ${formatDuration(trip.value.endsAt - game.now)}`
})

function mine(): void {
  const char = game.character
  if (char) void game.startMining(char.col, char.row)
}

function gathered(): void {
  const char = game.character
  if (char) void game.startGathering(char.col, char.row)
}

function hunted(): void {
  const char = game.character
  if (char) void game.startHunt(char.col, char.row)
}
</script>

<template>
  <div class="dock plate">
    <div class="inner">
      <!-- Where you are, and what you are doing about it -- two lines, the same
           two the tile card sitting above uses: what this place is, then what it
           is called. The caption the first line used to carry ("You are at") was
           the one thing here that said nothing. -->
      <div class="where">
        <span class="label meta">
          <template v-if="here">{{ here.tier }} · {{ lineNames || 'no lines' }}</template>
          <template v-else-if="standing">Open country · {{ RING_LABEL[standing.ring] }}</template>
          <template v-else>Unsurveyed</template>
        </span>

        <span class="named">
          <h2 class="place">{{ placeName }}</h2>
          <span v-if="doing" class="label doing" :class="{ ready }">{{ doing }}</span>
          <!-- §5.5 -- perishable, so it says when it goes rather than that it is
               here. "Herd" alone would read as scenery. -->
          <span v-else-if="herdLeaves" class="label herd" :class="{ going: herdGoing }">
            Herd moves on in {{ herdLeaves }}
          </span>
        </span>
      </div>

      <div class="actions">
        <!-- Mine and Claim are the same slot at two moments of one trip. -->
        <template v-if="trip">
          <HexAction
            small
            icon="claim"
            label="Claim"
            :primary="ready"
            :disabled="!ready || game.busy"
            :hint="claimHint"
            @activate="game.collect(trip.id)"
          />
          <HexAction
            small
            icon="drop"
            label="Drop"
            danger
            :disabled="game.busy"
            hint="Forfeits the reward, and frees you to move"
            @activate="game.abandon(trip.id)"
          />
        </template>

        <!-- §9.5.3 -- while a pack holds the hex there are no verbs, only the
             two ways out of the pin. -->
        <template v-else-if="pinned">
          <div class="pinned">
            <strong class="tiny">{{ pack ? MONSTERS[pack.key]?.name : 'Something' }} is standing here</strong>
            <span class="tiny muted">{{ packHint }}</span>
            <span v-for="warning in battle?.warnings ?? []" :key="warning" class="tiny warn">
              {{ warning }}
            </span>
            <span v-if="packLeaves" class="tiny muted">Moves on in {{ packLeaves }}</span>
          </div>
        </template>

        <template v-else>
          <!-- Both verbs, always, on any hex that has a seam at all. Which one
               is lit says which one the belt is ready for; neither is closed,
               because §4.0 is about the ground being open and a greyed cell
               says the opposite. -->
          <HexAction
            v-if="seam"
            small
            icon="mine"
            label="Mine"
            :primary="Boolean(underfoot?.canMine) && !herd"
            :disabled="game.busy"
            :hint="mineHint"
            @activate="mine"
          />

          <HexAction
            v-if="seam"
            small
            icon="gather"
            label="Gather"
            :primary="Boolean(gather?.canMine) && underfoot?.bare && !herd"
            :disabled="game.busy"
            :hint="gatherHint"
            @activate="gathered"
          />

          <!-- §5.5 -- present only while a herd is. Beside the other two rather
               than instead of them: a hunt takes no tile slot, so all three
               verbs are genuinely available on the same hex at the same time. -->
          <HexAction
            v-if="herd"
            small
            icon="hunt"
            label="Hunt"
            :primary="Boolean(hunt?.canHunt)"
            :disabled="game.busy"
            :hint="huntHint"
            @activate="hunted"
          />
        </template>

        <!-- Settlement-only. Absent in the field rather than greyed: the point
             is that these people are not out here. -->
        <template v-if="here">
          <span v-if="trip || seam || herd" class="rule" aria-hidden="true" />
          <HexAction small icon="trade" label="Trade" @activate="game.openPanel('shop')" />
          <HexAction small icon="craft" label="Craft" @activate="game.openPanel('craft')" />
          <HexAction
            small
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
 *
 * Its *height* is not its own, though. The tile card sits directly on top of it
 * and the two read as one band, so the dock takes the card's cell size and the
 * card's vertical padding -- which is what makes the two plates come out the
 * same height without either of them being told a number.
 */
.dock {
  width: fit-content;
  max-width: min(620px, calc(100vw - 24px));
}

/* The tile card's own padding, to the pixel -- see the note on .dock above. */
.inner {
  padding: 9px 12px 10px 14px;
  display: flex;
  align-items: center;
  gap: 18px;
}

.where {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
  /* Wide enough for a capital running all five lines, and the dock is sized to
     its contents, so the plate grows rather than the text wrapping to a third
     line. */
  max-width: 300px;
}

/* The name and what is happening to it share the second line: the status is
   about this place, so it reads as part of naming it rather than as a third
   thing stacked underneath. */
.named {
  display: flex;
  align-items: baseline;
  gap: 9px;
  min-width: 0;
}

.place {
  font-size: 16px;
  color: var(--vellum);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.meta,
.named > .label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.named > .label {
  flex: 0 0 auto;
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
    padding: 8px 10px 9px 11px;
  }

  .where {
    max-width: none;
    gap: 2px;
  }

  .named {
    gap: 7px;
  }

  .place {
    font-size: 13.5px;
  }

  .actions {
    gap: 2px;
  }

  .rule {
    margin: 2px 1px 11px;
  }
}

/* §9.5.3 -- the pin reads as a statement, not a disabled control. Nothing here
   is a button, because the two ways out are a fight that has its own cell and a
   clock that needs no help. */
.pinned {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 4px 2px;
}

.pinned .warn {
  color: var(--ember);
}
</style>
