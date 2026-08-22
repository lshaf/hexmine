<script setup lang="ts">
/**
 * The bag, §7.6.
 *
 * The screen is shaped like the two limits, because the two limits are what the
 * screen is about:
 *
 *  - **Kinds** are drawn, not counted. Every strap is a hexagon in a honeycomb
 *    -- the same nesting the map uses -- and the empty ones are drawn exactly
 *    like the full ones, so free space is a thing you can see rather than a
 *    number you have to subtract. A full bag turns away a kind it is not
 *    already carrying, so knowing at a glance how many straps are left is most
 *    of the decision.
 *  - **Amount** is a bar, because it is a quantity and not a set of places. It
 *    is also the one that can go over: a haul lands whole, and being too heavy
 *    stops the road rather than the work.
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
import { itemStatLine, statLine } from '@/game/formulas'
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
 */
type Slot =
  | { id: string; kind: 'material'; key: MaterialKey; name: string; icon: string; qty: number }
  | { id: string; kind: 'potion'; key: string; name: string; icon: string; qty: number }
  | { id: string; kind: 'gear'; key: string; name: string; icon: string; item: OwnedItem }

const slots = computed<Slot[]>(() => {
  const out: Slot[] = []

  const held = Object.entries(game.inventory)
    .filter(([, qty]) => qty)
    .map(([key, qty]) => ({ mat: MATERIALS[key as MaterialKey], qty: qty as number }))
    // Tier first, then the big stacks: the ladder is still the order things
    // make sense in, even without headings to say so.
    .sort((a, b) => a.mat.tier - b.mat.tier || b.qty - a.qty)

  for (const { mat, qty } of held) {
    out.push({
      id: `m:${mat.key}`,
      kind: 'material',
      key: mat.key,
      name: mat.name,
      icon: materialIcon(mat, ICON),
      qty,
    })
  }

  for (const [key, qty] of Object.entries(game.consumables)) {
    const def = ITEM_BY_KEY[key]
    if (!qty || !def) continue
    out.push({
      id: `p:${key}`,
      kind: 'potion',
      key,
      name: def.name,
      icon: itemIcon({ rarity: def.rarity, palette: def.palette, size: ICON }),
      qty,
    })
  }

  // §7.6 -- worn gear is not carried, so only what is off the belt takes a strap.
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
      item,
    })
  }

  return out
})

const bag = computed(() => game.bag)

/** Straps with nothing on them. Drawn, never stated. */
const free = computed(() => Math.max(0, (bag.value?.rowCap ?? 0) - slots.value.length))

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
 * as a seam running across it rather than a plaque centred in it.
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
 * **square around it**, centred, with its side the longer of the two: every
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

const overUnits = computed(() =>
  bag.value ? bag.value.units > bag.value.unitCap : false,
)

const fill = computed(() => {
  const b = bag.value
  if (!b) return '0%'
  return `${Math.min(100, Math.max(0, (b.units / b.unitCap) * 100))}%`
})

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

async function drink(key: string): Promise<void> {
  await game.drink(key)
  if (!game.consumables[key]) close()
}

/**
 * A charge already armed on this potion's stat AND its action, so drinking
 * again reads as replacing it rather than adding to it.
 *
 * §8.5 -- matching on the stat alone was right when a buff applied everywhere.
 * Now it would call a Forest Draught armed while a Deepseam Draught is, because
 * both are yield: two different things you are better at, not one thing twice.
 */
const armedOn = (def: ItemDef) =>
  game.buffs.find((b) => b.stat === def.stat && b.scope === (def.scope ?? 'global')) ?? null

/**
 * §8.5 -- what is already waiting is the better draught, so this one would be
 * paid for and never felt. Said here rather than after the fact: the server
 * refuses it either way, and a button that opens a flask for nothing is worse
 * than one that explains why it will not.
 */
const outclassed = (def: ItemDef) => {
  const armed = armedOn(def)
  return armed !== null && armed.value >= def.value
}

