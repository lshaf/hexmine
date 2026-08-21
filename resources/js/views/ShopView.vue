<script setup lang="ts">
/**
 * NPC trader, §3.2.
 *
 * Two halves, and the split is the whole point: gold comes in at a deliberately
 * poor rate, and gold buys the bottom two rarities only. Nothing here touches
 * anything tradeable -- gold has no bridge to NFT value (§3.3).
 */
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS, STAT_LABEL, shopItems } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { Material, MaterialKey } from '@/game/types'

const game = useGame()

const mode = ref<'sell' | 'buy'>('sell')

const sellable = computed(() => {
  const out: Array<{ mat: Material; qty: number }> = []
  for (const [key, qty] of Object.entries(game.inventory)) {
    if (!qty) continue
    const mat = MATERIALS[key as MaterialKey] as Material
    if (mat.npcPrice > 0) out.push({ mat, qty })
  }
  return out.sort((a, b) => b.qty * b.mat.npcPrice - a.qty * a.mat.npcPrice)
})

/** Only what this settlement stocks, §3.2 -- the server decides, we render it. */
const catalog = computed(() => shopItems().filter((i) => game.shopStock.includes(i.key)))

/** Stocked elsewhere: shown greyed so the ladder is visible, not hidden. */
const elsewhere = computed(() => shopItems().filter((i) => !game.shopStock.includes(i.key)))

const atSettlement = computed(() => game.currentSettlement !== null)

/** Sell quantities the player actually reaches for. */
function amounts(qty: number): number[] {
  return [1, 10, qty].filter((n, i, arr) => n > 0 && n <= qty && arr.indexOf(n) === i)
}

const owned = (key: string) => game.equipment.filter((e) => e.key === key).length
</script>

<template>
  <div class="page">
    <!-- The dock only offers Trade at a settlement, so this is the case where
         the player walked off while the panel was open. -->
    <p v-if="!atSettlement" class="inset tiny muted away">
      You have left the settlement. The trader stays put.
    </p>

    <template v-else>
    <div class="switch">
      <button type="button" :class="{ on: mode === 'sell' }" @click="mode = 'sell'">Sell</button>
      <button type="button" :class="{ on: mode === 'buy' }" @click="mode = 'buy'">Buy</button>
    </div>

    <div class="scroll body">
      <!-- ------------------------------------------------------- sell -->
      <template v-if="mode === 'sell'">
        <p class="tiny muted lead">
          The trader pays badly, on purpose. Selling is a floor under a bad day,
          never a strategy — and rare and raid materials are refused outright.
        </p>

        <div v-if="!sellable.length" class="inset empty">
          <p class="muted tiny" style="margin: 0">Nothing the trader will buy.</p>
        </div>

        <div v-for="entry in sellable" :key="entry.mat.key" class="inset row-item">
          <SvgIcon :svg="materialIcon(entry.mat, 26)" boxed :size="26" />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny">{{ entry.mat.name }}</strong>
              <span class="mono tiny muted">{{ entry.qty }} held</span>
            </div>
            <div class="tiny muted">{{ entry.mat.npcPrice }}g each</div>
          </div>
          <div class="row" style="gap: 5px">
            <button
              v-for="n in amounts(entry.qty)"
              :key="n"
              class="btn btn-sm"
              type="button"
              :disabled="game.busy"
              @click="game.sell(entry.mat.key, n)"
            >
              {{ n === entry.qty && entry.qty > 1 ? 'All' : n }}
            </button>
          </div>
        </div>
      </template>

      <!-- -------------------------------------------------------- buy -->
      <template v-else>
        <p class="tiny muted lead">
          Common and uncommon only. Gold never reaches past the second rung of
          the ladder, and never buys anything worth something outside the game.
        </p>

        <div v-if="!catalog.length" class="inset empty">
          <p class="muted tiny" style="margin: 0">This settlement stocks nothing right now.</p>
        </div>

        <div v-for="item in catalog" :key="item.key" class="inset row-item">
          <SvgIcon
            :svg="itemIcon({ slot: item.slot, rarity: item.rarity, palette: item.palette, size: 30 })"
            boxed
            :size="30"
          />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny">{{ item.name }}</strong>
              <span class="chip chip-gold mono tiny">{{ item.goldPrice }}g</span>
            </div>
            <div class="tiny muted">{{ item.description }}</div>
            <div class="row tiny" style="gap: 6px; margin-top: 4px">
              <span class="chip tiny">{{ formatPercent(item.value) }} {{ STAT_LABEL[item.stat] }}</span>
              <span class="chip tiny">{{ item.maxDurability }} dur</span>
              <span v-if="owned(item.key)" class="tiny muted">owned ×{{ owned(item.key) }}</span>
            </div>
          </div>
          <button
            class="btn btn-primary btn-sm"
            type="button"
            :disabled="game.busy || (game.character?.gold ?? 0) < (item.goldPrice ?? 0)"
            @click="game.buy(item.key)"
          >
            Buy
          </button>
        </div>

        <template v-if="elsewhere.length">
          <p class="tiny muted lead" style="margin-top: 16px">
            Not stocked here. Bigger settlements carry more.
          </p>
          <div v-for="item in elsewhere" :key="item.key" class="list-item locked">
            <SvgIcon
              :svg="itemIcon({ slot: item.slot, rarity: item.rarity, palette: item.palette, size: 26 })"
              boxed
              :size="26"
            />
            <div class="grow">
              <div class="row-between">
                <strong class="tiny">{{ item.name }}</strong>
                <span class="chip tiny">needs a {{ item.station }}</span>
              </div>
              <div class="tiny muted">
                {{ formatPercent(item.value) }} {{ STAT_LABEL[item.stat] }} · {{ item.goldPrice }}g
              </div>
            </div>
          </div>
        </template>
      </template>
    </div>
    </template>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
}

.switch {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}

.switch button {
  padding: 9px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font-weight: 700;
  font-size: 12px;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.switch button.on {
  background: var(--ink-raised);
  border-color: var(--copper);
  color: var(--vellum);
}

.body {
  padding-top: 14px;
}

.lead {
  margin: 0 0 11px;
  line-height: 1.5;
}

.empty {
  text-align: center;
}

.away {
  margin: 0;
}

.locked {
  opacity: 0.55;
}
</style>
