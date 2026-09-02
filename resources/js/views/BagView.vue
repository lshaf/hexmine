<script setup lang="ts">
/**
 * The bag, §7.6.
 *
 * The screen is shaped like the limit, because the limit is what the screen is
 * about -- and there is one of it now. **Straps are drawn, not counted.** Every
 * strap is a hexagon in a honeycomb -- the same nesting the map uses -- and the
 * empty ones are drawn exactly like the full ones, so free space is a thing you
 * can see rather than a number you have to subtract.
 *
 * What sits on a strap is one *stack*: fifty of a material, a hundred of a
 * draft, one piece of gear. So a big haul is several straps side by side, and
 * the comb says how much you are carrying and how many different things it is
 * in one reading. That is what removed the weight bar that used to sit above
 * it -- it was a second answer to a question the comb was already answering,
 * and the only one of the two that could be passed.
 *
 * Tapping a strap opens what is on it, rather than growing a detail panel under
 * the grid. The comb is a shape, and pushing it up the screen every time
 * something is picked would break the one reading the screen exists for. The popup is teleported to the body because the panel it sits in carries
 * a backdrop-filter, which would otherwise become the containing block for
 * anything fixed inside it.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import {
  ITEM_BY_KEY,
  MATERIALS,
  RARITY_LABEL,
  SCOPE_ACTION,
  SCOPE_LABEL,
  SLOT_LABEL,
} from '@/game/catalog'
import { statLine, swapCeilingNote, swapChanges } from '@/game/formulas'
import type { SwapChange } from '@/game/formulas'
import GearAction from '@/components/GearAction.vue'
import RepairCost from '@/components/RepairCost.vue'
import StatChips from '@/components/StatChips.vue'
import SwapMoves from '@/components/SwapMoves.vue'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { ItemDef, MaterialKey, OwnedItem } from '@/game/types'

const game = useGame()

/**
 * The size the SVG is authored at. What it ends up as is decided by `--art`
 * below, so this only has to be big enough that nothing is rasterised small.
 */
const ICON = 56

/**
 * One strap. Three things can be on it and they behave differently, so the kind
 * is carried rather than inferred later -- the detail below is a different panel
 * for each, and guessing from the key would be a second source of truth.
 *
 * `qty` is what sits on **this** strap and `held` is the whole holding across
 * all of them. The comb reads the first, because a strap is a place and the
 * badge says how full that place is; the popup reads the second, because it is
 * about the *kind* -- "my wood" is what a prospector means, not "my third strap
 * of wood", and the trash field has to arrive holding the whole stack (§11.1).
 */
type Slot =
  | { id: string; kind: 'material'; key: MaterialKey; name: string; icon: string; qty: number; held: number; straps: number }
  | { id: string; kind: 'potion'; key: string; name: string; icon: string; qty: number; held: number; straps: number }
  | { id: string; kind: 'gear'; key: string; name: string; icon: string; held: number; item: OwnedItem }

const bag = computed(() => game.bag)

/**
 * How a stack of `held` breaks across straps of `stack`, biggest first.
 *
 * A hundred and thirty wood is 50, 50, 30 -- full straps and then the
 * remainder, which is the order they read in and the order they fill in. The
 * depths come off the server (`bag.stackPotion` is not a constant: an
 * Alchemist's shelf is deeper), so this never has to guess.
 */
function across(held: number, stack: number): number[] {
  const out: number[] = []
  for (let left = held; left > 0; left -= stack) out.push(Math.min(stack, left))

  return out
}

const slots = computed<Slot[]>(() => {
  const out: Slot[] = []
  const b = bag.value
  if (!b) return out

  const held = Object.entries(game.inventory)
    .filter(([, qty]) => qty)
    .map(([key, qty]) => ({ mat: MATERIALS[key as MaterialKey], qty: qty as number }))
    // Tier first, then the big stacks: the ladder is still the order things
    // make sense in, even without headings to say so.
    .sort((a, b2) => a.mat.tier - b2.mat.tier || b2.qty - a.qty)

  for (const { mat, qty } of held) {
    const parts = across(qty, b.stackMaterial)
    parts.forEach((on, i) => {
      out.push({
        // The index is part of the id because two straps of one material are
        // two places, and a comb keyed on the material alone would collapse
        // them into one cell the moment a haul outgrew a strap.
        id: `m:${mat.key}:${i}`,
        kind: 'material',
        key: mat.key,
        name: mat.name,
        icon: materialIcon(mat, ICON),
        qty: on,
        held: qty,
        straps: parts.length,
      })
    })
  }

  for (const [key, qty] of Object.entries(game.consumables)) {
    const def = ITEM_BY_KEY[key]
    if (!qty || !def) continue
    const parts = across(qty, b.stackPotion)
    parts.forEach((on, i) => {
      out.push({
        id: `p:${key}:${i}`,
        kind: 'potion',
        key,
        name: def.name,
        icon: itemIcon({ rarity: def.rarity, palette: def.palette, size: ICON }),
        qty: on,
        held: qty,
        straps: parts.length,
      })
    })
  }

  // §7.6 -- worn gear is not carried, so only what is off the belt takes a
  // strap. One each, always: two axes are two objects with two durabilities and
  // can never be one stack.
  for (const item of game.equipment) {
    const def = ITEM_BY_KEY[item.key]
    if (item.equipped || !def) continue
    out.push({
      id: `g:${item.id}`,
      kind: 'gear',
      key: item.key,
      name: def.name,
      // §13.1 -- the slot is what picks the silhouette. Without it every piece
      // of gear draws as the flask consumables fall back to.
      icon: itemIcon({ slot: def.slot, family: def.family, rarity: def.rarity, palette: def.palette, size: ICON }),
      held: 1,
      item,
    })
  }

  return out
})

