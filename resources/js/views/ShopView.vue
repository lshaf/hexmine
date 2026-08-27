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
import { consumableResale, resaleBasis, resaleValue } from '@/game/formulas'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import StatChips from '@/components/StatChips.vue'
import type { Material, MaterialKey, Rarity } from '@/game/types'

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

/**
 * §4.0 -- the tier-zero half of the pack, as one figure.
 *
 * Tier is the whole test, so it takes the five biome scrap and the five junk
 * together: two different arguments about where a copper came from, one chore
 * to be rid of. The server re-decides all of this on the way in; what is
 * computed here is only whether the button is worth drawing and what it should
 * promise.
 */
const scrap = computed(() => {
  const rows = sellable.value.filter((e) => e.mat.tier === 0)

  return {
    rows: rows.length,
    units: rows.reduce((n, e) => n + e.qty, 0),
    gold: rows.reduce((n, e) => n + e.qty * e.mat.npcPrice, 0),
  }
})

/**
 * Only what this settlement stocks, §3.2 -- the server decides, we render it.
 *
 * What it does not stock is not on the shelf at all. A row you cannot buy is
 * not a ladder, it is a list of somewhere else, and the bench panel already
 * settles this the same way (CraftView): out of reach here means hidden here.
 */
const catalog = computed(() => shopItems().filter((i) => game.shopStock.includes(i.key)))

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
    .filter((e) => !e.equipped && resaleBasis(ITEM_BY_KEY[e.key] ?? ({} as never)) > 0)
    .map((e) => {
      const def = ITEM_BY_KEY[e.key]!

      return {
        item: e,
        def,
        // §7.4.3 -- the piece's own ceiling, not the recipe's: a Smith's node
        // raises one and not the other, and measuring against the catalog put a
        // well-made piece past 100% and clamped its resale back down.
        gold: resaleValue(def, e.durability, e.maxDurability),
        wear: Math.round((e.durability / Math.max(1, e.maxDurability || (def.maxDurability ?? 1))) * 100),
      }
    })
    .sort((a, b) => b.gold - a.gold),
)

/**
 * §8.2 -- potions the trader will take, worth most first.
 *
 * Priced off the recipe rather than a shelf, because nothing stocks a brew
 * (§8.5 makes it a thing you make) -- and deliberately under what its reagents
 * would have fetched, or the alchemy bench would be a gold press.
 *
 * Epic and legendary drafts are absent for the reason gear's top rungs are:
 * gold stops at the second rung (§3.2). It bites harder here, because every one
 * of those wants a Tier 3 rare and those are capped per wallet -- a price on one
 * would turn a capped rare into uncapped coin.
 */
const potions = computed(() =>
  Object.entries(game.consumables)
    .map(([key, qty]) => ({ key, qty, def: ITEM_BY_KEY[key] }))
    .filter((row) => row.qty > 0 && row.def && sellableRarity(row.def.rarity))
    .map((row) => ({ ...row, def: row.def!, each: consumableResale(row.def!) }))
    .filter((row) => row.each > 0)
    .sort((a, b) => b.each * b.qty - a.each * a.qty),
)

/** §3.2 -- gold reaches the bottom two rungs and stops. */
function sellableRarity(rarity: Rarity): boolean {
  return rarity === 'common' || rarity === 'uncommon'
}

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

        <div v-if="!sellable.length && !resellable.length && !potions.length" class="inset empty">
          <p class="muted tiny" style="margin: 0">Nothing the trader will buy.</p>
        </div>

        <!-- §4.0 -- the one row that is a chore rather than a decision. Scrap
             and junk reach no tier and feed no recipe, so there is nothing to
             weigh up: the only question is whether the straps are worth more
             than the coppers, and that is answered by pressing this. -->
        <button
          v-if="scrap.rows"
          class="inset row-item dump"
          type="button"
          :disabled="game.busy"
          @click="game.sellAllScrap()"
        >
          <div class="grow">
            <div class="row-between">
              <strong class="tiny">Clear out the scrap</strong>
              <span class="mono tiny take">+{{ scrap.gold }}g</span>
            </div>
            <!-- §4.0 is emphatic that junk is not scrap, and the button takes
                 both, so the line under it says both. The heading keeps the
                 word a player would use for the chore. -->
            <div class="tiny muted">
              Scrap and junk — {{ scrap.units }} across {{ scrap.rows }}
              {{ scrap.rows === 1 ? 'strap' : 'straps' }}
            </div>
          </div>
        </button>

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

        <!-- §8.2 -- the same exit for a brew. No wear to scale by, so a flask
             is worth what the next one is and they sell by the handful like a
             material rather than one object at a time. -->
        <template v-if="potions.length">
          <p class="label group">Potions</p>
          <p class="tiny muted lead">
            Half of what the reagents fetched, so a brew is always worth more
            drunk than sold.
          </p>

          <div v-for="row in potions" :key="row.key" class="inset row-item">
            <SvgIcon
              :svg="itemIcon({ rarity: row.def.rarity, palette: row.def.palette, size: 26 })"
              boxed
              :size="26"
            />
            <div class="grow">
              <div class="row-between">
                <strong class="tiny" :class="`rarity-${row.def.rarity}`">{{ row.def.name }}</strong>
                <span class="mono tiny muted">{{ row.qty }} held</span>
              </div>
              <div class="tiny muted">{{ row.each }}g each</div>
            </div>
            <div class="row" style="gap: 5px">
              <button
                v-for="n in amounts(row.qty)"
                :key="n"
                class="btn btn-sm"
                type="button"
                :disabled="game.busy"
                @click="game.sellPotion(row.key, n)"
              >
                {{ n === row.qty && row.qty > 1 ? 'All' : n }}
              </button>
            </div>
          </div>
        </template>

        <!-- §8.2 -- the third exit a piece of gear has. Repair keeps it, scrap
             returns materials, and this returns gold scaled by what is left. -->
        <template v-if="resellable.length">
          <p class="label group">Equipment</p>
          <p class="tiny muted lead">
            Half of what it is worth — the shelf price, or what the parts cost if
            nobody stocks it. Wear comes off the top, so a battered tool fetches
            a battered tool's price.
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
                {{ resaleBasis(row.def) }}g undamaged · {{ row.gold }}g as it stands
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
              <!-- §9.5.4 -- one row of chips, the same everywhere. A shopper
                   choosing between a shield and a wand is choosing on the pair,
                   so the pair is on the shelf. -->
              <StatChips :def="item" />
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

/*
 * A whole row as one button, because the whole row is one decision. The
 * per-material rows offer 1 / 10 / All and this offers nothing to choose, so
 * giving it a button on the end would imply there was something beside it to
 * read.
 */
.dump {
  width: 100%;
  text-align: left;
  border: 1px solid var(--line);
  cursor: pointer;
}

.dump:hover:not(:disabled) {
  border-color: var(--copper);
}

.dump:disabled {
  opacity: 0.5;
}

/* §13.3 -- gold for a coin figure. Sap is for a thing worth crossing the
   screen for, and clearing the pack is a chore rather than good news. */
.take {
  color: var(--gold);
}
</style>
