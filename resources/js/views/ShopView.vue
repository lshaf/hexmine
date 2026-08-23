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
import { ITEM_BY_KEY, MATERIALS, shopItems } from '@/game/catalog'
import { itemStatLine, resaleValue } from '@/game/formulas'
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

/**
 * §8.2 -- gear the trader will take back, worth most first.
 *
 * Worn pieces are absent on purpose: a sale is a trade, and losing the tool off
 * your own belt to a mistap is worse than losing one out of the pack. So is
 * anything the trader does not stock -- gold buys the bottom two rungs and never
 * the top (§3.2), so a crafted or NFT piece has no shelf price to halve, and
 * scrapping is that gear's exit instead.
 *
 * A piece worn past the point where half its price still rounds to a coin is
 * left in too, listed and refused, rather than vanishing off the shelf: a player
 * looking for their axe should find it and be told why it is worth nothing.
 */
const resellable = computed(() =>
  game.equipment
    .filter((e) => !e.equipped && (ITEM_BY_KEY[e.key]?.goldPrice ?? 0) > 0)
    .map((e) => {
      const def = ITEM_BY_KEY[e.key]!

      return {
        item: e,
        def,
        gold: resaleValue(def, e.durability),
        wear: Math.round((e.durability / (def.maxDurability ?? 1)) * 100),
      }
    })
    .sort((a, b) => b.gold - a.gold),
)

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

        <div v-if="!sellable.length && !resellable.length" class="inset empty">
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

        <!-- §8.2 -- the third exit a piece of gear has. Repair keeps it, scrap
             returns materials, and this returns gold scaled by what is left. -->
        <template v-if="resellable.length">
          <p class="label group">Equipment</p>
          <p class="tiny muted lead">
            Half the shelf price, and only for what the trader stocks. Wear comes
            off the top, so a battered tool fetches a battered tool's price.
          </p>

          <div v-for="row in resellable" :key="row.item.id" class="inset row-item">
            <SvgIcon
              :svg="itemIcon({ slot: row.def.slot, rarity: row.def.rarity, palette: row.def.palette, size: 26 })"
              boxed
              :size="26"
            />
            <div class="grow">
              <div class="row-between">
                <strong class="tiny" :class="`rarity-${row.def.rarity}`">{{ row.def.name }}</strong>
                <span class="mono tiny muted">{{ row.wear }}% left</span>
              </div>
              <div class="tiny muted">
                {{ row.def.goldPrice }}g new · {{ row.gold }}g as it stands
              </div>
            </div>
            <button
              class="btn btn-sm"
              type="button"
              :disabled="game.busy || row.gold <= 0"
              :title="row.gold > 0 ? `Sell for ${row.gold} gold` : 'Too far gone to be worth a coin'"
              @click="game.sellItem(row.item.id)"
            >
              {{ row.gold > 0 ? `${row.gold}g` : '—' }}
            </button>
          </div>
        </template>
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
            :svg="itemIcon({ slot: item.slot, family: item.family, rarity: item.rarity, palette: item.palette, size: 30 })"
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
              <span class="chip tiny">{{ itemStatLine(item) }}</span>
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
              :svg="itemIcon({ slot: item.slot, family: item.family, rarity: item.rarity, palette: item.palette, size: 26 })"
              boxed
              :size="26"
            />
            <div class="grow">
              <div class="row-between">
                <strong class="tiny">{{ item.name }}</strong>
                <span class="chip tiny">needs a {{ item.station }}</span>
              </div>
              <div class="tiny muted">
                {{ itemStatLine(item) }} · {{ item.goldPrice }}g
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

/* A heading inside a half, not a section of its own: materials and gear are
   the same act at the same counter. */
.group {
  margin: 16px 0 0;
  color: var(--copper);
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
