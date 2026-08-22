<script setup lang="ts">
/**
 * Crafting, §8.
 *
 * The tier ladder is the message: gold buys basic, materials buy crafted, and
 * only tier 3 + tier 4 reach the NFT tier. All three sit on the same capped
 * power curve (§8.1 rule 4) -- what changes is how fast you get there and what
 * it costs to keep running.
 */
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import {
  CATEGORIES,
  CATEGORY_LABEL,
  MATERIALS,
  RARITIES,
  RARITY_LABEL,
  STAT_LABEL,
  STATION_RANK,
  categoryForSlot,
  craftableItems,
  stationReaches,
} from '@/game/catalog'
import type { Category } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { EQUIPMENT } from '@/game/balance'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { ItemDef, MaterialKey, Rarity } from '@/game/types'

const game = useGame()

/** §8.1 -- what each rung of the ladder is, in one line each. */
const RARITY_NOTE: Record<Rarity, string> = {
  common: 'Tier 2 materials, no gold. Outlasts anything the trader sells.',
  uncommon: 'Tier 2 materials. Where a line starts paying for itself.',
  rare: 'Tier 1–2 materials, and the best a village workshop will ever manage.',
  epic: 'Tier 3 + tier 4 only. Tradeable, and capped at the same ceiling as everything else.',
  legendary: 'Guild halls only, once guilds exist.',
  unique: 'Never crafted. Dungeon drops only, and soulbound when it lands.',
}

/**
 * Only what this workbench can actually make.
 *
 * A recipe you cannot reach here is hidden outright rather than greyed: a
 * village will never craft an epic, so listing it is a permanent row of noise on
 * the screen a player uses most. Missing *materials* is a different thing and
 * stays visible — that is the shopping list, and hiding it would remove the only
 * reason to go and gather.
 */
/**
 * §8.4 -- three benches, and within each one the rarity ladder. Category first
 * because it is what a player is shopping *for*; rarity second because it is how
 * far up they can reach today.
 */
const tab = ref<Category>('weapon')

const reachable = computed(() => craftableItems().filter(hasStation))

const byCategory = computed(() => {
  const groups = {} as Record<Category, ItemDef[]>
  for (const category of CATEGORIES) groups[category] = []
  for (const item of reachable.value) groups[categoryForSlot(item.slot)].push(item)
  return groups
})

const byRarity = computed(() => {
  const groups = {} as Record<Rarity, ItemDef[]>
  for (const rarity of RARITIES) groups[rarity] = []
  for (const item of byCategory.value[tab.value]) groups[item.rarity]?.push(item)
  return groups
})

const craftableRarities = computed(() =>
  RARITIES.filter((r) => byRarity.value[r].length > 0),
)

const nothingHere = computed(() => reachable.value.length === 0)

const station = computed(() => game.currentSettlement)

/**
 * §8.0 -- can this bench make it? Rarity decides, not the item's own `station`
 * field, so the screen and the server refuse for the same reason.
 */
function hasStation(item: ItemDef): boolean {
  const here = station.value
  if (!here) return false
  if (!stationReaches(here.tier, item.rarity)) return false
  // §8.0 -- the guild hall does not exist, so nothing that needs one is
  // craftable from any settlement a player can be standing in.
  if (item.station === 'guild') return false

  return !item.station || STATION_RANK[here.tier] >= STATION_RANK[item.station]
}

function inputs(item: ItemDef): Array<{ key: MaterialKey; need: number; have: number }> {
  return Object.entries(item.inputs ?? {}).map(([key, need]) => ({
    key: key as MaterialKey,
    need: need as number,
    have: game.held(key as MaterialKey),
  }))
}

const hasMaterials = (item: ItemDef) => inputs(item).every((i) => i.have >= i.need)

/** Anything station-blocked is already hidden, so this only ever reports stock. */
function blockedReason(item: ItemDef): string | null {
  return hasMaterials(item) ? null : 'Missing materials.'
}

/**
 * What the thing costs you over time. Gear wears out; a potion runs down. Both
 * are the §11.1 sink, so both belong in the same spot on the row.
 */
function lifespan(item: ItemDef): string {
  if (item.consumable) {
    return `${Math.round(EQUIPMENT.buffMs / 60000)} minutes, then gone`
  }
  return `${item.maxDurability} durability`
}
</script>