/** Straps with nothing on them. Drawn, never stated. */
const free = computed(() => Math.max(0, (bag.value?.slotCap ?? 0) - slots.value.length))

/**
 * Every strap in one list, full ones first, so the comb is a single flow and a
 * cell's position in it is its index. Two loops would have made the stagger
 * below lie the moment the first empty strap landed mid-row.
 */
const cells = computed<Array<Slot | null>>(() => [
  ...slots.value,
  ...Array.from({ length: free.value }, () => null),
])

/*
 * §13.2 -- the comb is tiled the way the map tiles, because the straps are
 * ground you are carrying.
 *
 * Flat-top hexes: a column step of three quarters of a width, a row step of a
 * full height, and every other column dropped half a height. The only thing
 * that differs from the map is that the count is not fixed -- the comb takes as
 * many columns as the panel gives it and then steps down, so a wide panel reads
 * as a seam running across it rather than a plaque centerd in it.
 *
 * The column count has to be measured rather than assumed: the stagger is a
 * property of the *column*, and CSS can only count children. With an odd number
 * of columns, `nth-child(even)` would put the same column up on one row and
 * down on the next, and the tiling would come apart.
 */
const COL_STEP = 0.75
const MIN_COLUMNS = 3

const combEl = ref<HTMLElement | null>(null)
const columns = ref(6)

function measure(el: HTMLElement): void {
  const style = getComputedStyle(el)
  const step = parseFloat(style.getPropertyValue('--slot-w')) * COL_STEP
  if (!step) return

  // A track is three quarters of a cell; the last cell overhangs its track by
  // the remaining quarter, and the padding is what reserves room for it.
  // clientWidth still carries that padding, so take it off before counting --
  // leaving it in buys a column the comb then hangs off the edge of.
  const inner = el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)
  const next = Math.max(MIN_COLUMNS, Math.floor(inner / step))

  if (next !== columns.value) columns.value = next
}

let observer: ResizeObserver | null = null

watch(combEl, (el) => {
  observer?.disconnect()
  observer = null
  if (!el) return

  observer = new ResizeObserver(() => measure(el))
  observer.observe(el)
  measure(el)
})

onBeforeUnmount(() => observer?.disconnect())

/** Odd columns hang half a hex lower, which is what makes the cells nest. */
const dropped = (index: number) => (index % columns.value) % 2 === 1

/**
 * Scale every icon by its own drawn bounds, so a strap is the same weight
 * whatever is on it -- and never by deforming one.
 *
 * The icons are authored in a 40x40 box and none of them fills it: a raw lump
 * lives in x 7..32, a stack of bars in y 7..29, a rarity frame in almost the
 * whole thing. Rendering the *box* therefore lands a different margin in every
 * cell -- measured on a real bag, 10.7px on one and 13.2px on the next.
 *
 * getBBox() reports the ink in user units. The viewBox is re-pointed at the
 * **square around it**, centerd, with its side the longer of the two: every
 * icon's longest axis then lands at exactly the same length, which is the
 * consistency the stretch was after, and its proportions survive. A shortbow is
 * three parts wide to four tall and used to be drawn wider than it is high.
 *
 * A square viewBox in a square box means the default xMidYMid meet fits exactly
 * -- there is no letterbox to leave. It reads the ink rather than the current
 * viewBox, so running it twice on the same element is a no-op.
 */
function fitToInk(root: HTMLElement): void {
  for (const svg of root.querySelectorAll('svg')) {
    const ink = svg.getBBox()
    if (!ink.width || !ink.height) continue

    const side = Math.max(ink.width, ink.height)

    svg.setAttribute(
      'viewBox',
      `${ink.x + ink.width / 2 - side / 2} ${ink.y + ink.height / 2 - side / 2} ${side} ${side}`,
    )
  }
}

const popEl = ref<HTMLElement | null>(null)

watch([combEl, cells, popEl], async () => {
  await nextTick()
  if (combEl.value) fitToInk(combEl.value)
  if (popEl.value) fitToInk(popEl.value)
}, { immediate: true })

const pickedId = ref<string | null>(null)

function open(slot: Slot): void {
  pickedId.value = slot.id
  dropping.value = false
}
const picked = computed(() => slots.value.find((s) => s.id === pickedId.value) ?? null)

/** Shutting the popup also forgets the half-finished throw inside it. */
function close(): void {
  pickedId.value = null
  dropping.value = false
}

