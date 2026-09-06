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
import { MONSTERS } from '@/game/monsters'
import { TROPHY_BY_TIER } from '@/game/spoils'
import { monsterSpecimen } from '@/map/props'
import { formatDuration, formatSpan } from '@/game/formulas'
import { MINING } from '@/game/balance'
import { groundLabel } from '@/game/ground'
import { hexDistance } from '@/map/hexGeometry'
import { worldParams } from '@/game/worldgen'
import { materialIcon } from '@/icons/procedural'
import {
  animalMark,
  deadGlyph,
  dungeonProp,
  pocketSpecimen,
  unscoutedGlyph,
  waterGlyph,
} from '@/map/props'
import HexAction from '@/shell/HexAction.vue'
import SvgIcon from './SvgIcon.vue'
import LineMarks from './LineMarks.vue'
import type { MaterialKey } from '@/game/types'

const game = useGame()

const floorMinutes = Math.round(MINING.floorSeconds / 60)

const tile = computed(() => game.selectedTile)
const preview = computed(() => game.preview)

const distance = computed(() => {
  // §5.6 -- from where the walker IS. Measured off the character it counted
  // from the hex they set off from, so a road already half walked still quoted
  // the whole of it.
  return tile.value ? hexDistance(game.hereCol, game.hereRow, tile.value.col, tile.value.row) : 0
})

const open = ref(false)

const depleted = computed(() => Boolean(tile.value && tile.value.regrowsAt > game.now))

/**
 * §5.7 -- rich ground, and when it closes.
 *
 * Read off the PREVIEW rather than the tile, because it is here to explain a
 * haul the preview already reports: the server decided both in one pass, and
 * two sources for one fact is two chances to disagree about whether the bonus
 * applied.
 */
const pocketUntil = computed(() => preview.value?.pocketUntil ?? null)

/**
 * §5.7 -- the same animal the map draws on the hex, at card scale.
 *
 * The tell IS the creature, so the row explaining it has to be the creature
 * too: a symbol here and an animal out there would be two things to learn for
 * one fact. It is drawn on its own ground for the reason every specimen is --
 * a silhouette against nothing has no edge to read against.
 */
const pocketMark = computed(() =>
  tile.value && pocketUntil.value ? pocketSpecimen(tile.value.biome, 30) : '',
)


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
 *
 * Read off the TILE rather than off the preview, and that is the fix for a
 * flicker rather than a shortcut. Whether a hex holds a seam is a pure
 * function of (col, row, seed) -- the client runs the same one the server does
 * -- so waiting on a request to find out made the plate say one thing and then
 * another: the walk, and then the slots, half a second apart, every time a hex
 * was tapped. The fog is still respected, because `unseen` is the gate: out of
 * sight the client is not allowed to answer this and does not.
 */
const seam = computed(() => Boolean(!unseen.value && tile.value?.material))


/**
 * The portrait's material -- the SERVER's, and only where it has one.
 *
 * It used to fall back to the tile's own material out of sight, on the argument
 * that a variant is a pure function of (col, row, seed) and the map was already
 * painting the fogged hex in that variant's tint anyway, so a blank portrait
 * was the card being coy about something it had just said.
 *
 * That argument died with §5.2. Dead ground wears its biome's own fill, so the
 * map now says nothing at a distance about whether a hex can be worked at all
 * -- and a card that answered it anyway would be handing back the exact thing
 * the fog was rearranged to withhold. Whether there is a seam out there is the
 * question the walk exists to answer.
 */
const mat = computed(() => {
  const key = preview.value?.material

  return key ? MATERIALS[key] : null
})

/**
 * §5.2 -- the dead-ground portrait, for scouted ground only.
 *
 * Out of sight the slot falls through to the blank pin, and that is the honest
 * answer rather than a missing one: the title says "Forest", the pin says no
 * report, and the two agree. A dead hex drawn under a live biome name would be
 * the card asserting something it cannot know, which is exactly what naming
 * every fogged hex "Deadwood" did before.
 *
 * So out there every hex looks alike whatever is under it -- which is the whole
 * point -- and the tell arrives when you do.
 *
 * §5.6 is explicit that a place's identity is terrain and the fog was never
 * entitled to it, so a settlement keeps its name and its lines, water keeps its
 * name, and a dungeon mouth keeps its glyph at any distance. What the fog owns
 * is what the ground would PAY.
 */
