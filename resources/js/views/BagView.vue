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
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS, RARITY_LABEL, SLOT_LABEL, STAT_LABEL } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { MaterialKey, OwnedItem, StatKey } from '@/game/types'

const game = useGame()

/**
 * Generated at cell scale and then stretched to fill it -- see `.face svg`
 * below. The number is what the SVG is authored at; the hexagon is what it
 * ends up as.
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
      icon: itemIcon({ slot: def.slot, rarity: def.rarity, palette: def.palette, size: ICON }),
      item,
    })
  }

  return out
})

const bag = computed(() => game.bag)

/** Straps with nothing on them. Drawn, never stated. */
const free = computed(() => Math.max(0, (bag.value?.rowCap ?? 0) - slots.value.length))

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

/** A buff already running on this stat, so drinking again reads as a refresh. */
const runningOn = (stat: StatKey) => game.buffs.find((b) => b.stat === stat) ?? null

const minutesLeft = (expiresAt: number) =>
  Math.max(0, Math.ceil((expiresAt - game.now) / 60000))

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

    <!-- Kinds. Places rather than a quantity, so a comb of straps. -->
    <div class="slots">
      <button
        v-for="slot in slots"
        :key="slot.id"
        class="slot"
        type="button"
        :title="slot.name"
        :aria-label="slot.name"
        @click="open(slot)"
      >
        <span class="hex">
          <span class="face"><SvgIcon :svg="slot.icon" :size="ICON" /></span>
        </span>
        <span v-if="slot.kind !== 'gear'" class="qty mono">{{ slot.qty }}</span>
      </button>

      <!-- Free space, drawn. An empty strap is the same hexagon as a full one,
           which is what makes room something you can see rather than subtract. -->
      <span v-for="n in free" :key="`free-${n}`" class="slot empty" aria-hidden="true">
        <span class="hex"><span class="face" /></span>
      </span>
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
            <header class="pop-head">
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
                {{ formatPercent(def.value) }} {{ STAT_LABEL[def.stat] }} while it lasts
                <template v-if="runningOn(def.stat)">
                  · {{ minutesLeft(runningOn(def.stat)!.expiresAt) }} min left
                </template>
              </p>
              <div class="acts">
                <button class="btn btn-sm" type="button" :disabled="game.busy" @click="drink(picked.key)">
                  {{ runningOn(def.stat) ? 'Refresh' : 'Drink' }}
                </button>
              </div>
            </template>

            <!-- Gear: equipping is the tidiest way to free a strap, so it leads. -->
            <template v-else-if="picked.kind === 'gear' && def">
              <p class="tiny fact">
                {{ picked.item.durability }}/{{ def.maxDurability }} durability ·
                {{ formatPercent(def.value) }} {{ STAT_LABEL[def.stat] }}
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
  display: grid;
  grid-template-columns: repeat(6, calc(var(--slot-w) * 0.75));
  grid-auto-rows: var(--slot-h);
  justify-content: center;
  margin-top: 16px;
  /* The cells overhang their column to the right and the last row's dropped
     cells overhang the bottom. Both are reserved here rather than clipped. */
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

.slot:nth-child(even) {
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

/*
 * The art is the cell, not a stamp in the middle of it. Stretched to the
 * container and clipped by the same hexagon, so a full strap reads as a full
 * strap at a glance -- which is the whole reason the comb is drawn rather than
 * counted. The count then sits on top of it; overlapping the art is fine,
 * because the pill behind it carries its own contrast.
 */
.slots :deep(.svg-icon),
.slots :deep(.svg-icon svg) {
  display: block;
  width: 100%;
  height: 100%;
}

/* The art covers the face, so a background swap would never be seen on a full
   strap. Lift the whole cell instead -- the shape is what the eye is tracking --
   and keep the face swap for the empty ones, where there is nothing else. */
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
  width: 46px;
  height: 40px;
  flex: 0 0 auto;
}

/* Same rule as the comb: the art is the cell. */
.pop-head :deep(.svg-icon),
.pop-head :deep(.svg-icon svg) {
  display: block;
  width: 100%;
  height: 100%;
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

.acts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.acts .btn {
  white-space: nowrap;
}
</style>