const def = computed(() => (picked.value ? ITEM_BY_KEY[picked.value.key] : undefined))
const material = computed(() =>
  picked.value?.kind === 'material' ? MATERIALS[picked.value.key] : null,
)

/** What the thing is, in the catalog's own words. */
const description = computed(() =>
  material.value ? material.value.description : (def.value?.description ?? ''),
)

/**
 * §7.6 -- how deep a strap goes for the thing that was tapped.
 *
 * Off the server's own figure rather than a mirrored constant, because one of
 * the three is not a constant: an Alchemist's shelf is deeper than anybody
 * else's, and a client guessing a flat hundred would draw the wrong number of
 * straps under the right number of flasks.
 */
const stack = computed(() => {
  const b = bag.value
  if (!b || !picked.value) return 1

  return picked.value.kind === 'material' ? b.stackMaterial
    : picked.value.kind === 'potion' ? b.stackPotion
    : b.stackGear
})

/**
 * The straps this holding is on, drawn rather than described.
 *
 * §7.6 makes the comb the bag's whole visual idea -- a strap is a place, and
 * free space is something you see instead of subtract. The plate had been
 * falling back to prose the moment it had to say how a stack was distributed
 * ("130 carried on 3 straps"), which is the one screen in the game that should
 * never have to.
 */
const straps = computed(() =>
  picked.value && 'held' in picked.value ? across(picked.value.held, stack.value) : [],
)

/**
 * What is left in the last strap before this kind costs another one.
 *
 * Zero when the stack happens to end flush, which is the interesting case
 * rather than a rounding artefact: a stack of exactly fifty has filled its
 * strap, and the next unit wants a place that may not be there.
 */
const headroom = computed(() => {
  const held = picked.value && 'held' in picked.value ? picked.value.held : 0
  const over = held % stack.value

  return over === 0 ? 0 : stack.value - over
})

/**
 * §8.2 -- what the whole holding fetches, beside what one of them does.
 *
 * The per-unit price is the fact and the lot is the decision: nobody sells one
 * of anything, and the number that decides whether a strap is worth clearing
 * is what all of it comes to.
 */
const lot = computed(() => {
  const held = picked.value && 'held' in picked.value ? picked.value.held : 0

  return (material.value?.npcPrice ?? 0) * held
})

/**
 * §8 -- one item per slot, so a piece in the pack is never a question on its
 * own: it is a question about the one already on the belt. Everything below
 * exists to answer it here, at the tap, rather than sending a prospector to the
 * hero screen to hold two sets of numbers in their head.
 */
const pickedGear = computed(() => (picked.value?.kind === 'gear' ? picked.value : null))

/** What is worn in the slot this piece wants. Null when the slot is empty. */
const worn = computed<OwnedItem | null>(() => {
  const slot = pickedGear.value ? def.value?.slot : undefined
  if (!slot) return null

  return (
    game.equipment.find(
      (item) =>
        item.equipped &&
        item.id !== pickedGear.value?.item.id &&
        ITEM_BY_KEY[item.key]?.slot === slot,
    ) ?? null
  )
})

const wornDef = computed(() => (worn.value ? ITEM_BY_KEY[worn.value.key] : undefined))

/**
 * What the swap moves, and the one case where an upgrade buys nothing.
 *
 * Both come off `@/game/formulas`, because the prospector sheet asks the same
 * question of the spares filed behind a slot (§8.2). Two copies of a swap
 * comparison is two answers waiting to disagree.
 */
const changes = computed<SwapChange[]>(() =>
  pickedGear.value ? swapChanges(game.equipment, pickedGear.value.item, worn.value) : [],
)

const ceilingNote = computed<string>(() =>
  pickedGear.value ? swapCeilingNote(game.equipment, pickedGear.value.item, worn.value) : '',
)

async function drink(key: string): Promise<void> {
  await game.drink(key)
  if (!game.consumables[key]) close()
}

/**
 * A charge already armed on this potion's stat AND its action, so drinking
 * again reads as replacing it rather than adding to it.
 *
 * §8.5 -- matching on the stat alone was right when a buff applied everywhere.
 * Now it would call a Forest Draft armed while a Deepseam Draft is, because
 * both are yield: two different things you are better at, not one thing twice.
 */
const armedOn = (def: ItemDef) =>
  game.buffs.find((b) => b.stat === def.stat && b.scope === (def.scope ?? 'global')) ?? null

/**
 * §8.5 -- what is already waiting is the better draft, so this one would be
 * paid for and never felt. Said here rather than after the fact: the server
 * refuses it either way, and a button that opens a flask for nothing is worse
 * than one that explains why it will not.
 */
const outclassed = (def: ItemDef) => {
  const armed = armedOn(def)
  return armed !== null && armed.value >= (def.value ?? 0)
}

/** The same sentence the server would refuse with, said before the tap. */
const standingNote = (def: ItemDef) => {
  const armed = armedOn(def)
  if (!armed) return ''

  const held = ITEM_BY_KEY[armed.key]?.name ?? 'A draft'

  if (!outclassed(def)) {
    return `${held} is already waiting on the same work, and this one is stronger.`
  }

  return armed.key === def.key
    ? `A ${def.name} is already waiting on the same work. A second would not make it any stronger.`
    : `${held} is already waiting on the same work, and it is the stronger of the two.`
}