const deadFace = computed(() => Boolean(tile.value?.dead) && !unseen.value)

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
 * One list per verb, because this hex answers to two of them.
 *
 * §4.0 -- a dig and a bare-handed gather work the same ground at different
 * rates and off different tables, so reading a hex means reading both. Folding
 * them into one list would say the seam drops scrap.
 */
const drops = computed(() => shown(preview.value?.drops))
const gatherDrops = computed(() => shown(preview.value?.gather?.drops))
/** §5.5 -- and what comes off the animal, when there is one standing here. */
const huntDrops = computed(() => shown(preview.value?.hunt?.drops))

/**
 * §5.5 -- the animal, and it is only on the card when there is one.
 *
 * A hunt costing comes back for every hex, because the verb exists whether or
 * not the bucket put anything on the ground; what says there is something to
 * hunt is this, not the costing.
 */
const animal = computed(() => preview.value?.hunt?.animal ?? null)

/**
 * §9.5.2 -- the pack standing here, and everything known about it.
 *
 * It used to be a `Study` plate opened from the dock, which put what a hex
 * HOLDS in a modal while what a hex IS sat on the card underneath it. They are
 * one question -- *what am I pointing at* -- and this is the card that answers
 * it, so a monster is a row on it like the seam and the animal are.
 *
 * Two things fall out of the move rather than being arranged. It reaches **any
 * hex in sight** where the plate only ever reached the one under your feet, so
 * a pack two hexes off can be read before walking into it -- which is what a
 * card is for. And it costs nothing at all: the pack is already derived on this
 * device and the roster is already mirrored, so there is no request and nothing
 * to be stale.
 *
 * Gated on sight for the same reason the map gates the drawing (§13.2): a pack
 * is live state, and reading one from four days away is the scanner §5.6 exists
 * to refuse.
 */
const pack = computed(() =>
  !unseen.value && tile.value?.pack ? (MONSTERS[tile.value.pack.key] ?? null) : null,
)

/** §9.5.2 -- the profile is what a player reads to know HOW to fight it. */
const PROFILE_NOTE: Record<string, string> = {
  brute: 'Hits hard, guards badly. It empties your kit fast and empties fast in turn.',
  carapace: 'Guards hard, hits badly. Getting through the front is the whole fight.',
  swift: 'Middling at both, and it wears a weapon harder than its numbers suggest.',
}

/**
 * §9.5.6 -- and the one number a profile does not explain.
 *
 * `wearBias` moves where the bill lands, never how big it is, so it is said as
 * a sentence rather than as a figure: "1.5" means nothing at the moment you are
 * deciding whether to swing at something.
 */
const wearNote = computed(() =>
  pack.value && pack.value.wearBias > 1
    ? 'Blunts what it is hit with — more of the bill lands on your weapon and gloves.'
    : null,
)

/**
 * Is there anything behind the chevron, and therefore a chevron at all.
 *
 * **One computed, read in both places, and that is the whole of the fix.** The
 * summary asked `mine || tables.length` and the panel under it asked
 * `tables.length || (mine && mat)` -- two conditions for one question, which is
 * two chances to disagree. They did: a hex whose only news is a pack standing
 * on it satisfies neither, so the card offered no arrow and the hostile was
 * invisible until you happened to tap a row with nothing under it.
 *
 * `mine && mat` rather than `mine`, because the lede that branch draws needs
 * the material: a costing with nothing to cost is not a reason to open a panel.
 */
const hasDetail = computed(() => Boolean(tables.value.length || (mine.value && mat.value) || pack.value))

/**
 * §9.5.8 -- what it pays, in the same flat run of pips every verb here uses.
 *
 * Most likely first, exactly as a seam's kinds are: the plate and the trophy
 * come off every win, the ichor often, the grade above it rarely. It carried
 * its own always/often/rarely column for a while, which was a table where the
 * rest of the card has a list -- and the odds are already the order.
 *
 * Gold is not among them, because gold needs no strap (§7.6). That is what
 * makes it worth a sentence in the breakdown rather than a pip out here.
 */
const packDrops = computed(() => {
  const d = pack.value
  if (!d) return []

  return [d.plate, TROPHY_BY_TIER[d.tier], d.ichor, d.rareSpoil]
    .filter((k): k is string => Boolean(k))
    .map((k) => MATERIALS[k as MaterialKey])
    .filter(Boolean)
})

