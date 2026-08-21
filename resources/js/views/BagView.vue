<script setup lang="ts">
/**
 * Inventory. Grouped by tier so the §4 ladder is visible at a glance, and so the
 * two structural facts about resources -- non-tradeable between players, and
 * decaying over cap -- have somewhere to be stated.
 */
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS, STAT_LABEL } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { Material, MaterialKey, MaterialTier, StatKey } from '@/game/types'

const game = useGame()

const TIER_NAME: Record<MaterialTier, string> = {
  0: 'Scrap',
  1: 'Raw',
  2: 'Refined',
  3: 'Rare',
  4: 'Raid',
}

const TIER_NOTE: Record<MaterialTier, string> = {
  0: 'What bare hands bring back. Sells for a copper, and feeds nothing.',
  1: 'Biome-locked. Decays while you are over your storage cap.',
  2: 'Processed at settlements. The backbone of crafting.',
  3: 'Contested ring only, and capped per wallet.',
  4: 'Dungeon-sourced. Gates the top equipment tier.',
}

interface Held {
  mat: Material
  qty: number
}

const byTier = computed(() => {
  const groups: Record<MaterialTier, Held[]> = { 0: [], 1: [], 2: [], 3: [], 4: [] }
  for (const [key, qty] of Object.entries(game.inventory)) {
    if (!qty) continue
    const mat = MATERIALS[key as MaterialKey] as Material
    groups[mat.tier].push({ mat, qty })
  }
  for (const tier of [0, 1, 2, 3, 4] as MaterialTier[]) {
    groups[tier].sort((a, b) => b.qty - a.qty)
  }
  return groups
})

const empty = computed(() => Object.values(game.inventory).every((n) => !n))

const tiers: MaterialTier[] = [0, 1, 2, 3, 4]

/**
 * §8.5 -- the shelf. Potions are not materials and not equipment, so they get
 * their own section rather than being wedged into either.
 */
const potions = computed(() =>
  Object.entries(game.consumables)
    .filter(([, qty]) => qty > 0)
    .map(([key, qty]) => ({ def: ITEM_BY_KEY[key]!, qty }))
    .filter((p) => p.def)
    .sort((a, b) => a.def.name.localeCompare(b.def.name)),
)

/** A buff already running on this stat, so drinking again reads as a refresh. */
const runningOn = (stat: StatKey) => game.buffs.find((b) => b.stat === stat) ?? null

const minutesLeft = (expiresAt: number) =>
  Math.max(0, Math.ceil((expiresAt - game.now) / 60000))

/**
 * §11.1 -- throwing things away.
 *
 * Two taps, always: the first opens the amounts, the second does it. There is no
 * salvage and no undo, so a single mis-tap must never be able to empty a stack.
 * The trader pays for this stuff — this is for when there is no trader within
 * three hexes and the only thing worth having is the room.
 */
const dropping = ref<MaterialKey | null>(null)

/** Amounts worth reaching for. Deduped, so a stack of 1 offers one button. */
function amounts(qty: number): number[] {
  return [1, 10, qty].filter((n, i, arr) => n > 0 && n <= qty && arr.indexOf(n) === i)
}

const label = (n: number, qty: number) => (n === qty && qty > 1 ? `All ${qty}` : `${n}`)

async function drop(key: MaterialKey, qty: number): Promise<void> {
  dropping.value = null
  await game.discardMaterial(key, qty)
}
</script>