/**
 * §11.1 -- trashing a stack.
 *
 * **Trash rather than scrap**, and the two words are not interchangeable: §8.2
 * scraps a piece of gear and hands back a share of what went into it, and this
 * hands back nothing at all. A verb that promised salvage on the one action
 * that has none would be the more expensive lie.
 *
 * Two taps, always: the first opens the field, the second does it. There is no
 * salvage and no undo, so a single mis-tap must never be able to empty a stack.
 * The trader pays for this stuff -- this is for when there is no trader within
 * three hexes and the only thing worth having is the strap.
 *
 * A FIELD rather than a row of amounts. The old three buttons -- 1, 10, all --
 * were the three numbers somebody guessed a player would want, and every stack
 * that was not those three had to be trashed in instalments. The field is
 * pre-filled with the whole stack and selected, so the common case (the strap
 * is what you actually want) is still one tap, and any other number is typed
 * over the top of it.
 */
const dropping = ref(false)
const trashQty = ref(1)
const trashField = ref<HTMLInputElement | null>(null)

/**
 * What the field is currently worth, clamped to the whole holding.
 *
 * The holding rather than the strap, because the popup is about the *kind*: a
 * hundred and thirty wood is three straps and one decision, and a field that
 * stopped at fifty would make emptying it a thing done in instalments -- which
 * is exactly what §11.1 replaced the three amount buttons to avoid.
 */
const trashing = computed(() => {
  const held = picked.value && 'held' in picked.value ? picked.value.held : 1

  return Math.max(1, Math.min(Math.floor(trashQty.value || 0), held))
})

const trashValid = computed(
  () => Number.isFinite(trashQty.value) && trashQty.value === trashing.value,
)

async function startTrash(qty: number): Promise<void> {
  trashQty.value = qty
  dropping.value = true
  await nextTick()
  trashField.value?.focus()
  trashField.value?.select()
}

async function drop(key: MaterialKey, qty: number): Promise<void> {
  dropping.value = false
  await game.discardMaterial(key, qty)
  // The strap is empty now, and a popup about nothing is a popup about nothing.
  if (!game.inventory[key]) close()
}

async function equip(item: OwnedItem): Promise<void> {
  await game.equip(item.id)
  close()
}

async function scrap(item: OwnedItem): Promise<void> {
  await game.discard(item.id)
  close()
}

/**
 * §8.2 -- a stowed piece mends. It always could: the server has never asked
 * whether a piece was worn, and the only thing keeping a broken axe out of the
 * repair queue was that the button lived on the prospector sheet, where you can
 * only reach gear you are already wearing.
 */
async function mend(item: OwnedItem): Promise<void> {
  await game.repair(item.id)
  close()
}
</script>

