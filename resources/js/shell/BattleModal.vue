<script setup lang="ts">
/**
 * How the fight went, §9.5.5.
 *
 * The health bar is the durability of what you are wearing (§9.5.5), so there
 * is nothing on screen to watch tick down: the exchange runs when you close and
 * is over by the time you look at it. That makes the
 * receipt the entire combat UI -- how the exchange went, which way it fell,
 * what it cost the kit.
 *
 * It gets a plate rather than a toast for the reason §8.2 gives: a fight can
 * DESTROY something, and a status line sliding past the corner is not where a
 * player should learn that a legendary is gone. The destroyed list is the one
 * thing here drawn in ember, because ember is the color of a state to deal
 * with (§13.3) and an empty slot is exactly that.
 *
 * Dismissed by any click and by Escape, with no button: the roll happened
 * before this was drawn, so there is nothing here to agree to.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ACTION_PATHS } from '@/icons/actions'
import { MATERIALS } from '@/game/catalog'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import { optionStatLine } from '@/game/formulas'
import { ITEM_BY_KEY } from '@/game/catalog'
import type { BattleResult } from '@/api/types'

const props = defineProps<{ battle: BattleResult }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const won = computed(() => props.battle.won)


/** §7.4 -- the job keys are the words; there is no table to look them up in. */
const jobName = computed(() => {
  const key = props.battle.job
  return key ? key[0]!.toUpperCase() + key.slice(1) : null
})

/** §9.5.8 -- what came off it, biggest stack first. */
const spoils = computed(() =>
  Object.entries(props.battle.spoils ?? {})
    .map(([key, qty]) => ({ mat: MATERIALS[key as keyof typeof MATERIALS], qty }))
    .filter((r) => r.mat)
    .sort((a, b) => b.qty - a.qty),
)

const lootDef = computed(() =>
  props.battle.looted ? (ITEM_BY_KEY[props.battle.looted.key] ?? null) : null,
)

const settled = ref(false)

const CALM = window.matchMedia('(prefers-reduced-motion: reduce)').matches