/**
 * One entry per verb the dock offers here, in the order the dock offers them.
 *
 * Named with the dock's own words -- Mine, Gather -- because these rows are the
 * price list for those buttons and the correspondence should be exact. They
 * used to read "Woodcutting reward", which repeated a noun on every row, said
 * "reward" twice over a set of pips that are visibly rewards, and wrapped to two
 * lines on a phone. Which line the seam trains is the lede's job.
 */
const tables = computed(() => {
  const p = preview.value
  if (!p) return []

  return [
    { key: 'mine', label: 'Mine', rows: drops.value, cost: p },
    { key: 'gather', label: 'Gather', rows: gatherDrops.value, cost: p.gather },
    // §5.5 -- the third verb, and the only one of the three that is not always
    // on offer: the seam is a property of the ground and the animal is not.
    // Named for the animal rather than for the verb, because "Hunt" repeated
    // over a row of hides says nothing the button below has not, and WHICH
    // animal is the whole of what decides the rung.
    { key: 'hunt', label: 'Hunt', rows: huntDrops.value, cost: p.hunt },
  ].filter((t) => t.cost && t.rows.length)
})

/**
 * §5.7 -- what rich ground multiplies the haul by.
 *
 * A RATE rather than a sentence, and in the readout slot the verbs above put
 * their clocks in: "half again on every haul" is prose about a number, and the
 * number is the thing being compared. The clock moves down to the pip row,
 * because on this card it is the one fact that expires rather than the one that
 * is being read for.
 */
const pocketRate = computed(() => `×${worldParams().pocketYield}`)

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

const onSelected = computed(() =>
  Boolean(tile.value && game.hereCol === tile.value.col && game.hereRow === tile.value.row),
)

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
  // Said in the units the limit is actually counted in, because "bag full" in
  // front of a map that will not move reads as a bug rather than a decision.
  if (overloaded.value) {
    const bag = game.character!.bag
    return `Too much to carry — ${bag.slots}/${bag.slotCap} straps`
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
  if (t.water) return groundLabel(t)

  if (unseen.value && !t.settlement) {
    // `unseen`, not `deadFace`: out here the argument is that we have not
    // LOOKED, which is true of a live hex and a dead one alike. Passing the
    // dead-portrait flag would have named the dead ones and left the living
    // ones named for their variant -- the leak, with an extra step.
    return t.dungeon ? 'A way down' : groundLabel(t, true)
  }

  return t.settlement?.name ?? t.dungeon?.name ?? groundLabel(t)
})

/*
 * A new HEX is a fresh question; do not carry the previous one's expansion.
 *
 * Keyed on the coordinates rather than on the tile object, and that is the
 * whole of it: §5.6's scheduled refresh rebuilds every tile in view, so the
 * same hex arrives as a new object several times an hour. Watching the object
 * shut an open card each time the map came back -- the reader was told to look
 * again at the very moment something they were reading had changed.
 */
