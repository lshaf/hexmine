<script setup lang="ts">
/**
 * Inventory. Grouped by tier so the §4 ladder is visible at a glance, and so the
 * two structural facts about resources -- non-tradeable between players, and
 * decaying over cap -- have somewhere to be stated.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS } from '@/game/catalog'
import { materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { Material, MaterialKey, MaterialTier } from '@/game/types'

const game = useGame()

const TIER_NAME: Record<MaterialTier, string> = {
  1: 'Raw',
  2: 'Refined',
  3: 'Rare',
  4: 'Raid',
}

const TIER_NOTE: Record<MaterialTier, string> = {
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
  const groups: Record<MaterialTier, Held[]> = { 1: [], 2: [], 3: [], 4: [] }
  for (const [key, qty] of Object.entries(game.inventory)) {
    if (!qty) continue
    const mat = MATERIALS[key as MaterialKey] as Material
    groups[mat.tier].push({ mat, qty })
  }
  for (const tier of [1, 2, 3, 4] as MaterialTier[]) {
    groups[tier].sort((a, b) => b.qty - a.qty)
  }
  return groups
})

const empty = computed(() => Object.values(game.inventory).every((n) => !n))

const tiers: MaterialTier[] = [1, 2, 3, 4]
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
            <div class="grow">
              <div class="row-between">
                <strong class="tiny">{{ held.mat.name }}</strong>
                <span class="mono qty">{{ held.qty }}</span>
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
