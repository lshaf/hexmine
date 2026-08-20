<script setup lang="ts">
/**
 * Crafting, §8.
 *
 * The tier ladder is the message: gold buys basic, materials buy crafted, and
 * only tier 3 + tier 4 reach the NFT tier. All three sit on the same capped
 * power curve (§8.1 rule 4) -- what changes is how fast you get there and what
 * it costs to keep running.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS, STAT_LABEL, STATION_RANK, craftableItems } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { EQUIPMENT } from '@/game/balance'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipTier, ItemDef, MaterialKey } from '@/game/types'

const game = useGame()

const TIER_ORDER: EquipTier[] = ['crafted', 'nft']

const TIER_LABEL: Record<EquipTier, string> = {
  basic: 'Basic',
  crafted: 'Crafted',
  nft: 'NFT tier',
}

const TIER_NOTE: Record<EquipTier, string> = {
  basic: 'Bought with gold.',
  crafted: 'Tier 1–2 materials. Beats anything gold can buy.',
  nft: 'Tier 3 + tier 4 only. Tradeable, and hard-capped at the same ceiling as everything else.',
}

const byTier = computed(() => {
  const groups = {} as Record<EquipTier, ItemDef[]>
  for (const tier of TIER_ORDER) groups[tier] = []
  for (const item of craftableItems()) groups[item.tier]?.push(item)
  return groups
})

const station = computed(() => game.currentSettlement)

function hasStation(item: ItemDef): boolean {
  if (!item.station) return true
  const here = station.value
  return Boolean(here && STATION_RANK[here.tier] >= STATION_RANK[item.station])
}

function inputs(item: ItemDef): Array<{ key: MaterialKey; need: number; have: number }> {
  return Object.entries(item.inputs ?? {}).map(([key, need]) => ({
    key: key as MaterialKey,
    need: need as number,
    have: game.held(key as MaterialKey),
  }))
}

const hasMaterials = (item: ItemDef) => inputs(item).every((i) => i.have >= i.need)

function blockedReason(item: ItemDef): string | null {
  if (!station.value) return 'Needs a workbench. Travel to a settlement.'
  if (!hasStation(item)) return `${station.value.name} is only a ${station.value.tier}. Needs a ${item.station}.`
  if (!hasMaterials(item)) return 'Missing materials.'
  return null
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

    <section v-for="tier in TIER_ORDER" :key="tier" class="section">
      <div class="row" style="gap: 8px; margin-bottom: 3px">
        <h3 class="head">{{ TIER_LABEL[tier] }}</h3>
        <span v-if="tier === 'nft'" class="chip chip-nft tiny">tradeable</span>
      </div>
      <p class="tiny muted note">{{ TIER_NOTE[tier] }}</p>

      <div v-for="item in byTier[tier]" :key="item.key" class="recipe panel">
        <div class="row" style="align-items: flex-start">
          <SvgIcon
            :svg="itemIcon({ slot: item.slot, tier: item.tier, palette: item.palette, size: 34 })"
            boxed
            :size="34"
          />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny">{{ item.name }}</strong>
              <span class="chip tiny" :class="tier === 'nft' ? 'chip-nft' : ''">
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
          <span class="tiny muted">
            {{ blockedReason(item) ?? `${item.maxDurability} durability` }}
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
