<script setup lang="ts">
/**
 * The prospector sheet, §7 and §8.
 *
 * This screen is about **what is on the character**, and it is deliberately the
 * only thing it is about. Levels, job levels, points and trees are the Jobs
 * sheet's (§7.4), and a second copy of a skill level here would be a second
 * place for the same number to be read -- and eventually to be wrong. There is
 * one pointer to that sheet and no figures repeated from it.
 *
 * Two things it does have to make obvious, because nowhere else says them:
 *  - every bonus ends at one ceiling (§8.1 rule 1), and how much of it is spent
 *  - a tool pays out on its own line and nowhere else (§8 rule 1)
 *
 * The five gathering lines used to be printed twice on this page -- once as a
 * yield table and once as a rack of tool slots -- which is the same duplication
 * in miniature. They are one list now: the line, what is in its hand, and what
 * that is worth on its own ground.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import {
  ITEM_BY_KEY,
  SKILL_LIST,
  SLOT_LABEL,
  STAT_LABEL,
  slotForSkill,
} from '@/game/catalog'
import { formatPercent, formatStat } from '@/game/formulas'
import { EQUIPMENT } from '@/game/balance'
import { itemIcon, skillIcon } from '@/icons/procedural'
import GearRow from '@/components/GearRow.vue'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipSlot, OwnedItem, StatKey } from '@/game/types'

const game = useGame()

/**
 * §8 -- worn gear only. The five tool slots are not here: a tool belongs to its
 * line, and it is drawn on the line so that "what am I carrying for the forest"
 * and "what is the forest worth to me" are one answer instead of two.
 */
const WORN: Array<{ key: EquipSlot; label: string; hint: string }> = [
  { key: 'armor', label: SLOT_LABEL.armor, hint: 'Empty' },
  { key: 'boots', label: SLOT_LABEL.boots, hint: 'Empty' },
  { key: 'gloves', label: SLOT_LABEL.gloves, hint: 'Empty' },
  { key: 'weapon', label: SLOT_LABEL.weapon, hint: 'Raids — nothing to equip yet' },
]

const equipped = computed(() => {
  const map = {} as Record<EquipSlot, OwnedItem | undefined>
  for (const item of game.equipment) {
    if (!item.equipped) continue
    const def = ITEM_BY_KEY[item.key]
    if (def?.slot) map[def.slot] = item
  }
  return map
})

const stowed = computed(() => game.equipment.filter((e) => !e.equipped))

/**
 * §8 -- one row per gathering line, in one place.
 *
 * `yield` is the server's aggregate for that line, already capped and already
 * carrying the tool, the tree and any draught. The tool's own printed value is
 * left off on purpose: two numbers for one thing is exactly what this page is
 * being cured of.
 */
const lines = computed(() =>
  SKILL_LIST.map((skill) => {
    const slot = slotForSkill(skill.key)
    const tool = equipped.value[slot]
    const def = tool ? ITEM_BY_KEY[tool.key]! : null

    return {
      key: skill.key,
      name: skill.name,
      label: SLOT_LABEL[slot],
      tool,
      toolName: def?.name ?? null,
      rarity: def?.rarity ?? null,
      // §13.1 -- the real thing, drawn from its own slot, rarity and material.
      // The line glyph is what stands in when the hand is empty; drawing it
      // over a piece of gear would throw away both channels the icon set
      // carries, and rarity is most of what a player is looking for here.
      icon: def ? itemIcon({ slot: def.slot, family: def.family, rarity: def.rarity, palette: def.palette, size: 30 }) : null,
      durability: tool?.durability ?? 0,
      maxDurability: def?.maxDurability ?? 1,
      wear: tool ? (tool.durability / (def!.maxDurability ?? 1)) * 100 : 0,
      broken: tool ? tool.durability <= 0 : false,
      yield: game.toolYield?.[skill.key] ?? 0,
    }
  }),
)

/**
 * §8.1 rule 1 -- the load-bearing number on this page.
 *
 * Not a bare percentage but a percentage *of a ceiling*, because the ceiling is
 * the rule: gear, a bought tree and a draught are three roads to the same +15%
 * and none of them passes it. A meter says how much of that road is walked far
 * better than a figure a player has to hold 0.15 in their head to read.
 *
 * Yield is missing on purpose -- §8 makes it a number per line, not one number,
 * and it is on the lines below.
 */
const CEILING = EQUIPMENT.statCeiling