<template>
  <div v-if="bag" class="page">
    <!-- Straps. Places rather than a quantity, so a comb -- and the empty ones
         are drawn exactly like the full ones, which is what makes room
         something you can see rather than subtract. A stack that outgrows a
         strap simply takes the next one along. -->
    <div ref="combEl" class="slots" :style="{ '--cols': columns }">
      <template v-for="(cell, i) in cells" :key="cell ? cell.id : `free-${i}`">
        <button
          v-if="cell"
          class="slot"
          :class="{ dropped: dropped(i) }"
          type="button"
          :title="cell.name"
          :aria-label="cell.name"
          @click="open(cell)"
        >
          <span class="hex">
            <span class="face"><SvgIcon :svg="cell.icon" :size="ICON" /></span>
          </span>
          <span v-if="cell.kind !== 'gear'" class="qty mono">{{ cell.qty }}</span>
        </button>

        <span v-else class="slot empty" :class="{ dropped: dropped(i) }" aria-hidden="true">
          <span class="hex"><span class="face" /></span>
        </span>
      </template>
    </div>

    <p class="tiny muted straps">
      {{ slots.length }} of {{ bag.slotCap }} straps used.
      <template v-if="!free"> Full — anything that needs a strap of its own will be turned away.</template>
      <template v-else-if="slots.length">
        A strap holds {{ bag.stackMaterial }} of a material or {{ bag.stackPotion }} of a draft;
        worn gear rides on your belt and costs nothing.
      </template>
      <template v-else> Work a hex on the map to bring something back.</template>
    </p>

    <p class="tiny muted footnote">
      Resources cannot be traded between players. There is no direct transfer, by
      design — it removes the laundering and arbitrage vector entirely.
    </p>

    <!-- What is on the strap you tapped, and the one or two things you can do
         with it. Teleported out of the panel: see the note at the top.

         Every kind is the same plate: a head that says what it is, a band that
         says how much of it there is and where it is sitting, a band of facts,
         the flavour, and the actions last. It used to be three paragraphs of
         grey prose for a material and a banded, chipped, structured plate for a
         piece of gear -- two designs one tap apart, and the one the bag is
         mostly made of was the poorer of the two. -->
    <Teleport to="body">
      <div v-if="picked" class="pop-wrap" role="dialog" :aria-label="picked.name">
        <div class="pop-scrim" @click="close" />
        <div class="pop plate">
          <div class="pop-inner">
            <!-- What it is. Identity only: how much there is belongs to the
                 band under it, where it can be read rather than parsed. -->
            <header ref="popEl" class="pop-head">
              <span class="hex big">
                <span class="face"><SvgIcon :svg="picked.icon" :size="46" /></span>
              </span>
              <div class="grow">
                <strong :class="picked.kind === 'material' ? '' : `rarity-${def?.rarity}`">
                  {{ picked.name }}
                </strong>
                <p class="tiny muted sub">
                  <template v-if="picked.kind === 'material' && material">
                    Tier {{ material.tier }}<template v-if="material.biome"> · <span class="place">{{ material.biome }}</span></template>
                  </template>
                  <template v-else-if="picked.kind === 'potion' && def">
                    {{ RARITY_LABEL[def.rarity] }} draft
                  </template>
                  <template v-else-if="def">
                    {{ def.slot ? SLOT_LABEL[def.slot] : '' }} · {{ RARITY_LABEL[def.rarity] }} · one strap
                  </template>
                </p>
              </div>
              <button class="pop-close" type="button" aria-label="Close" @click="close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </button>
            </header>

            <!--
              §7.6 -- the holding: how much, and the straps it is sitting on.

              Drawn rather than said, because the comb behind this plate is the
              same drawing and a screen should not describe in prose what it
              renders everywhere else. The line under it is the half a picture
              cannot carry: how much more fits before this kind costs another
              place.
            -->
            <section v-if="picked.kind !== 'gear'" class="band">
              <div class="hold">
                <span class="tally">
                  <span class="n">{{ picked.held }}</span>
                  <span class="eyebrow">{{ picked.kind === 'potion' ? 'on the shelf' : 'carried' }}</span>
                </span>
                <span class="comb-mini" :aria-label="`${straps.length} straps`">
                  <span
                    v-for="(on, i) in straps"
                    :key="i"
                    class="s"
                    :class="{ part: i === straps.length - 1 && headroom > 0 }"
                  ><i>{{ on }}</i></span>
                </span>
              </div>
              <p class="tiny muted room">
                <template v-if="headroom > 0">
                  <strong>{{ headroom }} more</strong> before it takes another strap.
                </template>
                <template v-else>Full — the next one takes another strap.</template>
              </p>
            </section>

            <!-- Material: what it is worth, per unit and for the lot. -->
            <section v-if="picked.kind === 'material' && material" class="band">
              <div class="rows">
                <div class="kv">
                  <span class="eyebrow">At a trader</span>
                  <span v-if="material.npcPrice > 0" class="v">
                    {{ material.npcPrice }}g each · {{ lot }}g the lot
                  </span>
                  <span v-else class="v none">Will not take it</span>
                </div>
                <div v-if="material.walletCap" class="kv">
                  <span class="eyebrow">Per wallet</span>
                  <span class="v">{{ material.walletCap }} max</span>
                </div>
              </div>
            </section>

            <!-- Potion: what it arms, and what spends it. -->
            <section v-else-if="picked.kind === 'potion' && def" class="band">
              <div class="rows">
                <div class="kv">
                  <span class="eyebrow">Arms</span>
                  <span class="v">{{ statLine(def.stat!, def.value ?? 0) }}</span>
                </div>
                <div class="kv">
                  <span class="eyebrow">{{ SCOPE_LABEL[def.scope ?? 'global'] }}</span>
                  <span class="v">spent by one {{ SCOPE_ACTION[def.scope ?? 'global'] }}</span>
                </div>
              </div>
              <!-- Two charges on one action are the same effect twice, so the
                   stronger is the one that counts. Which way round that falls
                   decides the button, so it is said in words first. -->
              <p
                v-if="armedOn(def)"
                class="tiny standing"
                :class="{ better: !outclassed(def) }"
              >{{ standingNote(def) }}</p>
            </section>

            <!-- Gear: the swap, §8 -- one item per slot, so a spare is never a
                 question on its own. -->
            <template v-else-if="picked.kind === 'gear' && def">
              <section class="band">
                <div v-if="worn && wornDef" class="swap">
                  <div class="side off">
                    <span class="eyebrow">Equipped</span>
                    <div class="side-head">
                      <strong class="tiny" :class="`rarity-${wornDef.rarity}`">{{ wornDef.name }}</strong>
                      <span class="tiny mono muted">{{ worn.durability }}/{{ worn.maxDurability || wornDef.maxDurability }}</span>
                    </div>
                    <StatChips :def="wornDef" :options="worn.options ?? []" />
                  </div>

                  <div class="side on">
                    <span class="eyebrow">Stowed</span>
                    <div class="side-head">
                      <strong class="tiny" :class="`rarity-${def.rarity}`">{{ def.name }}</strong>
                      <span class="tiny mono">{{ picked.item.durability }}/{{ def.maxDurability }}</span>
                    </div>
                    <StatChips :def="def" :options="picked.item.options ?? []" />
                  </div>

                  <div class="moves">
                    <span class="eyebrow">Net change</span>
                    <SwapMoves :changes="changes" />
                  </div>
                </div>

                <!-- Nothing in the slot, so there is no trade to draw: what the
                     piece is worth on its own is the whole answer. -->
                <div v-else class="rows">
                  <div class="kv">
                    <span class="eyebrow">Durability</span>
                    <span class="v">{{ picked.item.durability }}/{{ def.maxDurability }}</span>
                  </div>
                  <div class="kv">
                    <span class="eyebrow">Carries</span>
                    <StatChips :def="def" :options="picked.item.options ?? []" />
                  </div>
                </div>

                <p v-if="ceilingNote" class="tiny ceiling">{{ ceilingNote }}</p>
              </section>

              <!-- §8.2 -- what a mend would take, on the plate that offers one.
                   The bill is the decision, not a footnote to it. -->
              <section class="band">
                <RepairCost :item="picked.item" />
              </section>
            </template>

            <p v-if="description" class="band quiet tiny muted flavour">{{ description }}</p>

            <!--
              The actions, always last and always in the same place.

              They used to float wherever the prose above them ended, so the tap
              a plate was opened for sat at a different height on every kind of
              thing. A band pins them.
            -->
            <section class="band acts-band">
              <template v-if="picked.kind === 'material'">
                <form v-if="dropping" class="trash" @submit.prevent="drop(picked.key, trashing)">
                  <p class="tiny muted ask">
                    How many of {{ picked.held }}? Nothing comes back for it.
                  </p>
                  <div class="line">
                    <input
                      ref="trashField"
                      v-model.number="trashQty"
                      class="count"
                      type="number"
                      min="1"
                      :max="picked.held"
                      step="1"
                      inputmode="numeric"
                      aria-label="How many to trash"
                    />
                    <!-- The confirm says the number rather than "OK", so the
                         last thing read before an irreversible tap is what it
                         will do. -->
                    <button class="btn btn-sm btn-danger" type="submit" :disabled="game.busy || !trashValid">
                      Trash {{ trashing }}
                    </button>
                    <button class="btn btn-sm" type="button" @click="dropping = false">Cancel</button>
                  </div>
                </form>
                <div v-else class="acts">
                  <button
                    class="btn btn-sm btn-danger"
                    type="button"
                    :disabled="game.busy"
                    @click="startTrash(picked.held)"
                  >
                    Trash
                  </button>
                </div>
              </template>

              <div v-else-if="picked.kind === 'potion' && def" class="acts">
                <button
                  class="btn btn-sm grow"
                  type="button"
                  :disabled="game.busy || outclassed(def)"
                  :title="outclassed(def) ? 'Keep it — what you have waiting is better' : ''"
                  @click="drink(picked.key)"
                >
                  {{ armedOn(def) ? 'Replace the charge' : 'Drink' }}
                </button>
              </div>

              <div v-else-if="picked.kind === 'gear'" class="acts">
                <GearAction
                  action="equip"
                  label="Equip"
                  wide
                  class="grow"
                  :disabled="game.busy || picked.item.durability <= 0"
                  @click="equip(picked.item)"
                />
                <GearAction
                  action="repair"
                  label="Repair"
                  wide
                  :disabled="game.busy"
                  @click="mend(picked.item)"
                />
                <GearAction
                  action="scrap"
                  label="Scrap"
                  wide
                  :disabled="game.busy"
                  @click="scrap(picked.item)"
                />
              </div>
            </section>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.page {
  /* Sizing and scrolling belong to PanelOverlay. */
  padding: 0;
}

