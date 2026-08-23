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
import { MINING } from '@/game/balance'
import { waterLabel } from '@/game/water'
import { VARIANT_LABEL } from '@/game/variants'
import { hexDistance } from '@/map/hexGeometry'
import { materialIcon } from '@/icons/procedural'
import HexAction from '@/shell/HexAction.vue'
import SvgIcon from './SvgIcon.vue'
import type { MaterialKey } from '@/game/types'

const game = useGame()

const floorMinutes = Math.round(MINING.floorSeconds / 60)

const tile = computed(() => game.selectedTile)
const preview = computed(() => game.preview)

const distance = computed(() => {
  const char = game.character
  return char && tile.value ? hexDistance(char.col, char.row, tile.value.col, tile.value.row) : 0
})

const open = ref(false)

/**
 * What the map's glyph says, in words. Out of sight a settlement is a tier and
 * nothing else (§5.6), so this is the whole of what the card may call it.
 */
const TIER_LABEL: Record<string, string> = {
  village: 'A village',
  city: 'A city',
  capital: 'A capital',
}

const depleted = computed(() => Boolean(tile.value && tile.value.regrowsAt > game.now))

/**
 * §5.6 -- outside sight there is no scouting report, because there is no
 * scouting. The card falls back to what the seed already told this device: the
 * lie of the land, whether anybody lives there, and how long the walk is.
 *
 * Derived from distance rather than from `preview` being null, so it does not
 * flicker through "unscouted" while a request for a hex in sight is in flight.
 */
const unseen = computed(() => distance.value > game.sight)

/**
 * The seam's icon, and only while there is a seam to show.
 *
 * Gated on sight rather than on `preview` alone: setting off drops sight to
 * zero without clearing the selection, and a material icon left standing over
 * "unscouted" would be the card contradicting itself.
 */
const mat = computed(() =>
  !unseen.value && preview.value?.material ? MATERIALS[preview.value.material] : null,
)

/**
 * §4 -- the kinds this hex can pay out, in order of likelihood.
 *
 * Capped at what fits on a card rather than listing every tail entry: a list
 * long enough to scroll stops being a glance and starts being homework.
 */
const DROPS_SHOWN = 7

const shown = (keys: readonly MaterialKey[] | undefined) =>
  (keys ?? []).slice(0, DROPS_SHOWN).map((k) => MATERIALS[k])

/**
 * One list per verb, because this hex may answer to two of them.
 *
 * §5.5 -- a herd is not a mode of mining, it is a different thing to do on the
 * same ground, and it pays out of a different table: pelt, horn, sinew and the
 * biome's critter. Reading a hex therefore means reading every offer on it, and
 * folding them into one list would say the seam drops pelt.
 *
 * The hunting list is here only when there is a herd to point a bow at, and the
 * server empties it when there is no bow -- so its presence IS the answer to
 * "can I hunt here", and nothing has to say so twice.
 */
const drops = computed(() => shown(preview.value?.drops))
const gatherDrops = computed(() => shown(preview.value?.gather?.drops))
const huntDrops = computed(() => shown(preview.value?.hunt?.drops))

/**
 * One entry per verb the dock offers here, in the order the dock offers them.
 *
 * Each is named by its own line rather than by a phrase, because the names are
 * already the thing that tells them apart -- a hex in the badlands offers a
 * Quarrying reward and a Gather reward, and nothing has to say more than that.
 */
const tables = computed(() => {
  const line = preview.value?.skill

  return [
    {
      key: 'mine',
      label: line ? `${SKILL_BY_KEY[line].name} reward` : 'Reward',
      rows: drops.value,
    },
    { key: 'gather', label: 'Gather reward', rows: gatherDrops.value },
    { key: 'hunt', label: 'Hunting reward', rows: huntDrops.value },
  ].filter((t) => t.rows.length)
})

/**
 * The server costs a trip for any workable hex in sight, standing on it or not,
 * so the card can be read as a scouting report: what this seam is worth, and
 * what it would take. Whether you may act on it is a separate line.
 */