/** The same sentence the server would refuse with, said before the tap. */
const standingNote = (def: ItemDef) => {
  const armed = armedOn(def)
  if (!armed) return ''

  const held = ITEM_BY_KEY[armed.key]?.name ?? 'A draught'

  if (!outclassed(def)) {
    return `${held} is already waiting on the same work, and this one is stronger.`
  }

  return armed.key === def.key
    ? `A ${def.name} is already waiting on the same work. A second would not make it any stronger.`
    : `${held} is already waiting on the same work, and it is the stronger of the two.`
}

/**
 * §11.1 -- throwing things away.
 *
 * Two taps, always: the first opens the amounts, the second does it. There is
 * no salvage and no undo, so a single mis-tap must never be able to empty a
 * stack. The trader pays for this stuff -- this is for when there is no trader
 * within three hexes and the only thing worth having is the strap.
 */
const dropping = ref(false)

/** Amounts worth reaching for. Deduped, so a stack of 1 offers one button. */
function amounts(qty: number): number[] {
  return [1, 10, qty].filter((n, i, arr) => n > 0 && n <= qty && arr.indexOf(n) === i)
}

const label = (n: number, qty: number) => (n === qty && qty > 1 ? `All ${qty}` : `${n}`)

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
</script>

<template>
  <div v-if="bag" class="page">
    <!-- Amount. A quantity, so a bar -- and the one limit that can be passed. -->
    <section class="amount" :class="{ over: overUnits }">
      <div class="row-between" style="margin-bottom: 6px">
        <span class="label">Amount</span>
        <span class="mono read" :class="{ over: overUnits }">
          {{ bag.units }}<span class="of">/{{ bag.unitCap }}</span>
        </span>
      </div>
      <div class="bar bar-violet"><span :style="{ width: fill }" /></div>
      <p v-if="overUnits" class="tiny warn">
        Too heavy to set off. Sell, process or throw something away — all three
        work from where you are standing.
      </p>
    </section>

    <!-- Kinds. Places rather than a quantity, so a comb of straps -- and the
         empty ones are drawn exactly like the full ones, which is what makes
         room something you can see rather than subtract. -->
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
      {{ slots.length }} of {{ bag.rowCap }} straps used.
      <template v-if="!free"> Full — a kind you are not already carrying will be turned away.</template>
      <template v-else-if="slots.length"> Worn gear rides on your belt and costs nothing.</template>
      <template v-else> Work a hex on the map to bring something back.</template>
    </p>

    <p class="tiny muted footnote">
      Resources cannot be traded between players. There is no direct transfer, by
      design — it removes the laundering and arbitrage vector entirely.
    </p>

    <!-- What is on the strap you tapped, and the one or two things you can do
         with it. Teleported out of the panel: see the note at the top. -->
    <Teleport to="body">
      <div v-if="picked" class="pop-wrap" role="dialog" :aria-label="picked.name">
        <div class="pop-scrim" @click="close" />
        <div class="pop plate">
          <div class="pop-inner">
            <header ref="popEl" class="pop-head">
              <span class="hex big">
                <span class="face"><SvgIcon :svg="picked.icon" :size="40" /></span>
              </span>
              <div class="grow">
                <strong :class="picked.kind === 'material' ? '' : `rarity-${def?.rarity}`">
                  {{ picked.name }}
                </strong>
                <p class="tiny muted sub">
                  <template v-if="picked.kind === 'material' && material">
                    Tier {{ material.tier }} · {{ picked.qty }} carried
                  </template>
                  <template v-else-if="picked.kind === 'potion' && def">
                    {{ RARITY_LABEL[def.rarity] }} draught · {{ picked.qty }} on the shelf
                  </template>
                  <template v-else-if="def">
                    {{ def.slot ? SLOT_LABEL[def.slot] : '' }} · {{ RARITY_LABEL[def.rarity] }}
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

            <p v-if="description" class="tiny muted flavour">{{ description }}</p>

            <!-- Material: what it is worth, and the way out that works anywhere. -->
            <template v-if="picked.kind === 'material' && material">
              <p class="tiny fact">
                {{ material.npcPrice > 0 ? `${material.npcPrice}g each at a trader` : 'The trader will not take it' }}
                <template v-if="material.walletCap"> · capped at {{ material.walletCap }} per wallet</template>
              </p>
              <div v-if="dropping" class="acts">
                <button
                  v-for="n in amounts(picked.qty)"
                  :key="n"
                  class="btn btn-sm btn-danger"
                  type="button"
                  :disabled="game.busy"
                  @click="drop(picked.key, n)"
                >
                  Throw {{ label(n, picked.qty) }}
                </button>
                <button class="btn btn-sm" type="button" @click="dropping = false">Cancel</button>
              </div>
              <div v-else class="acts">
                <button class="btn btn-sm btn-danger" type="button" :disabled="game.busy" @click="dropping = true">
                  Throw away
                </button>
              </div>
            </template>

            <!-- Potion: drinking it is both the use and the way to free a strap. -->
            <template v-else-if="picked.kind === 'potion' && def">
              <p class="tiny fact">
                {{ statLine(def.stat, def.value) }}
                <strong>{{ SCOPE_LABEL[def.scope ?? 'global'] }}</strong>
                · spent by one {{ SCOPE_ACTION[def.scope ?? 'global'] }}
              </p>
              <!-- Two charges on one action are the same effect twice, so the
                   stronger is the one that counts. Which way round that falls
                   decides the button, so it is said in words first. -->
              <p
                v-if="armedOn(def)"
                class="tiny standing"
                :class="{ better: !outclassed(def) }"
              >{{ standingNote(def) }}</p>
              <div class="acts">
                <button
                  class="btn btn-sm"
                  type="button"
                  :disabled="game.busy || outclassed(def)"
                  :title="outclassed(def) ? 'Keep it — what you have waiting is better' : ''"
                  @click="drink(picked.key)"
                >
                  {{ armedOn(def) ? 'Replace the charge' : 'Drink' }}
                </button>
              </div>
            </template>

            <!-- Gear: equipping is the tidiest way to free a strap, so it leads. -->
            <template v-else-if="picked.kind === 'gear' && def">
              <p class="tiny fact">
                {{ picked.item.durability }}/{{ def.maxDurability }} durability ·
                {{ itemStatLine(def) }}
              </p>
              <div class="acts">
                <button
                  class="btn btn-sm"
                  type="button"
                  :disabled="game.busy || picked.item.durability <= 0"
                  :title="picked.item.durability <= 0 ? 'Broken — repair it first' : 'Worn gear costs no strap'"
                  @click="equip(picked.item)"
                >
                  Equip
                </button>
                <button class="btn btn-sm btn-danger" type="button" :disabled="game.busy" @click="scrap(picked.item)">
                  Scrap for parts
                </button>
              </div>
            </template>
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