const ceilings = computed(() =>
  (['tripReduction', 'travelSpeed', 'processingSpeed'] as StatKey[]).map((key) => {
    const value = Math.abs(game.bonuses?.[key] ?? 0)

    return {
      key,
      label: STAT_LABEL[key],
      reading: formatStat(key, game.bonuses?.[key] ?? 0),
      percent: Math.min(100, (value / CEILING) * 100),
      headroom: formatPercent(Math.max(0, CEILING - value)),
      maxed: value >= CEILING - 1e-9,
    }
  }),
)
</script>

<template>
  <div v-if="game.character" class="page">
    <!-- ------------------------------------------------------- ceiling -->
    <section class="inset">
      <div class="row-between" style="margin-bottom: 10px">
        <h3 class="head">Against the ceiling</h3>
        <span class="tiny mono muted">{{ formatPercent(CEILING) }} is the roof</span>
      </div>

      <div class="meters">
        <div v-for="stat in ceilings" :key="stat.key" class="meter">
          <div class="row-between">
            <span class="label">{{ stat.label }}</span>
            <strong class="mono reading" :class="{ maxed: stat.maxed }">{{ stat.reading }}</strong>
          </div>
          <div class="bar" :class="stat.maxed ? 'bar-gold' : ''">
            <span :style="{ width: `${stat.percent}%` }" />
          </div>
          <span class="tiny muted">
            {{ stat.maxed ? 'At the roof — nothing adds to this any more.' : `${stat.headroom} still to buy` }}
          </span>
        </div>
      </div>

      <p class="tiny muted note">
        Gear, a skill tree and a draught all feed this one sum and stop at the
        same roof. A second item of the same kind is worth
        ×{{ EQUIPMENT.stackFalloff }} the first — buying three of a thing does
        not make you three times better.
      </p>
    </section>

    <!-- ---------------------------------------------------------- lines -->
    <!-- §8 rule 1: a tool pays out on its own line and nowhere else. The zeroes
         are the useful part — they are the lines you own no tool for. -->
    <section class="section">
      <div class="row-between" style="margin-bottom: 8px">
        <h3 class="head">Lines</h3>
        <span class="tiny muted">a tool works one line each</span>
      </div>

      <div v-for="line in lines" :key="line.key" class="inset line" :class="{ bare: !line.tool }">
        <SvgIcon v-if="line.icon" :svg="line.icon" boxed :size="30" />
        <span v-else class="icon-box glyph" v-html="skillIcon(line.key, 20)" />

        <div class="grow">
          <div class="row-between">
            <strong class="tiny">{{ line.name }}</strong>
            <strong class="tiny mono yield">{{ formatPercent(line.yield) }}</strong>
          </div>

          <template v-if="line.tool">
            <div class="tiny" :class="`rarity-${line.rarity}`">{{ line.toolName }}</div>
            <p v-if="line.broken" class="tiny broken">
              Broken — this line is paying the bare-handed rate until it is repaired.
            </p>
            <div v-else class="row wear">
              <div class="bar grow" :class="line.wear < 25 ? 'bar-ember' : ''">
                <span :style="{ width: `${line.wear}%` }" />
              </div>
              <span class="tiny mono muted">{{ line.durability }}/{{ line.maxDurability }}</span>
            </div>
          </template>

          <!-- §4.0 -- never a refusal. The hex is still workable; it just pays
               in scrap, and that gap is the argument for a first tool. -->
          <p v-else class="tiny muted">
            No {{ line.label.toLowerCase() }} — bare hands still work this ground, and bring back scrap.
          </p>
        </div>

        <div v-if="line.tool" class="row-actions">
          <button class="btn btn-sm" type="button" :disabled="game.busy" @click="game.repair(line.tool.id)">
            Repair
          </button>
          <button class="btn btn-sm" type="button" :disabled="game.busy" @click="game.unequip(line.tool.id)">
            Stow
          </button>
        </div>
      </div>
    </section>

    <!-- ----------------------------------------------------------- worn -->
    <section class="section">
      <div class="row-between" style="margin-bottom: 8px">
        <h3 class="head">Worn</h3>
        <span class="tiny muted">works everywhere</span>
      </div>

      <div v-for="slot in WORN" :key="slot.key" class="inset row-item">
        <template v-if="equipped[slot.key]">
          <GearRow :item="equipped[slot.key]!">
            <button class="btn btn-sm" type="button" :disabled="game.busy" @click="game.repair(equipped[slot.key]!.id)">
              Repair
            </button>
            <button class="btn btn-sm" type="button" :disabled="game.busy" @click="game.unequip(equipped[slot.key]!.id)">
              Stow
            </button>
          </GearRow>
        </template>

        <template v-else>
          <span class="icon-box empty-slot">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor"
                 stroke-width="1.1" stroke-dasharray="3 2.6" stroke-linejoin="round" aria-hidden="true">
              <path d="M23 12 17.5 21.5h-11L1 12l5.5-9.5h11Z" />
            </svg>
          </span>
          <div class="grow">
            <strong class="tiny muted">{{ slot.label }}</strong>
            <div class="tiny muted">{{ slot.hint }}</div>
          </div>
        </template>
      </div>
    </section>

    <!-- --------------------------------------------------------- stowed -->
    <!-- §7.6 -- an unworn piece is carried, so this list is bag rows. Taking
         something off is the one action that adds a row. -->
    <section v-if="stowed.length" class="section">
      <div class="row-between" style="margin-bottom: 8px">
        <h3 class="head">Stowed</h3>
        <span class="tiny muted">carried, not worn — one bag row each</span>
      </div>

      <div v-for="item in stowed" :key="item.id" class="inset row-item">
        <GearRow :item="item">
          <button
            class="btn btn-sm"
            type="button"
            :disabled="game.busy || item.durability <= 0"
            @click="game.equip(item.id)"
          >
            Equip
          </button>
          <button
            class="btn btn-sm btn-danger"
            type="button"
            :disabled="game.busy"
            title="Returns a small salvage"
            @click="game.discard(item.id)"
          >
            Scrap
          </button>
        </GearRow>
      </div>
    </section>

    <!-- ------------------------------------------------------- identity -->
    <section class="section">
      <h3 class="head" style="margin-bottom: 8px">Character</h3>
      <div class="inset stack">
        <div class="row-between tiny">
          <span class="muted">Wallet</span>
          <span class="mono wallet">{{ game.character.wallet }}</span>
        </div>
        <div class="row-between tiny">
          <span class="muted">Position</span>
          <span class="mono">{{ game.character.col }}, {{ game.character.row }}</span>
        </div>
        <div class="row-between tiny">
          <span class="muted">Bound</span>
          <span>Soulbound — one character per wallet, non-transferable</span>
        </div>
        <p class="tiny muted" style="margin: 0; line-height: 1.5">
          Levels buy capacity: access to deeper tiles. Not the bag, and not the
          road — those are the Explorer's, and the only way to earn them is to
          walk. Levels never buy raw power.
        </p>
      </div>
    </section>

    <!-- One pointer, no figures. Levels, points and trees are the Jobs sheet's
         and are not repeated here. -->
    <p class="tiny muted footnote">
      How good you are at each of these — levels, points and the trees they open
      — lives in the Jobs sheet.
    </p>
  </div>