watch(
  () => (tile.value ? `${tile.value.col},${tile.value.row}` : null),
  () => {
    open.value = false
    openVerb.value = null
  },
)

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
            <!-- §5.2 -- dead ground gets its own hex rather than the blank pin,
                 once you are near enough to have looked. Out of sight this
                 falls through to the pin, so a live hex and a dead one are one
                 picture until the walk is done. -->
            <SvgIcon v-else-if="deadFace" :svg="deadGlyph(tile.biome, 34)" />
            <!-- §5.6 -- unscouted: the biome's ground with nothing on it, which
                 is exactly what the map draws out there. Props either way would
                 be the card knowing more than the map does. -->
            <SvgIcon v-else-if="unseen" :svg="unscoutedGlyph(tile.biome, 34)" />
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
                 only mining takes a seat, so a fight on the hex leaves
                 the two counts disagreeing. Printed only when they do: without
                 it a hex drawn busy would read "0/2" here and one of the two
                 would look wrong. -->
            <span v-if="tile.workers > tile.slotsUsed" class="stat">
              <span class="label">At work</span>
              <span class="readout">{{ tile.workers }}</span>
            </span>
          </span>
          <!-- §5.6 -- what the walk COSTS, on a hex that is not the one
               underfoot. Distance is the whole price of going anywhere, so the
               card owes it wherever it can be answered: a settlement two hexes
               off and one four days away are the same tap and very different
               decisions.

               The hex COUNT is gone from beside it. Two readouts for one
               journey, and the one that decides anything is the clock -- five
               minutes a hex means the count is the same fact in a unit nobody
               plans in. The Travel button under this row still names both. -->
          <span v-else-if="distance > 0" class="stats">
            <span class="stat">
              <span class="label">Takes</span>
              <span class="readout">{{ formatSpan(eta) }}</span>
            </span>
          </span>

          <span v-else class="reason tiny">{{ preview?.reason }}</span>

          <span v-if="hasDetail" class="chevron" :class="{ open }" aria-hidden="true">
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

        <div v-if="open && hasDetail" class="detail">
          <!-- §9.5.2 -- the pack gets the lede the seam's material and the
               animal get, and for the same reason: the row below is a price
               list and this is what it is a price list FOR. Drawn as well as
               named, because it is the drawing the player just tapped on the
               map. -->
          <p v-if="pack" class="tiny muted lede quarry">
            <span class="mark" aria-hidden="true" v-html="monsterSpecimen(pack.key, 22)" />
            {{ pack.name }} · {{ pack.profile }} · tier {{ pack.tier }}
          </p>

          <!-- The line comes from the server, not from the material: a scrap
               haul still belongs to the hex's own line, §4.0. -->
          <p v-if="mine && mat" class="tiny muted lede">
            {{ mat.name }} · trains {{ SKILL_BY_KEY[mine.skill ?? skillForMaterial(mat.key)].name }}
          </p>

          <!-- §5.5 -- the animal gets the lede the seam's material gets, for
               the same reason: the row below is a price list and this is what
               it is a price list FOR. Which animal is the whole of what
               decides the rung of hide, so naming it is not decoration.

               Drawn as well as named, because it is the drawing the player
               just tapped on the map. -->
          <p v-if="animal" class="tiny muted lede quarry">
            <span class="mark" aria-hidden="true" v-html="animalMark(animal.key, 22)" />
            {{ animal.name }} · {{ animal.grade }} rung · trains Hunting
          </p>

          <!--
            §9.5.2 -- a fight is a verb this hex answers to, so it is priced
            like one: the same row, the same leader, the same chevron, the
            same run of pips underneath.

            **First, and that is not layout.** Nothing on the price list below
            matters while something is looking at you -- §9.5.3 refuses every
            verb on a pinned hex -- so a seam read before the pack standing on
            it is a seam read in the wrong order.

            What sits where the clock sits is the LEVEL, because it answers the
            same question a clock does on the rows beneath: the one number that
            says whether to bother reading the rest. And what is behind the tap
            is what is behind it everywhere else here -- how that number was
            arrived at.

            It carries no verdict. Whether you win is the preview's (§9.5.5)
            and when it leaves is the pin's; this is the half that is true of
            the creature whoever is reading it, which is the half a card is
            for.
          -->
          <div v-if="pack" class="inset verb fight">
            <button
              class="price"
              type="button"
              :aria-expanded="openVerb === 'fight'"
              aria-controls="verb-fight"
              @click="toggleVerb('fight')"
            >
              <span class="label muted">Fight</span>
              <span class="leader" aria-hidden="true" />
              <span class="readout clock">level {{ pack.level }}</span>
              <span class="chevron small" :class="{ open: openVerb === 'fight' }" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="m6 15 6-6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </span>
            </button>

            <!--
              §9.5.4/§9.5.5 -- the three solid numbers a fight is decided by,
              on the face and above what it pays.

              They belong here for the same reason the pips do: what a hex can
              give up is a fact about the place and the card owes it at a
              glance, and on a pack these three ARE the place. They were behind
              the chevron, which made the level the only figure on the row --
              and a level is a summary of exactly these, so the card was showing
              the conclusion and hiding the evidence.

              Above the drops rather than below, because they decide whether
              there will be any.

              Drawn as §9.5.4's own pair of channels -- a dim uppercase word and
              a mono figure -- so a monster's numbers read the way a piece of
              gear's do. The pool is one of the three because durability IS the
              health bar on both sides of the exchange.
            -->
            <div class="solids">
              <span class="solid"><span class="key">atk</span><span class="mono">{{ pack.attack }}</span></span>
              <span class="solid"><span class="key">def</span><span class="mono">{{ pack.defense }}</span></span>
              <span class="solid"><span class="key">pool</span><span class="mono">{{ pack.hp }}</span></span>
            </div>

            <div class="pips">
              <span v-for="d in packDrops" :key="d.key" class="pip" :title="d.name">
                <SvgIcon :svg="materialIcon(d, 18)" />{{ d.name }}
              </span>
            </div>

            <!-- What is behind the tap is what is behind it on every other row:
                 not another figure, but what the figures MEAN. A profile is the
                 sentence those three add up to, and the wear note is the one
                 thing they do not explain (§9.5.6).

                 Gold sits here rather than in the pips above, because it is the
                 one thing off a pack that needs no strap (§7.6) -- and a pip
                 promises a strap. -->
            <div v-if="openVerb === 'fight'" id="verb-fight" class="rates tiny">
              <div class="row-between rate">
                <span>Gold</span>
                <span class="readout">{{ pack.gold[0] }}–{{ pack.gold[1] }}</span>
              </div>
              <p class="note">{{ PROFILE_NOTE[pack.profile] }}</p>
              <p v-if="wearNote" class="note">{{ wearNote }}</p>
            </div>
          </div>

          <!-- §4 / §7.3 -- one price line per verb, because this hex answers
               to both and each has its own clock: a dig takes the seam at the
               tool's rate, bare hands take what is lying about at the rate of
               having none. Folding them together would say the seam drops
               scrap and that the two cost the same hour.

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
                Nothing to work it with. Equip a tool for this line, or gather
                it by hand.
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

          <!-- §5.7 -- under the price lists, because it is about them: rich
               ground multiplies whatever the two rows above pay. The RATE sits
               where a verb puts its clock, since that is the figure being
               compared, and the clock drops to the pip row -- it is the one
               fact on this card that expires rather than the one it is read
               for. No drop list of its own: a pocket changes how much comes
               back, never what does. -->
          <div v-if="pocketUntil" class="inset verb rich">
            <div class="price">
              <span class="label muted">Rich</span>
              <span class="leader" aria-hidden="true" />
              <span class="readout clock rate">{{ pocketRate }}</span>
            </div>
            <div class="pips">
              <span class="pip">
                <SvgIcon :svg="pocketMark" />on every haul, {{ formatDuration(pocketUntil - game.now) }} left
              </span>
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