.amount {
  padding: 10px 11px 11px;
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  background: var(--ink-panel);
}

.amount.over {
  border-color: var(--ember);
}

.read .of {
  color: var(--vellum-dim);
}

.read.over {
  color: var(--ember);
}

.amount.over .bar > span {
  background: var(--ember);
}

.warn {
  margin: 8px 0 0;
  line-height: 1.45;
  color: var(--ember);
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

/* The hairline hexagon from app.css: outer element is the border colour, inner
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
  width: min(320px, 100%);
}

.pop-inner {
  padding: 13px 14px 14px;
}

.pop-head {
  display: flex;
  align-items: center;
  gap: 11px;
}

.pop-head .hex.big {
  --art: calc(40px * 0.72);
  width: 46px;
  height: 40px;
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
  font-size: 14px;
}

.sub {
  margin: 2px 0 0;
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

.flavour {
  margin: 11px 0 0;
  line-height: 1.5;
}

.fact {
  margin: 9px 0 11px;
  line-height: 1.45;
  color: var(--vellum-dim);
}

/* Copper for the refusal, gold for the upgrade -- the same reading the dock
   gives work in progress and an opportunity. */
.standing {
  margin: -4px 0 11px;
  line-height: 1.45;
  color: var(--copper);
}

.standing.better {
  color: var(--gold);
}

.acts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.acts .btn {
  white-space: nowrap;
}
</style>
