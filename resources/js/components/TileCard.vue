<script setup lang="ts">
/**
 * The selected hex, sitting just above the dock.
 *
 * Deliberately separate from the dock: the dock answers "what can I do here",
 * this answers "what am I pointing at, and is it worth walking to". Travel lives
 * here rather than in the dock for exactly that reason -- it is the one action
 * that is about somewhere else, and putting it beside the haul and mine time is
 * what turns those numbers into a decision.
 *
 * Compact by default, and disclosed twice over. The card opens to what this
 * ground is worth; each verb on it then opens to how its clock was arrived at,
 * because that matters exactly once -- the first time a better tool does not
 * shorten a mine as much as expected and the rate has to explain itself.
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
import { dungeonProp, waterGlyph } from '@/map/props'
import HexAction from '@/shell/HexAction.vue'
import SvgIcon from './SvgIcon.vue'
import LineMarks from './LineMarks.vue'
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
 * §5.1 -- this hex has something to work, whether or not you may work it now.
 *
 * Deliberately not `mine`: a mine is priced at zero the moment a verb is
 * refused, which took the slot count off the plate exactly when it was most
 * worth reading. Two seats are a fact about the GROUND, shared with everybody,
 * and they hold whether the belt is ready or not -- so the plate keeps saying
 * how many are free and the dock keeps saying what you may do about it.
 */
const seam = computed(() => Boolean(!unseen.value && preview.value?.material))


/**
 * The portrait's material, scouted or not.
 *
 * The server's preview when there is one, and the tile's own material when
 * there is not -- which out of sight is every time, because the preview
 * endpoint refuses unscouted ground (§5.6). That is not a leak: a hex's variant
 * is a pure function of (col, row, seed), the map already paints the fogged
 * tile in that variant's own tint, and the card's title has always named it
 * ("Ironwood Grove"). A blank portrait over a title that named the ground was
 * the card being coy about something it had just said.
 *
 * What stays fogged is the live half the server owns: how much is left, who is
 * standing on it, what the work would cost.
 */
const mat = computed(() => {
  const key = preview.value?.material ?? tile.value?.material

  return key ? MATERIALS[key] : null
})

/**
 * §6 -- what the settlement standing here refines, said as the material each
 * line turns out. The map draws one billet per line at the foot of the tile;
 * this is the same fact with the names on it.
 *
 * Not sight-gated, and neither is the name above: what a settlement RUNS falls
 * out of (col, row, seed) exactly as its tier does, and the map draws the same
 * billets on a fogged glyph. A walk of four days is a decision, and deciding it
 * blind was never the fog protecting anything.
 */
const refines = computed(() => tile.value?.settlement?.lines ?? [])

/**
 * §9.1 -- the mouth, in the portrait, at the size the other tiles are drawn.
 *
 * The map's own drawing rather than a second one: its coordinates run from the
 * hex center, so the viewBox is a square around that origin.
 */
const dungeonSpecimen = `<svg viewBox="-17 -15 34 34" width="34" height="34" aria-hidden="true">${dungeonProp()}</svg>`

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
 * Named with the dock's own words -- Mine, Gather, Hunt -- because these rows
 * are the price list for those three buttons and the correspondence should be
 * exact. They used to read "Woodcutting reward", which repeated a noun on every
 * row, said "reward" three times over a set of pips that are visibly rewards,
 * and wrapped to two lines on a phone. Which line the seam trains is the lede's
 * job and it is already doing it.
 */
const tables = computed(() => {
  const p = preview.value
  if (!p) return []

  return [
    { key: 'mine', label: 'Mine', rows: drops.value, cost: p },
    { key: 'gather', label: 'Gather', rows: gatherDrops.value, cost: p.gather },
    { key: 'hunt', label: 'Hunt', rows: huntDrops.value, cost: p.hunt },
  ].filter((t) => t.rows.length)
})

/**
 * §7.3 -- which verb has its arithmetic showing, or none.
 *
 * One at a time. The numbers a prospector is actually comparing are the three
 * clocks, and those are on the closed rows already -- so there is never a
 * reason to hold two breakdowns open, and an accordion keeps the card inside
 * the height it has to share with the dock (§13.2).
 */