<template>
  <div class="page">
    <div v-if="empty" class="inset empty">
      <h3>Nothing in the bag</h3>
      <p class="muted tiny">Work a hex on the map to bring something back.</p>
    </div>

    <template v-for="tier in tiers" :key="tier">
      <section v-if="byTier[tier].length" class="section">
        <div class="row-between" style="margin-bottom: 7px">
          <h3 class="head">Tier {{ tier }} · {{ TIER_NAME[tier] }}</h3>
          <span v-if="tier === 3" class="chip chip-nft tiny">capped</span>
        </div>
        <p class="tiny muted note">{{ TIER_NOTE[tier] }}</p>

        <div class="grid-auto">
          <div v-for="held in byTier[tier]" :key="held.mat.key" class="cell">
            <SvgIcon :svg="materialIcon(held.mat, 28)" boxed :size="28" />

            <!-- Second tap: how many. Replaces the row rather than sitting
                 beside it, so the amounts cannot be hit by accident. -->
            <div v-if="dropping === held.mat.key" class="grow">
              <div class="row-between" style="margin-bottom: 5px">
                <strong class="tiny">Throw away</strong>
                <button class="link tiny" type="button" @click="dropping = null">Cancel</button>
              </div>
              <div class="amounts">
                <button
                  v-for="n in amounts(held.qty)"
                  :key="n"
                  class="btn btn-sm btn-danger"
                  type="button"
                  :disabled="game.busy"
                  @click="drop(held.mat.key, n)"
                >
                  {{ label(n, held.qty) }}
                </button>
              </div>
            </div>

            <div v-else class="grow">
              <div class="row-between">
                <strong class="tiny">{{ held.mat.name }}</strong>
                <div class="row" style="gap: 6px">
                  <span class="mono qty">{{ held.qty }}</span>
                  <button
                    class="drop-btn"
                    type="button"
                    :disabled="game.busy"
                    :title="`Throw away ${held.mat.name}`"
                    :aria-label="`Throw away ${held.mat.name}`"
                    @click="dropping = held.mat.key"
                  >
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                      <path d="M5 7h14M10 7V5h4v2M8 7l1 12h6l1-12" />
                    </svg>
                  </button>
                </div>
              </div>
              <div
                v-if="held.mat.walletCap"
                class="bar"
                style="margin-top: 5px"
                :title="`Wallet cap ${held.mat.walletCap}`"
              >
                <span :style="{ width: `${(held.qty / held.mat.walletCap) * 100}%` }" />
              </div>
              <div v-else class="tiny muted">
                {{ held.mat.npcPrice > 0 ? `${held.mat.npcPrice}g each` : 'not sellable' }}
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>

    <!-- §8.5 -- the shelf. Not materials, not equipment: drunk, not worn. -->
    <section v-if="potions.length" class="section">
      <div class="row-between" style="margin-bottom: 7px">
        <h3 class="head">Consumables</h3>
        <span class="tiny muted">{{ game.buffs.length }} running</span>
      </div>
      <p class="tiny muted note">
        Drink one and it works for a while, then it does not. Drinking a second of
        the same kind restarts the clock rather than stacking.
      </p>

      <div v-for="potion in potions" :key="potion.def.key" class="cell potion">
        <SvgIcon
          :svg="itemIcon({ rarity: potion.def.rarity, palette: potion.def.palette, size: 28 })"
          boxed
          :size="28"
        />
        <div class="grow">
          <div class="row-between">
            <strong class="tiny" :class="`rarity-${potion.def.rarity}`">{{ potion.def.name }}</strong>
            <span class="mono qty">×{{ potion.qty }}</span>
          </div>
          <div class="tiny muted">
            {{ formatPercent(potion.def.value) }} {{ STAT_LABEL[potion.def.stat] }}
            <template v-if="runningOn(potion.def.stat)">
              · {{ minutesLeft(runningOn(potion.def.stat)!.expiresAt) }} min left
            </template>
          </div>
        </div>
        <button
          class="btn btn-sm"
          type="button"
          :disabled="game.busy"
          @click="game.drink(potion.def.key)"
        >
          {{ runningOn(potion.def.stat) ? 'Refresh' : 'Drink' }}
        </button>
      </div>
    </section>

    <p class="tiny muted footnote">
      Resources cannot be traded between players. There is no direct transfer, by
      design — it removes the laundering and arbitrage vector entirely.
    </p>
  </div>
</template>

<style scoped>
.page {
  /* Sizing and scrolling belong to PanelOverlay. */
  padding: 0;
}

.section + .section {
  margin-top: 18px;
}

.head {
  font-size: 14px;
}

.note {
  margin: 0 0 9px;
  line-height: 1.45;
}

.cell {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  background: var(--ink-panel);
  border: 1px solid var(--line);
}

.qty {
  font-size: 14px;
  font-weight: 700;
}

/* Potions get a full-width row rather than the material grid: they carry an
   action, and an action needs somewhere to sit. */
.potion + .potion {
  margin-top: 6px;
}

/* The cells are narrow, and "All 54" breaking across two lines reads as two
   buttons. Let the row wrap instead of the label. */
.amounts {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.amounts .btn {
  white-space: nowrap;
}

/* Quiet until wanted: throwing things away is never the thing you came here to
   do, so it does not compete with the quantity for attention. */
.drop-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  padding: 0;
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  background: var(--ink);
  color: #7b8580;
  cursor: pointer;
}

.drop-btn:hover:not(:disabled) {
  color: var(--ember);
  border-color: var(--ember);
}

.drop-btn:disabled {
  opacity: 0.4;
  cursor: default;
}

.link {
  border: 0;
  padding: 0;
  background: none;
  color: var(--vellum-dim);
  cursor: pointer;
  text-decoration: underline;
}

.link:hover {
  color: var(--vellum);
}

.empty {
  text-align: center;
  padding: 26px 16px;
}

.empty h3 {
  font-size: 15px;
  margin-bottom: 4px;
}

.footnote {
  margin-top: 20px;
  line-height: 1.5;
  padding-top: 12px;
  border-top: 1px solid var(--line);
}
</style>