const trip = computed(() =>
  !unseen.value && preview.value && preview.value.seconds > 0 ? preview.value : null,
)


/** Honest game time explains the rule; wall time is what you actually wait. */
const gameTime = (seconds: number) => seconds * 1000
const wallTime = (seconds: number) => (seconds * 1000) / game.timeScale
const compressed = computed(() => game.timeScale > 1)

/* ------------------------------------------------------------------ travel */

const onSelected = computed(() => {
  const char = game.character
  return Boolean(char && tile.value && char.col === tile.value.col && char.row === tile.value.row)
})

/** A trip pins you to the hex you are working until you claim or drop it. */
const working = computed(() => game.fieldJob)

/** §7.6 -- too much in the bag and the road is shut until it is not. */
const overloaded = computed(() => Boolean(game.character?.bag.over))

/**
 * §5.6 -- distance is not a gate any more. Every hex on the map can be walked
 * to, scouted or not. What stops you is being there already, or being busy with
 * something else.
 *
 * §7.6's overloaded bag is deliberately NOT here. It is the one refusal the
 * player can undo from where they are standing, so it must be *said* rather
 * than grayed out: the button stays live, the server refuses the walk, and its
 * own message -- which names the limit and how much to shed -- arrives as a
 * toast. A dead button explains nothing and reads as a bug.
 */
const canTravel = computed(
  () => Boolean(tile.value) && !onSelected.value && !working.value && !game.travel,
)

/** What the walk actually costs, which is the decision now that reach is not. */
const eta = computed(() =>
  tile.value ? game.travelEta(tile.value.col, tile.value.row) : 0,
)

const travelHint = computed(() => {
  if (onSelected.value) return 'You are already here'
  if (game.travel) return 'You are on the road — stop before setting a new course'
  if (working.value) {
    return working.value.endsAt <= game.now
      ? 'Claim your reward before you move on'
      : 'You are working this hex — claim or drop it first'
  }
  // Named against the limit that is actually broken, because "bag full" in
  // front of a map that will not move reads as a bug rather than a decision.
  if (overloaded.value) {
    const bag = game.character!.bag
    return bag.units > bag.unitCap
      ? `Too much to carry — ${bag.units}/${bag.unitCap} units`
      : `Too many kinds — ${bag.rows}/${bag.rowCap} straps`
  }
  return `${distance.value} hexes · ${formatSpan(eta.value)}`
})

/**
 * Unscouted ground is named by what the glyph on the map already says and no
 * more: a tier, or the biome under it. Withholding the settlement's name is not
 * decoration -- the map draws a pip out there rather than a label, and a card
 * that quietly knew better would make the pip a lie.
 */