/* §5.7 -- the rate reads as the figure it is, in the palette's one colour for
   worth (§13.3). Gold on the number alone: the row is a note about the hauls
   above it, not a third haul. */
.verb.rich .rate {
  color: var(--gold);
}

.verb.rich .pip :deep(svg) {
  display: block;
}

/* §5.7 -- gold, the palette's one colour for worth (§13.3), and the only row
   here that runs out. */
.verb.rich .label {
  color: var(--gold);
}

/* §4.0 -- the floor under the ladder, and it says so by being the quiet one. */
.verb.gather .label {
  color: #7b8580;
}

/* §9.5.2 -- and the fight is told apart the way every other verb here is:
 * one word in its own colour, on a row that is otherwise identical.
 *
 * That is the card's own idiom -- `.rich` takes gold and `.gather` goes quiet
 * -- and it is the whole reason this block needed no panel of its own. It had
 * one, tinted ember, and a tinted panel inside a card of untinted panels is a
 * different KIND of thing rather than a different verb, which is exactly the
 * claim that was wrong: a fight is a verb this hex answers to.
 *
 * Ember, because §13.3 spends it on a state to deal with and something
 * standing on the hex is the only thing on this card that is one. */
.verb.fight .label {
  color: var(--ember);
}

.verb.fight + .verb {
  margin-top: 8px;
}

/* §9.5.4 -- the pair and the pool, in the two channels gear already uses: a dim
   uppercase word and a mono figure. Told apart by the LABEL and never by
   colour, because §13.3 spends ember on a state to deal with and sap on one
   worth crossing the screen for, and a stat is neither. */
.solids {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
}

.solid {
  display: inline-flex;
  align-items: baseline;
  gap: 4px;
  font-size: 12px;
  color: var(--vellum);
}

.solid .key {
  font-size: 8.5px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--vellum-dim);
}

/* §5.5 -- the animal's lede carries its drawing, so the mark rides the text
   baseline rather than sitting above it in a block of its own. */
.lede.quarry {
  display: flex;
  align-items: center;
  gap: 5px;
}

.lede.quarry .mark :deep(svg) {
  display: block;
}

@media (prefers-reduced-motion: reduce) {
  .chevron {
    transition: none;
  }
}

</style>