<template>
  <div class="page">
    <div class="inset where">
      <div class="row-between">
        <div>
          <span class="label">Workbench</span>
          <div class="tiny" style="margin-top: 3px">
            <template v-if="station">
              {{ station.name }} <span class="muted">· {{ station.tier }}</span>
            </template>
            <span v-else class="muted">You have left the settlement.</span>
          </div>
        </div>
        <span class="chip">falloff ×{{ EQUIPMENT.stackFalloff }}</span>
      </div>
    </div>

    <!-- §8.4 -- the three benches. Counts sit on the tabs so an empty one is
         obvious before you open it. -->
    <div v-if="!nothingHere" class="switch">
      <button
        v-for="category in CATEGORIES"
        :key="category"
        type="button"
        :class="{ on: tab === category }"
        @click="tab = category"
      >
        {{ CATEGORY_LABEL[category] }}
        <span class="tiny muted">{{ byCategory[category].length }}</span>
      </button>
    </div>

    <!-- Nothing reachable here. Say where to go, not just that there is nothing. -->
    <div v-if="nothingHere" class="inset empty">
      <p class="tiny muted" style="margin: 0">
        <template v-if="station">
          A {{ station.tier }} workbench cannot make any of these. Bigger
          settlements carry deeper benches.
        </template>
        <template v-else>
          Crafting needs a workbench. Travel to a settlement and try again.
        </template>
      </p>
    </div>

    <!-- A bench this settlement can reach, but nothing in this category on it. -->
    <div v-if="!nothingHere && !craftableRarities.length" class="inset empty">
      <p class="tiny muted" style="margin: 0">
        <template v-if="tab === 'consumable'">
          Potions and buffs are not built yet. This bench will make them.
        </template>
        <template v-else>Nothing in this category is made at a {{ station?.tier }}.</template>
      </p>
    </div>

    <section v-for="rarity in craftableRarities" :key="rarity" class="section">
      <div class="row" style="gap: 8px; margin-bottom: 3px">
        <h3 class="head" :class="`rarity-${rarity}`">{{ RARITY_LABEL[rarity] }}</h3>
        <span v-if="byRarity[rarity][0]?.tradeable" class="chip chip-nft tiny">tradeable</span>
      </div>
      <p class="tiny muted note">{{ RARITY_NOTE[rarity] }}</p>

      <div v-for="item in byRarity[rarity]" :key="item.key" class="recipe panel">
        <div class="row" style="align-items: flex-start">
          <SvgIcon
            :svg="itemIcon({ slot: item.slot, rarity: item.rarity, palette: item.palette, size: 34 })"
            boxed
            :size="34"
          />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny" :class="`rarity-${item.rarity}`">{{ item.name }}</strong>
              <span class="chip tiny" :class="item.tradeable ? 'chip-nft' : ''">
                {{ formatPercent(item.value) }} {{ STAT_LABEL[item.stat] }}
              </span>
            </div>
            <div class="tiny muted">{{ item.description }}</div>
          </div>
        </div>

        <div class="inputs">
          <div
            v-for="input in inputs(item)"
            :key="input.key"
            class="input"
            :class="{ short: input.have < input.need }"
          >
            <SvgIcon :svg="materialIcon(MATERIALS[input.key], 18)" />
            <span class="tiny mono">{{ input.have }}/{{ input.need }}</span>
            <span class="tiny muted name">{{ MATERIALS[input.key].name }}</span>
          </div>
        </div>

        <div class="row-between foot">
          <!-- A potion has no durability to quote; what it has is a clock. -->
          <span class="tiny muted">
            {{ blockedReason(item) ?? lifespan(item) }}
          </span>
          <button
            class="btn btn-sm"
            :class="{ 'btn-primary': !blockedReason(item) }"
            type="button"
            :disabled="game.busy || Boolean(blockedReason(item))"
            @click="game.craft(item.key)"
          >
            Craft
          </button>
        </div>
      </div>
    </section>

    <p class="tiny muted footnote">
      Rarity changes durability and reliability, not the power ceiling. A maxed
      crafted setup and a maxed NFT setup land on the same curve — which is what
      keeps free play viable.
    </p>
  </div>
</template>

<style scoped>
.page {
  /* Sizing and scrolling belong to PanelOverlay. */
  padding: 0;
}

.where {
  margin-bottom: 16px;
}

.empty {
  padding: 20px 16px;
  text-align: center;
  line-height: 1.5;
}

/* Three benches. Labels wrap on narrow phones rather than truncating, because
   "Tools & weapons" losing its tail reads as a different category. */
.switch {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  margin-bottom: 16px;
}

.switch button {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 8px 4px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font-weight: 700;
  font-size: 10.5px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  line-height: 1.25;
  cursor: pointer;
}

.switch button.on {
  background: var(--ink-raised);
  border-color: var(--copper);
  color: var(--vellum);
}

.section + .section {
  margin-top: 20px;
}

.head {
  font-size: 14px;
}

.note {
  margin: 0 0 9px;
  line-height: 1.45;
}

.recipe {
  padding: 11px 12px;
}

.recipe + .recipe {
  margin-top: 8px;
}

.inputs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 10px 0 9px;
}

.input {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 4px 8px 4px 5px;
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
}

.input.short {
  border-color: #6d3330;
}

.input.short .mono {
  color: #e58c86;
}

.input .name {
  max-width: 92px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.foot {
  padding-top: 9px;
  border-top: 1px solid var(--line);
}

.footnote {
  margin-top: 22px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  line-height: 1.5;
}
</style>