const title = computed(() => {
  const t = tile.value
  if (!t) return ''

  // §5.3 -- water is named whether it is scouted or not. What the server keeps
  // back out there is live state, and a lake is terrain: the client derives it
  // from the seed like every other hex, so pretending not to know would be a
  // fog the map does not actually have.
  if (t.water) return waterLabel(t.biome, t.water)

  if (unseen.value) {
    return t.settlement
      ? (TIER_LABEL[t.settlement.tier] ?? VARIANT_LABEL[t.variant])
      : t.dungeon
        ? 'A way down'
        : VARIANT_LABEL[t.variant]
  }

  return t.settlement?.name ?? t.dungeon?.name ?? VARIANT_LABEL[t.variant]
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
              <span class="label">Reward</span>
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
          <!-- §5.6 -- the walk, on any hex that is not the one underfoot.
               Distance is the whole cost of going anywhere, so the card owes it
               wherever it can be answered: a settlement two hexes off and one
               four days away are the same tap and very different decisions,
               and hours are what says which. -->
          <span v-else-if="distance > 0" class="stats">
            <span class="stat">
              <span class="label">Walk</span>
              <span class="readout">{{ distance }} hex</span>
            </span>
            <span class="stat">
              <span class="label">Takes</span>
              <span class="readout">{{ formatSpan(eta) }}</span>
            </span>
          </span>

          <span v-else class="reason tiny">
            {{ depleted ? `Regrows in ${formatDuration(tile.regrowsAt - game.now)}` : preview?.reason }}
          </span>

          <span v-if="trip || tables.length" class="chevron" :class="{ open }" aria-hidden="true">
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

        <div v-if="open && (tables.length || (trip && mat))" class="detail">
          <!-- The line comes from the server, not from the material: a scrap
               haul still belongs to the hex's own line, §4.0. -->
          <p v-if="trip && mat" class="tiny muted lede">
            {{ mat.name }} · trains {{ SKILL_BY_KEY[trip.skill ?? skillForMaterial(mat.key)].name }}
          </p>

          <!-- §4 -- what this ground can give up, most likely first and with no
               odds attached. Naming the odds would turn a hex into a spreadsheet;
               naming the kinds is what lets you decide whether to spend an hour
               here, which is the only decision the card exists to support.
               
               One list per verb, because the three verbs pay out of three
               tables: a dig takes the seam, bare hands take what is lying
               about, and a hunt takes the animal. Folding them together would
               say the seam drops essence. A list is here only when its verb is
               -- the hunting table arrives empty without a herd or without a
               bow, so its presence IS the answer to "can I hunt here". -->
          <div
            v-for="t in tables"
            :key="t.key"
            class="inset drops"
            :class="t.key"
          >
            <span class="label muted">{{ t.label }}</span>
            <div class="pips">
              <span v-for="d in t.rows" :key="d.key" class="pip" :title="d.name">
                <SvgIcon :svg="materialIcon(d, 18)" />{{ d.name }}
              </span>
            </div>
          </div>

          <div v-if="trip" class="inset breakdown tiny">
            <div class="row-between">
              <span class="muted">Ground to work through</span>
              <span class="readout">{{ trip.durability }}</span>
            </div>
            <div class="row-between">
              <span class="muted">Tool</span>
              <span class="readout" :class="{ good: trip.toolAttack > 0 }">
                +{{ trip.toolAttack }}/s
              </span>
            </div>
            <div class="row-between">
              <span class="muted">Skill</span>
              <span class="readout" :class="{ good: trip.skillAttack > 0 }">
                +{{ trip.skillAttack }}/s
              </span>
            </div>
            <div class="row-between">
              <span class="muted">Your rate</span>
              <span class="readout good">{{ trip.rate }}/s</span>
            </div>
            <hr class="divider" />
            <div class="row-between">
              <span>Mine time</span>
              <span class="readout">{{ formatSpan(gameTime(trip.seconds)) }}</span>
            </div>
            <p v-if="trip.clamped" class="note clamp">
              Clamped to the {{ floorMinutes }}-minute floor. A faster rate is
              wasted on this hex.
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

  .head {
    gap: 8px;
    padding: 8px 10px 9px 11px;
  }

  .summary {
    gap: 8px;
  }

  .name {
    font-size: 13px;
  }

  .stats {
    gap: 9px;
  }

  .stat .readout {
    font-size: 12px;
  }

  .reason {
    max-width: 130px;
  }

  .detail {
    padding: 0 11px 10px;
  }
}

/* §4 -- the drop list. Pips rather than rows: this is a set of possibilities,
   not a table of values, and a column of numbers would imply odds that are
   deliberately not given. */
.drops {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.drops .pips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
}

.drops .pip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--vellum-dim);
}

.drops + .drops {
  margin-top: 8px;
}

/* Gold, the same reading the dock and the map already give a herd: an
   opportunity standing here rather than ground that will keep. */
.drops.hunt .label {
  color: var(--gold);
}

/* §4.0 -- the floor under the ladder, and it says so by being the quiet one. */
.drops.gather .label {
  color: #7b8580;
}
</style>