const openVerb = ref<string | null>(null)

const toggleVerb = (key: string) => {
  openVerb.value = openVerb.value === key ? null : key
}

/**
 * The server costs a mine for any workable hex in sight, standing on it or not,
 * so the card can be read as a scouting report: what this seam is worth, and
 * what it would take. Whether you may act on it is a separate line.
 */
const mine = computed(() =>
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

/** A mine pins you to the hex you are working until you claim or drop it. */
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
 * §5.6 -- what the card may call a hex it has never stood on.
 *
 * Everything derived from (col, row, seed) is fair: the lie of the land, water,
 * and WHO LIVES THERE -- their name, their tier and the lines they run. The
 * atlas has always charted all of it at any distance, and the same bundle
 * computes it here, so withholding it on this card was a fiction rather than a
 * fog. What is actually held back is the server's half: depletion, who is
 * working the ground, what a hex would pay.
 *
 * A way down keeps its plain label, because a dungeon's NAME is the one thing
 * out there that is not derived -- it comes off the world config with the site.
 */
const title = computed(() => {
  const t = tile.value
  if (!t) return ''

  // §5.3 -- water is named whether it is scouted or not. What the server keeps
  // back out there is live state, and a lake is terrain: the client derives it
  // from the seed like every other hex, so pretending not to know would be a
  // fog the map does not actually have.
  if (t.water) return waterLabel(t.biome, t.water)

  if (unseen.value && !t.settlement) {
    return t.dungeon ? 'A way down' : VARIANT_LABEL[t.variant]
  }

  return t.settlement?.name ?? t.dungeon?.name ?? VARIANT_LABEL[t.variant]
})

// A new hex is a fresh question; do not carry the previous one's expansion.
watch(tile, () => {
  open.value = false
  openVerb.value = null
})

// Closing the card closes what was open inside it, so reopening is the same
// glance every time rather than whatever was left showing.
watch(open, (isOpen) => {
  if (!isOpen) openVerb.value = null
})
</script>

<template>
  <Transition name="rise">
    <div v-if="tile" class="card plate">
      <div class="inner">
        <div class="head">
        <button class="summary" type="button" @click="open = !open">
          <!--
            The portrait: what this hex is ABOUT, in one slot.

            A seam is its material. A settlement is the lines it runs (§6),
            packed into the same nested comb the map and the bag use -- so the
            slot answers the same question for both kinds of ground rather than
            holding a blank hexagon on every hex that is not a mine.
          -->
          <span class="portrait">
            <LineMarks v-if="refines.length" layout="comb" :lines="refines" />
            <SvgIcon v-else-if="mat" :svg="materialIcon(mat, 34)" />
            <!-- Water and a dungeon mouth are drawn rather than named, in the
                 map's own hand: the specimen is the same drawing the almanac
                 uses, and the mouth is the one the map puts on the hex. -->
            <SvgIcon v-else-if="tile.water" :svg="waterGlyph(tile.biome, tile.water, 34)" />
            <SvgIcon v-else-if="tile.dungeon" :svg="dungeonSpecimen" />
            <span v-else class="pin" aria-hidden="true" />
          </span>

          <span class="grow text">
            <span class="label">
              {{ RING_LABEL[tile.ring] }} · {{ tile.col }},{{ tile.row }}
            </span>
            <span class="name">{{ title }}</span>
          </span>

          <!-- Slots alone, and always. The haul and the clock used to sit here
               too, and once each verb started pricing itself they were the
               mining row's two numbers said a second time thirty pixels higher
               -- and said for one verb as though they held for all three.

               Slots stays because it is a fact about the HEX rather than about
               a verb: two seats, shared with everybody, whatever you came here
               to do. Which is also why it no longer hangs off the mine -- a
               mine prices at zero the moment a verb is refused, so the one
               moment the plate went quiet was the moment you had no tool, and
               it filled the gap with a sentence the button beside it was
               already saying. -->
          <span v-if="depleted" class="reason tiny">
            Regrows in {{ formatDuration(tile.regrowsAt - game.now) }}
          </span>

          <span v-else-if="seam" class="stats">
            <span class="stat">
              <span class="label">Slots</span>
              <span class="readout">{{ tile.slotsUsed }}/2</span>
            </span>
            <!-- §5.1 -- the map fills a notch for anybody at work on the hex and
                 §5.5 says only mining takes a seat, so a hunt or a fight leaves
                 the two counts disagreeing. Printed only when they do: without
                 it a hex drawn busy would read "0/2" here and one of the two
                 would look wrong. -->
            <span v-if="tile.workers > tile.slotsUsed" class="stat">
              <span class="label">At work</span>
              <span class="readout">{{ tile.workers }}</span>
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

          <span v-else class="reason tiny">{{ preview?.reason }}</span>

          <span v-if="mine || tables.length" class="chevron" :class="{ open }" aria-hidden="true">
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
          :primary="canTravel && Boolean(mine)"
          :disabled="!canTravel || game.busy"
          :hint="travelHint"
          @activate="tile && game.travelTo(tile.col, tile.row)"
        />
        </div>

        <div v-if="open && (tables.length || (mine && mat))" class="detail">
          <!-- The line comes from the server, not from the material: a scrap
               haul still belongs to the hex's own line, §4.0. -->
          <p v-if="mine && mat" class="tiny muted lede">
            {{ mat.name }} · trains {{ SKILL_BY_KEY[mine.skill ?? skillForMaterial(mat.key)].name }}
          </p>

          <!-- §4 / §7.3 -- one price line per verb, because this hex answers to
               up to three of them and each has its own clock now: a dig takes
               the seam at the pick's rate, bare hands take what is lying about
               at no rate at all, and a hunt takes the animal at the bow's.
               Folding them together would say the seam drops essence and that
               all three cost the same hour.

               The kinds stay on the face of it -- what a hex can give up is a
               fact about the place and the card owes it at a glance. What is
               behind the tap is only how the clock got to its number. -->
          <div v-for="t in tables" :key="t.key" class="inset verb" :class="t.key">
            <button
              class="price"
              type="button"
              :aria-expanded="openVerb === t.key"
              :aria-controls="`verb-${t.key}`"
              @click="toggleVerb(t.key)"
            >
              <span class="label muted">{{ t.label }}</span>
              <span class="leader" aria-hidden="true" />
              <!-- §8.0 rule 1 -- nothing in your hands and nothing learned is
                   not a very long mine, it is no mine. A clock here would be a
                   number that cannot be reached. -->
              <span v-if="t.cost.able" class="readout clock">
                {{ formatSpan(wallTime(t.cost.seconds)) }}
              </span>
              <span v-else class="readout clock unable">Can't</span>
              <span class="chevron small" :class="{ open: openVerb === t.key }" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="m6 15 6-6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </span>
            </button>

            <div class="pips">
              <span v-for="d in t.rows" :key="d.key" class="pip" :title="d.name">
                <SvgIcon :svg="materialIcon(d, 18)" />{{ d.name }}
              </span>
            </div>

            <!-- §7.3 -- the rate, and nothing about hit points. What a player
                 can act on is that the number goes up when the tool does.
                 
                 The first line is the tool OR the hands, never both, because
                 §4.0 gives gathering no tool and §8.0 rule 1 gives the other
                 two no bare-handed mode. The tree's own points sit under the
                 line level and are drawn only once there are some. -->
            <div v-if="openVerb === t.key" :id="`verb-${t.key}`" class="rates tiny">
              <div class="row-between">
                <span class="muted">{{ t.key === 'gather' ? 'Bare hands' : 'Tool' }}</span>
                <span class="readout">{{ t.cost.toolAttack }}</span>
              </div>
              <div class="row-between">
                <span class="muted">Skill</span>
                <span class="readout" :class="{ good: t.cost.skillAttack > 0 }">
                  +{{ t.cost.skillAttack }}
                </span>
              </div>
              <div v-if="t.cost.skillBite > 0" class="row-between">
                <span class="muted">Tree</span>
                <span class="readout good">+{{ t.cost.skillBite }}</span>
              </div>
              <div class="row-between rate">
                <span>Your rate</span>
                <span class="readout" :class="t.cost.able ? 'good' : 'unable'">
                  {{ t.cost.rate }}/s
                </span>
              </div>
              <p v-if="!t.cost.able" class="note unable">
                {{
                  t.key === 'hunt'
                    ? 'No bow. A herd is not something you take by hand.'
                    : 'Nothing to work it with. Equip a tool for this line, or gather it by hand.'
                }}
              </p>
              <p v-if="t.cost.clamped" class="note clamp">
                Held at the {{ floorMinutes }}-minute guard. Nothing works this
                ground faster.
              </p>
              <p v-if="compressed" class="note">
                Game clock says {{ formatSpan(gameTime(t.cost.seconds)) }} —
                the development clock is ×{{ game.timeScale }}.
              </p>
            </div>
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

/*
 * The portrait's own square. Everything that can stand here -- a seam's
 * material, a settlement's comb, or the empty hexagon -- is centered in it and
 * measured from the same edge, so a card's name starts at one x whatever the
 * hex turns out to be. A capital's comb is wider than the square and grows it;
 * it stays centered, which is the part that reads.
 */
.portrait {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  min-width: 34px;
  min-height: 34px;
}

/*
 * The portrait standing empty: water, a dungeon mouth, open country. Smaller
 * than the slot and darker than anything else on the plate, because it is a
 * place where a fact would be rather than a fact -- at full size and full
 * contrast it read as a thing the hex was holding.
 */
.pin {
  width: 26px;
  height: 26px;
  background: var(--hud-line-soft);
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
  /* The marks beside it are fixed width, so the name is what gives when a
     capital running all five lands on a phone. */
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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

/* §13.3 -- ember is the color of a state to deal with, and a verb you cannot
   perform is exactly that: something to go and fix, not a warning about danger
   and not a disappointment. */
.unable {
  color: var(--ember);
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

/* §4 / §7.3 -- a verb, priced.
 *
 * The card is a costing sheet and now reads like one: a name on the left, the
 * clock on the right, and a hairline running between them so the three verbs
 * scan as a column of prices rather than three unrelated blocks. That leader is
 * the only decoration here and it is doing work -- it is what lets an eye drop
 * straight down the times without reading a word. */
.verb {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.verb + .verb {
  margin-top: 8px;
}

.price {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  /* A thumb target rather than a text baseline. The row is the full width of
     the card, so the only dimension that needed arguing for is this one. */
  min-height: 28px;
  padding: 2px 0;
  background: none;
  border: 0;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.price:focus-visible {
  outline: 1px solid var(--vellum-dim);
  outline-offset: 3px;
}

.leader {
  flex: 1 1 auto;
  min-width: 12px;
  height: 1px;
  background: var(--line);
}

.clock {
  font-size: 12px;
  font-variant-numeric: tabular-nums;
  flex: 0 0 auto;
}

.chevron.small {
  display: inline-flex;
}

/* §7.3 -- the arithmetic, under a rule so it reads as an aside to the price
   above rather than as more of the reward list. */
.rates {
  border-top: 1px solid var(--line);
  padding-top: 6px;
}

.rates .row-between {
  padding: 1px 0;
}

.rates .rate {
  margin-top: 3px;
  padding-top: 4px;
  border-top: 1px solid var(--line);
}

/* Pips rather than rows: this is a set of possibilities, not a table of values,
   and a column of numbers would imply odds that are deliberately not given. */
.pips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
}

.pip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--vellum-dim);
}

/* Gold, the same reading the dock and the map already give a herd: an
   opportunity standing here rather than ground that will keep. */
.verb.hunt .label {
  color: var(--gold);
}

/* §4.0 -- the floor under the ladder, and it says so by being the quiet one. */
.verb.gather .label {
  color: #7b8580;
}

@media (prefers-reduced-motion: reduce) {
  .chevron {
    transition: none;
  }
}

</style>
