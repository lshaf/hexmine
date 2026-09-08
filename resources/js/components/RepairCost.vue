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
 *
 * **Standing at a settlement it says the bill twice**, because there are two
 * ways to pay it: the parts out of the bag, or as many of them as the counter
 * stocks bought for coin (Balance::REPAIR_COIN_TIER). Two lines rather than one
 * toggled line, because the choice is between two bills and a player comparing
 * them should not have to press something to see the other one.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS } from '@/game/catalog'
import { repairBill, repairCoinTierAt } from '@/game/formulas'
import type { MaterialKey, OwnedItem } from '@/game/types'

const props = defineProps<{ item: OwnedItem }>()

const game = useGame()

const def = computed(() => ITEM_BY_KEY[props.item.key])

/**
 * §8.2 -- the same derivation the button beside this reads, so the price on the
 * plate and the price on the button cannot disagree.
 */
const bill = computed(() =>
  repairBill(def.value, props.item, repairCoinTierAt(game.currentSettlement?.tier)),
)

const row = (key: string, need: number) => ({
  key: key as MaterialKey,
  name: MATERIALS[key as MaterialKey]?.name ?? key,
  need,
  have: game.held(key as MaterialKey),
})

const rows = computed(() => Object.entries(bill.value.cost).map(([k, n]) => row(k, n)))

/** And what you still have to have brought, which is the whole of the catch. */
const keep = computed(() => Object.entries(bill.value.keep).map(([k, n]) => row(k, n)))

const gold = computed(() => bill.value.gold)

/**
 * §3.2 -- basic gear has no recipe and the NPC mends it for coin instead, which
 * is a different bill and one only payable at a settlement. The gold figure is
 * the server's; naming the trader is the honest thing this side can say.
 */
const byCoin = computed(() => rows.value.length === 0 && bill.value.missing > 0)

const missing = computed(() => bill.value.missing)

const short = computed(() => rows.value.some((r) => r.have < r.need))

/**
 * Whether the coin line is worth drawing at all. Nothing is offered out in the
 * field, and nothing is offered on a bill the counter stocks no part of --
 * which is what a mend made entirely of capped rares comes to.
 */
const coinOffered = computed(() => !byCoin.value && bill.value.coinOffered)

defineExpose({ short })
</script>

<template>
  <p v-if="missing === 0" class="tiny muted cost">Nothing to mend.</p>

  <p v-else-if="byCoin" class="tiny muted cost">Mended by the trader, for coin.</p>

  <template v-else>
    <p class="tiny cost">
      <span class="muted lead">Costs</span>
      <span
        v-for="r in rows"
        :key="r.key"
        class="mono part"
        :class="{ short: r.have < r.need }"
      >{{ r.need }} {{ r.name }}<span v-if="r.have < r.need" class="held">&nbsp;({{ r.have }})</span></span>
    </p>

    <!--
      §8.2 -- or over the counter. The gold buys the PARTS, never the labour,
      which is why the mend still teaches: a trader selling you four planks and
      standing back is not a bench.
    -->
    <p v-if="coinOffered" class="tiny cost">
      <span class="muted lead">Or</span>
      <span class="mono part gold" :class="{ short: (game.character?.gold ?? 0) < gold }">{{ gold }}g</span>
      <span v-if="keep.length" class="muted plus">plus</span>
      <span
        v-for="r in keep"
        :key="r.key"
        class="mono part"
        :class="{ short: r.have < r.need }"
      >{{ r.need }} {{ r.name }}<span v-if="r.have < r.need" class="held">&nbsp;({{ r.have }})</span></span>
    </p>
  </template>
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

/* §13.3 -- gold is the currency itself, and this is the one figure in the row
   that is one. The materials beside it stay quiet. */
.part.gold {
  color: var(--gold);
}

/* §13.3 -- ember is a state to deal with, and being short is one. It outranks
   the gold above, because a price you cannot meet is a problem before it is a
   price. */
.part.short {
  color: var(--ember);
}

.plus {
  letter-spacing: 0.08em;
}

.held {
  opacity: 0.8;
}
</style>