/*
 * The comb.
 *
 * Six columns of three-quarter width, with every other cell dropped half a
 * height -- the map's own tiling (§13.2), at HUD scale. Six is even, so the
 * up/down parity is the same on every row and the nesting survives the wrap
 * that a bag of fourteen straps needs.
 */
.slots {
  --slot-w: 56px;
  --slot-h: 48px;
  /* The inscribed square -- see the note on the icon box below. */
  --art: calc(var(--slot-h) * 0.72);
  --cols: 6;
  display: grid;
  /* §13.2's tiling: colStep = W * 0.75, rowStep = H. The count comes from the
     panel rather than from here -- see measure() -- so the comb fills the width
     it is given and then steps down. */
  grid-template-columns: repeat(var(--cols), calc(var(--slot-w) * 0.75));
  grid-auto-rows: var(--slot-h);
  justify-content: start;
  margin-top: 16px;
  /* Each cell overhangs its column to the right by the quarter width the
     nesting saves, and the dropped column overhangs the last row by half a
     height. Both are reserved rather than clipped. */
  padding: 0 calc(var(--slot-w) * 0.25) calc(var(--slot-h) / 2) 0;
}

.slot {
  position: relative;
  width: var(--slot-w);
  height: var(--slot-h);
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
}

/* Odd columns hang half a hex lower. Keyed off the column rather than the child
   index, because the two only agree when the count happens to be even. */