onMounted(() => {
  if (CALM) {
    settled.value = true
    return
  }
  requestAnimationFrame(() => {
    settled.value = true
  })
})

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="wrap" role="dialog" aria-label="Fight" @click="$emit('close')">
    <div class="scrim" />

    <div class="fight plate" :class="{ settled }">
      <div class="inner">
        <span class="eyebrow label">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
               stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="ACTION_PATHS.battle" />
          </svg>
          <template v-if="battle.corpse">
            {{ battle.corpse.mine ? 'Your corpse' : `${battle.corpse.owner ?? 'Someone'}'s corpse` }}
            · {{ battle.monster.name }}
          </template>
          <template v-else>{{ battle.monster.name }} · {{ battle.monster.profile }}</template>
        </span>

        <p class="tally">
          <strong class="figure" :class="won ? 'good' : 'bad'">
            {{ won ? 'Down' : battle.died ? 'Killed' : 'Driven off' }}
          </strong>
        </p>

        <!-- §9.5.5 -- the exchange, kept beside the outcome it produced. There
             is no health bar to watch, so this is where a fight is read: how
             long it took, and how close it was. -->
        <p class="tiny muted odds">
          {{ battle.rounds }} rounds · you {{ battle.attack }}/{{ battle.defense }} ·
          it {{ battle.monster.attack }}/{{ battle.monster.defense }}
        </p>
        <p class="tiny muted odds">
          You dealt {{ battle.damageDealt }} of its {{ battle.monster.hp }} ·
          it took {{ battle.damageTaken }} of your {{ battle.pool }}
        </p>

        <!-- §9.5.8 -- gold always, and never a bag row. A loss pays nothing at
             all: losing is an exit, not a strategy (§9.5.3). -->
        <div class="inset ledger tiny">
          <div class="row-between">
            <span class="muted">Gold</span>
            <span class="readout" :class="battle.gold ? 'gold' : 'muted'">
              {{ battle.gold ? `+${battle.gold}` : '—' }}
            </span>
          </div>
          <div v-if="jobName" class="row-between">
            <span class="muted">{{ jobName }}</span>
            <span class="readout" :class="battle.jobXp ? 'good' : 'muted'">
              {{ battle.jobXp ? `+${battle.jobXp} xp` : '—' }}
            </span>
          </div>
        </div>

        <!-- §9.5.8 -- combat feeds combat: two families off the monster and
             nothing from the mining economy. -->
        <ul v-if="spoils.length" class="spoils">
          <li v-for="row in spoils" :key="row.mat.key">
            <SvgIcon :svg="materialIcon(row.mat, 20)" />
            <span class="grow name">{{ row.mat.name }}</span>
            <span class="qty readout good">+{{ row.qty }}</span>
          </li>
        </ul>

        <!-- §9.5.8 -- the kit it was using, at 5-50% and never past rare: epic
             is where gear becomes mintable, and a monster that dropped one
             would be the grind-to-NFT faucet §2 exists to close. -->
        <div v-if="battle.looted" class="inset loot">
          <div class="row">
            <SvgIcon
              v-if="lootDef"
              :svg="itemIcon({
                slot: lootDef.slot,
                family: lootDef.family,
                rarity: lootDef.rarity,
                palette: lootDef.palette,
                size: 26,
              })"
            />
            <span class="grow">
              <strong class="tiny">{{ battle.looted.name }}</strong>
              <span class="tiny muted block">
                Taken off it · {{ battle.looted.durability }}/{{ battle.looted.maxDurability }}
              </span>
            </span>
          </div>
          <span
            v-for="option in battle.looted.options"
            :key="option.stat + option.value"
            class="tiny option"
          >
            {{ lootDef ? optionStatLine(option, lootDef) : option.stat }}
          </span>
        </div>

        <p v-if="battle.leftBehind" class="tiny note bad">
          {{ battle.leftBehind }} had nowhere to go and was left on the ground.
        </p>

        <p v-else-if="battle.spoilsLost" class="tiny note bad">
          {{ battle.spoilsLost }} units would not fit and were left behind.
        </p>

        <!-- §9.5.6 -- wear IS the combat system, so it is the detail this
             receipt owes: the weapon on the gap to their guard, and the one
             worn piece that took the hit. -->
        <ul v-if="battle.wear.length" class="wear">
          <li v-for="row in battle.wear" :key="row.name" :class="{ gone: row.destroyed }">
            <span class="grow name">{{ row.name }}</span>
            <span class="readout bad">−{{ row.lost }}</span>
            <span class="left readout">{{ row.destroyed ? 'destroyed' : row.left }}</span>
          </li>
        </ul>

        <p v-else class="tiny muted empty">
          Nothing on you to wear out. An empty slot absorbs nothing — and it held
          nothing off either.
        </p>

        <p v-if="battle.destroyed.length" class="tiny note bad">
          {{ battle.destroyed.join(', ') }} ran out and {{ battle.destroyed.length === 1 ? 'is' : 'are' }} gone.
        </p>

        <!-- §9.5.7 -- what the row did, and it is never a footnote: the corpse
             is the whole reason a death is recoverable rather than a fine. -->
        <p v-if="battle.recovered" class="tiny note good">
          {{ battle.recovered }} is back in your bag.
        </p>

        <p v-else-if="battle.burned" class="tiny note bad">
          {{ battle.burned }} was not yours to carry. It burned with the corpse.
        </p>

        <div v-if="battle.died" class="inset death">
          <strong class="tiny">It put you down.</strong>
          <p v-if="battle.stolen" class="tiny muted">
            It kept <strong>{{ battle.stolen.label }}</strong> and is still standing
            where you fell. Kill it yourself and the row comes home — anybody else
            kills it and the row is gone.
          </p>
          <p v-else class="tiny muted">
            Your bag was empty, so it took nothing. The walk back is the whole bill.
          </p>
          <p v-if="battle.wokeAt" class="tiny muted">
            You woke at {{ battle.wokeAt.name }} ({{ battle.wokeAt.col }},
            {{ battle.wokeAt.row }}).
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wrap {
  position: absolute;
  inset: 0;
  z-index: var(--z-panel);
  display: grid;
  place-items: center;
  padding: 16px;
}

.scrim {
  position: absolute;
  inset: 0;
  background: rgba(10, 14, 12, 0.72);
}

.fight {
  position: relative;
  width: min(340px, 100%);
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.24s ease, transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.fight.settled {
  opacity: 1;
  transform: none;
}

.inner {
  display: flex;
  flex-direction: column;
  gap: 11px;
  padding: 14px 15px 15px;
}

.eyebrow {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--copper);
  text-transform: capitalize;
}

.tally {
  margin: -3px 0 0;
}

.figure {
  font-family: var(--font-display);
  font-size: 34px;
  line-height: 0.9;
}

.figure.good {
  color: var(--sap);
}

.figure.bad {
  color: var(--ember);
}

.odds {
  margin: -6px 0 0;
  line-height: 1.5;
}

.ledger {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* ------------------------------------------------------------------- wear */

.wear {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.wear li {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 12px;
  padding: 5px 8px;
  background: rgba(0, 0, 0, 0.28);
}

.wear .name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.left {
  font-size: 11px;
  color: var(--vellum-dim);
}

.wear li.gone {
  color: var(--ember);
}

.wear li.gone .left {
  color: var(--ember);
}

/* ----------------------------------------------------------------- spoils */

.spoils {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.spoils li {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 12px;
}

.loot {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.loot .row {
  display: flex;
  align-items: center;
  gap: 9px;
}

.block {
  display: block;
}

.option {
  color: var(--copper);
}

.death {
  display: flex;
  flex-direction: column;
  gap: 5px;
  border-left: 2px solid var(--ember);
}

.death p {
  margin: 0;
  line-height: 1.5;
}

.good {
  color: var(--sap);
}

.empty,
.note {
  margin: 0;
  line-height: 1.5;
}
</style>
