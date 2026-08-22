<script setup lang="ts">
/**
 * One piece of equipment, wherever it is being shown.
 *
 * The prospector sheet says the same thing about a piece of gear in three
 * places -- on its line, on the body, and in the pack -- and said it three
 * times in markup. What differs between those places is which buttons hang off
 * the end, so that is the only thing left to the caller: the slot goes in the
 * default slot, the row draws the rest.
 *
 * §8.1 rule 3 -- durability is always on it. A piece at zero is broken and
 * inactive, never destroyed, and the row says so in words rather than leaving a
 * player to read an empty bar.
 */
import { computed } from 'vue'
import { ITEM_BY_KEY } from '@/game/catalog'
import { itemStatLine, optionStatLine } from '@/game/formulas'
import { itemIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { OwnedItem } from '@/game/types'

const props = defineProps<{ item: OwnedItem }>()

const def = computed(() => ITEM_BY_KEY[props.item.key]!)
const broken = computed(() => props.item.durability <= 0)
const percent = computed(
  () => (props.item.durability / (def.value.maxDurability ?? 1)) * 100,
)
</script>

<template>
  <SvgIcon
    :svg="itemIcon({ slot: def.slot, family: def.family, rarity: def.rarity, palette: def.palette, size: 30 })"
    boxed
    :size="30"
  />

  <div class="grow">
    <div class="row-between">
      <strong class="tiny" :class="`rarity-${def.rarity}`">{{ def.name }}</strong>
      <!-- §3.3 -- the only badge worth the width. Tradeable is not implied by
           rarity, and it is the one fact about a piece that is not on its face. -->
      <span v-if="def.tradeable" class="chip tiny chip-nft">NFT</span>
    </div>

    <!-- What it is for, on its own line rather than as a chip beside the name.
         At panel width the two competed and the chip wrapped, which turned every
         row into two. -->
    <div class="tiny muted stat">{{ itemStatLine(def) }}</div>

    <!-- §8.0.1 -- rolled lines. Listed under the base stat because that is what
         they are: extra, on top of what the item is for. -->
    <div v-if="item.options?.length" class="rolled">
      <span v-for="(option, i) in item.options" :key="i" class="tiny mono roll">
        {{ optionStatLine(option, def) }}
      </span>
    </div>

    <p v-if="broken" class="tiny broken">Broken — inactive until repaired, never destroyed.</p>
    <div v-else class="row wear">
      <div class="bar grow" :class="percent < 25 ? 'bar-ember' : ''">
        <span :style="{ width: `${percent}%` }" />
      </div>
      <span class="tiny mono muted">{{ item.durability }}/{{ def.maxDurability }}</span>
    </div>
  </div>

  <div class="row-actions">
    <slot />
  </div>
</template>

<style scoped>
.stat {
  margin-top: 2px;
}

.rolled {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 5px;
}

.roll {
  padding: 1px 6px;
  border-radius: var(--radius-sm);
  background: #1c2519;
  color: #b7d6a4;
}

.wear {
  gap: 7px;
  margin-top: 5px;
}

.broken {
  margin: 5px 0 0;
  color: var(--ember);
}

.row-actions {
  display: flex;
  flex: 0 0 auto;
  justify-content: flex-end;
  gap: 5px;
}
</style>
