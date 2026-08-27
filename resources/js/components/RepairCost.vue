<script setup lang="ts">
/**
 * §8.2 -- what a mend will cost, before it is pressed.
 *
 * The Repair button used to say nothing at all: you pressed it and either the
 * materials went quietly or the server answered "Repair needs 4 Wood" -- a
 * shopping list delivered as an error, after the decision. Repair is the
 * largest continuous sink in the game (§11.1), so what it takes is the whole
 * decision rather than a detail of it.
 *
 * Read off the PIECE's ceiling (§7.4.3), not the recipe's: a Smith's node moves
 * one and not the other, and a well-made piece is cheaper to keep per point.
 *
 * Shortfalls carry ember, which is §13.3's colour for a state to deal with --
 * and being short of a material is exactly that. Everything you already hold is
 * left quiet, because a list where every row shouts says nothing.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS } from '@/game/catalog'
import { repairCost } from '@/game/formulas'
import type { MaterialKey, OwnedItem } from '@/game/types'

const props = defineProps<{ item: OwnedItem }>()

const game = useGame()

const def = computed(() => ITEM_BY_KEY[props.item.key])

const ceiling = computed(
  () => props.item.maxDurability || (def.value?.maxDurability ?? 0),
)

const missing = computed(() => Math.max(0, ceiling.value - props.item.durability))

const rows = computed(() =>
  Object.entries(repairCost(def.value!, missing.value, ceiling.value)).map(([key, need]) => ({
    key: key as MaterialKey,
    name: MATERIALS[key as MaterialKey]?.name ?? key,
    need,
    have: game.held(key as MaterialKey),
  })),
)

/**
 * §3.2 -- basic gear has no recipe and the NPC mends it for coin instead, which
 * is a different bill and one only payable at a settlement. The gold figure is
 * the server's; naming the trader is the honest thing this side can say.
 */
const byCoin = computed(() => rows.value.length === 0 && missing.value > 0)

const short = computed(() => rows.value.some((r) => r.have < r.need))

defineExpose({ short })
</script>

<template>
  <p v-if="missing === 0" class="tiny muted cost">Nothing to mend.</p>

  <p v-else-if="byCoin" class="tiny muted cost">Mended by the trader, for coin.</p>

  <p v-else class="tiny cost">
    <span class="muted lead">Costs</span>
    <span
      v-for="row in rows"
      :key="row.key"
      class="mono part"
      :class="{ short: row.have < row.need }"
    >{{ row.need }} {{ row.name }}<span v-if="row.have < row.need" class="held">&nbsp;({{ row.have }})</span></span>
  </p>
</template>

<style scoped>
.cost {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 3px 7px;
  margin: 3px 0 0;
}

.lead {
  letter-spacing: 0.08em;
}

.part {
  color: var(--vellum-dim);
  white-space: nowrap;
}

/* §13.3 -- ember is a state to deal with, and being short is one. */
.part.short {
  color: var(--ember);
}

.held {
  opacity: 0.8;
}
</style>