</template>

<style scoped>
.page {
  /* Sizing and scrolling belong to PanelOverlay. */
  padding: 0;
}

.section {
  margin-top: 18px;
}

.head {
  font-size: 14px;
}

.note {
  margin: 11px 0 0;
  line-height: 1.45;
}

/* One meter per stat. Stacked rather than side by side: the bar is the reading,
   and a bar too short to see is no reading at all. */
.meters {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.meter {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.meter .bar {
  height: 6px;
}

.reading {
  font-size: 14px;
}

.reading.maxed {
  color: var(--gold);
}

/* A line, its tool and what the pair is worth. A bare line is dimmed rather
   than hidden -- the gap is the thing worth seeing. */
.line {
  display: flex;
  align-items: center;
  gap: 11px;
  min-height: 58px;
}

.line + .line {
  margin-top: 6px;
}

.line.bare {
  opacity: 0.62;
}

.glyph {
  width: 38px;
  height: 38px;
  color: var(--copper);
}

.yield {
  font-size: 13px;
  color: var(--gold);
}

.line.bare .yield {
  color: var(--vellum-dim);
}

.wear {
  gap: 7px;
  margin-top: 5px;
}

.broken {
  margin: 4px 0 0;
  color: var(--ember);
}

.empty-slot {
  width: 42px;
  height: 42px;
  color: var(--vellum-dim);
  background: rgba(0, 0, 0, 0.22);
}

.row-actions {
  display: flex;
  flex: 0 0 auto;
  justify-content: flex-end;
  gap: 5px;
}

.wallet {
  overflow-wrap: anywhere;
  text-align: right;
}

.footnote {
  margin: 16px 0 0;
  line-height: 1.5;
}
</style>