.slot.dropped {
  transform: translateY(calc(var(--slot-h) / 2));
}

/* The hairline hexagon from app.css: outer element is the border color, inner
   one is the fill. A clip-path eats an inset shadow's diagonals, so a drawn
   ring is the only version of this that keeps all six edges. */
.hex {
  display: block;
  width: 100%;
  height: 100%;
}

.face {
  display: grid;
  place-items: center;
  background: var(--ink-raised);
  transition: background 0.14s ease;
}

.slots :deep(.svg-icon) {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

/*
 * One square, the same on every strap, and the art is fitted to its own ink by
 * fitToInk() above -- so the box below is what the drawing actually becomes.
 *
 * The side is the largest square that sits inside a flat-top hexagon without
 * its corners crossing the slanted edges: 2WH / (2H + W), which is 35.4px at
 * 56x48 and is what the 0.72 is rounded down from. Past that the comb starts
 * shaving the corners off whatever it is carrying.
 */
.slots :deep(.svg-icon svg) {
  display: block;
  width: var(--art);
  height: var(--art);
}

/* Two readings of the same hover: the face lights behind the art, and the art
   itself lifts. The shape is what the eye is tracking, so the cell as a whole
   has to answer. */
.slot:hover:not(.empty) {
  filter: brightness(1.22);
}

.slot:hover:not(.empty) .face {
  background: #304036;
}

/* Free space: the same hexagon, empty. Solid fill, never an opacity --
   §13.2's rule about ghost shapes holds off the map too. */
.slot.empty {
  cursor: default;
}

.slot.empty .face {
  background: var(--ink);
}

/* Inside the hexagon, not hanging off it. A comb has no margins: anything
   drawn outside a cell is drawn on top of the cell next to it. */
.qty {
  position: absolute;
  left: 50%;
  bottom: 5px;
  transform: translateX(-50%);
  padding: 0 4px;
  font-size: 10px;
  font-weight: 700;
  line-height: 1.4;
  color: var(--vellum);
  background: rgba(8, 11, 10, 0.72);
  border-radius: 3px;
  pointer-events: none;
}

.straps {
  margin: 12px 0 0;
  line-height: 1.5;
  text-align: center;
}

.footnote {
  margin-top: 20px;
  line-height: 1.5;
  padding-top: 12px;
  border-top: 1px solid var(--line);
}

/* ------------------------------------------------------------------- popup */

.pop-wrap {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: grid;
  place-items: center;
  padding: 18px;
}

.pop-scrim {
  position: absolute;
  inset: 0;
  background: rgba(8, 11, 10, 0.55);
}

.pop {
  position: relative;
  width: min(330px, 100%);
}

/* The padding belongs to the bands, so each one can carry its own ground. */
.pop-inner {
  padding: 0;
}

/* ------------------------------------------------------------------- head */

.pop-head {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 12px 13px 11px;
}

.pop-head .hex.big {
  --art: calc(46px * 0.74);
  width: 53px;
  height: 46px;
  flex: 0 0 auto;
}

/* Same rule as the comb: a portrait hexagon that reads differently from the
   strap it was tapped on would be two systems. */
.pop-head :deep(.svg-icon) {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

.pop-head :deep(.svg-icon svg) {
  display: block;
  width: var(--art);
  height: var(--art);
}

.pop-head strong {
  font-size: 14.5px;
}

.sub {
  margin: 3px 0 0;
}

/* A biome key is stored lowercase and is a proper noun on screen. Capitalising
   the whole subtitle instead turned "Epic draft" into "Epic Draft". */
.place {
  text-transform: capitalize;
}

.pop-close {
  align-self: flex-start;
  padding: 0;
  border: 0;
  background: none;
  color: var(--vellum-dim);
  cursor: pointer;
}

.pop-close:hover {
  color: var(--vellum);
}

/* ------------------------------------------------------------------ bands */

/*
 * One band per question, in one order, whatever was tapped.
 *
 * The plate used to be shaped by whatever the kind happened to have: a piece of
 * gear got banded blocks with eyebrows and chips, and a material got three
 * paragraphs of grey prose and a button. Two designs one tap apart, and the
 * poorer of the two was the one the bag is mostly made of.
 */
.band {
  padding: 10px 13px;
  border-top: 1px solid var(--line);
  background: rgba(0, 0, 0, 0.22);
}

/* The flavour is the one thing that is not an answer, so it does not get a
   ground of its own -- it reads as the space before the actions. */
.band.quiet {
  background: none;
}

.eyebrow {
  font-size: 8.5px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--vellum-dim);
}

/*
 * §7.6 -- the holding, and the straps it is on.
 *
 * The count is the plate's headline because "how much have I got" is what a bag
 * is opened for; it used to be the smallest text on the plate, buried in a
 * subtitle between a tier and a strap count.
 */
.hold {
  display: flex;
  align-items: center;
  gap: 13px;
}

.tally {
  flex: 0 0 auto;
}

.tally .n {
  display: block;
  font-size: 25px;
  line-height: 1;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.tally .eyebrow {
  display: block;
  margin-top: 4px;
}

/*
 * The same comb, at plate scale. A stack that outgrows its strap takes the next
 * one along, and this is the one place a player can see how their own is laid
 * out -- which the screen behind it can only show mixed in with everything else.
 */
.comb-mini {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  flex: 1 1 auto;
}

.comb-mini .s {
  width: 34px;
  height: 30px;
  flex: 0 0 auto;
  clip-path: var(--hex-clip);
  background: var(--line);
  padding: 1px;
}

.comb-mini .s > i {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  clip-path: var(--hex-clip);
  background: var(--ink-raised);
  font-size: 10.5px;
  font-weight: 600;
  font-style: normal;
  font-variant-numeric: tabular-nums;
  color: var(--vellum-dim);
}

/* The one with room left in it is the only one worth picking out: it is where
   the next haul lands, and where the next strap starts. */
.comb-mini .s.part > i {
  background: #2b3830;
  color: var(--vellum);
}

.room {
  margin: 8px 0 0;
  line-height: 1.4;
}

/* -------------------------------------------------------------- fact rows */

/*
 * Label left, figure right. Facts were sentences -- "2g each at a trader ·
 * capped at 40 per wallet" -- which put the numbers a player actually scans for
 * inside prose, in the middle of a line, at two different indents.
 */
.rows {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.kv {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.kv .v {
  font-size: 12px;
  color: var(--vellum);
  font-variant-numeric: tabular-nums;
  text-align: right;
}

/* Not a figure, so it is not drawn like one. */
.kv .v.none {
  color: var(--vellum-dim);
}

.flavour {
  margin: 0;
  line-height: 1.5;
}

/* §8.1 rule 1 -- the qualification, not an alarm. Copper is what the dock
   already spends on "yes, but": work in progress, and a refusal with a reason. */
.ceiling {
  margin: 8px 0 0;
  line-height: 1.45;
  color: var(--copper);
}

/* Copper for the refusal, gold for the upgrade -- the same reading the dock
   gives work in progress and an opportunity. */
.standing {
  margin: 8px 0 0;
  line-height: 1.45;
  color: var(--copper);
}

.standing.better {
  color: var(--gold);
}

/* ---------------------------------------------------------------- the swap */

/*
 * The swap plate, §8 -- one item per slot.
 *
 * Two bands of ONE block rather than two panels side by side: at 330px a pair
 * of columns puts four words on a line and a name on three, and the thing being
 * compared is a handful of small numbers, which read better stacked than
 * scanned across a gutter. The order is the trade -- what is on, what would go
 * on, what moves -- so the plate ends on the reason to tap Equip.
 *
 * It carries no ground or cut of its own any more: the band it sits in has
 * both, and a clipped block inside a clipped band was two plates deep.
 */
.swap {
  display: flex;
  flex-direction: column;
}

.side {
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 8px 0;
}

/*
 * What you are giving up is drawn down, what you would be wearing is drawn up
 * -- the map's fog grammar (§5.6) indoors. The rarity color stays on both names
 * either way: which rung a piece is on is the fact the eye scans for, and
 * dimming it would cost more than the contrast buys.
 */
.side.off {
  padding-top: 0;
  color: var(--vellum-dim);
}

.side.off :deep(.chip) {
  background: #191f1c;
}

/*
 * The incoming piece is marked with a copper edge rather than a lighter fill:
 * the chips inside carry their own raised background, and lifting the band
 * behind them flattened the two into one shape. Copper is what the dock already
 * spends on the thing being proposed. The negative margin puts the rule against
 * the band's own edge rather than floating it inside the padding.
 */
.side.on {
  margin-left: -13px;
  padding-left: 11px;
  border-top: 1px solid var(--line);
  border-left: 2px solid var(--copper);
}

.side-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}

/* The answer the plate was opened for, so it sits under the rule on its own. */
.moves {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 8px;
  padding-top: 8px;
  border-top: 1px solid var(--line);
}

/* -------------------------------------------------------------- the actions */

.acts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.acts .btn {
  white-space: nowrap;
}

/* The one a plate is usually opened to press takes the room left over, so it
   is the widest target on the band. */
.acts :deep(.grow),
.acts .grow {
  flex: 1 1 auto;
}

/* §11.1 -- the trash row: what you are about to lose, how many, and the tap.
   The question sits on its own line so the field and the two buttons never get
   squeezed onto a phone; the field takes what is left of the row. */
.trash {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.trash .ask {
  margin: 0;
  line-height: 1.4;
}

.trash .line {
  display: flex;
  align-items: stretch;
  gap: 6px;
}

.trash .line .btn {
  flex: 0 0 auto;
}

.count {
  flex: 1 1 60px;
  min-width: 54px;
  padding: 7px 9px;
  border: 1px solid var(--line);
  background: var(--ink);
  color: var(--vellum);
  font: inherit;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.count:focus {
  outline: none;
  border-color: var(--ember);
}

/* The spinners are a second control for the one thing the field already does,
   and they are too small to hit on a phone. */
.count::-webkit-outer-spin-button,
.count::-webkit-inner-spin-button {
  margin: 0;
  appearance: none;
}
</style>
