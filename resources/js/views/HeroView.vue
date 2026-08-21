<script setup lang="ts">
/**
 * Character sheet, §7 and §8.
 *
 * Two things this screen has to make obvious:
 *  - level buys capacity, never power (§7.1)
 *  - every equipment bonus is capped and stacks with diminishing returns (§8.1)
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import {
  ITEM_BY_KEY,
  SKILL_BY_KEY,
  SKILL_LIST,
  SLOT_LABEL,
  STAT_LABEL,
  slotForSkill,
} from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { EQUIPMENT, SKILLS } from '@/game/balance'
import { itemIcon, skillIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipSlot, OwnedItem, SkillKey, StatKey } from '@/game/types'

const game = useGame()

/**
 * §8 -- one tool slot per gathering line. They are listed apart from worn gear
 * because they behave differently: a tool only pays out on its own line, and
 * only the tool that did the work loses durability.
 */
interface SlotRow {
  key: EquipSlot
  label: string
  /** Shown on an empty slot, where the label alone does not say what goes here. */
  hint?: string
}

interface ToolRow extends SlotRow {
  line: SkillKey
}

const TOOL_SLOTS: ToolRow[] = SKILL_LIST.map((skill) => ({
  key: slotForSkill(skill.key),
  label: SLOT_LABEL[slotForSkill(skill.key)],
  line: skill.key,
  hint: skill.name,
}))

const WORN_SLOTS: SlotRow[] = [
  { key: 'armor', label: SLOT_LABEL.armor },
  { key: 'boots', label: SLOT_LABEL.boots },
  { key: 'gloves', label: SLOT_LABEL.gloves },
  { key: 'weapon', label: SLOT_LABEL.weapon, hint: 'Raids — nothing to equip yet' },
]

const GROUPS: Array<{ title: string; note: string; slots: SlotRow[] }> = [
  { title: 'Gathering tools', note: 'one line each', slots: TOOL_SLOTS },
  { title: 'Worn', note: 'works everywhere', slots: WORN_SLOTS },
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

const skillPointsUsed = computed(() =>
  game.skills ? Object.values(game.skills).reduce((sum, s) => sum + s.level, 0) : 0,
)

const def = (item: OwnedItem) => ITEM_BY_KEY[item.key]!

const durabilityPercent = (item: OwnedItem) =>
  (item.durability / (def(item).maxDurability ?? 1)) * 100

/** Yield is missing on purpose: §8 makes it a number per line, not one number. */
const statKeys: StatKey[] = ['tripReduction', 'travelSpeed', 'processingSpeed']
</script>

<template>
  <div v-if="game.character" class="page">
    <!-- ------------------------------------------------------- bonuses -->
    <section class="inset">
      <div class="row-between" style="margin-bottom: 9px">
        <h3 class="head">Active bonuses</h3>
        <span class="tiny muted">capped per slot</span>
      </div>
      <div class="grid-2">
        <div v-for="key in statKeys" :key="key" class="stat">
          <span class="label">{{ STAT_LABEL[key] }}</span>
          <strong class="mono">{{ formatPercent(game.bonuses?.[key] ?? 0) }}</strong>
        </div>
      </div>
      <p class="tiny muted note">
        A second item of the same kind is worth ×{{ EQUIPMENT.stackFalloff }} the first.
        Buying three of a thing does not make you three times better.
      </p>
    </section>

    <!-- ------------------------------------------------- yield by line -->
    <!-- §8: a tool pays out on its own line and nowhere else, so there is no
         single yield figure any more. The zeroes are the useful part — they are
         the lines you own no tool for. -->
    <section class="inset">
      <div class="row-between" style="margin-bottom: 9px">
        <h3 class="head">Yield by line</h3>
        <span class="tiny muted">tools work one line each</span>
      </div>
      <div class="lines">
        <div
          v-for="tool in TOOL_SLOTS"
          :key="tool.line"
          class="line-cell"
          :class="{ bare: (game.toolYield?.[tool.line] ?? 0) === 0 }"
        >
          <SvgIcon :svg="skillIcon(tool.line, 20)" :size="20" />
          <span class="tiny">{{ SKILL_BY_KEY[tool.line].name }}</span>
          <strong class="tiny mono">{{ formatPercent(game.toolYield?.[tool.line] ?? 0) }}</strong>
        </div>
      </div>
    </section>

    <!-- ------------------------------------------------------ equipment -->
    <section v-for="group in GROUPS" :key="group.title" class="section">
      <div class="row-between" style="margin-bottom: 8px">
        <h3 class="head">{{ group.title }}</h3>
        <span class="tiny muted">{{ group.note }}</span>
      </div>
      <div v-for="slot in group.slots" :key="slot.key" class="slot-row">
        <template v-if="equipped[slot.key]">
          <SvgIcon
            :svg="itemIcon({
              slot: def(equipped[slot.key]!).slot,
              rarity: def(equipped[slot.key]!).rarity,
              palette: def(equipped[slot.key]!).palette,
              size: 30,
            })"
            boxed
            :size="30"
          />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny" :class="`rarity-${def(equipped[slot.key]!).rarity}`">{{ def(equipped[slot.key]!).name }}</strong>
              <span class="chip tiny" :class="def(equipped[slot.key]!).tradeable ? 'chip-nft' : ''">
                {{ formatPercent(def(equipped[slot.key]!).value) }}
              </span>
            </div>
            <!-- §8.0.1 -- rolled lines. Listed under the base stat because that
                 is what they are: extra, on top of what the item is for. -->
            <div v-if="equipped[slot.key]!.options?.length" class="rolled">
              <span
                v-for="(option, i) in equipped[slot.key]!.options"
                :key="i"
                class="tiny mono roll"
              >
                {{ formatPercent(option.value) }} {{ STAT_LABEL[option.stat] }}
              </span>
            </div>

            <div class="row" style="gap: 7px; margin-top: 5px">
              <div class="bar grow" :class="durabilityPercent(equipped[slot.key]!) < 25 ? 'bar-ember' : ''">
                <span :style="{ width: `${durabilityPercent(equipped[slot.key]!)}%` }" />
              </div>
              <span class="tiny mono muted">
                {{ equipped[slot.key]!.durability }}/{{ def(equipped[slot.key]!).maxDurability }}
              </span>
            </div>
          </div>
          <div class="row-actions">
            <button
              class="btn btn-sm"
              type="button"
              :disabled="game.busy"
              @click="game.repair(equipped[slot.key]!.id)"
            >
              Repair
            </button>
            <button
              class="btn btn-sm"
              type="button"
              :disabled="game.busy"
              @click="game.unequip(equipped[slot.key]!.id)"
            >
              Stow
            </button>
          </div>
        </template>

        <template v-else>
          <span class="icon-box slot-icon empty-slot">
            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor"
                 stroke-width="1.1" stroke-dasharray="3 2.6" stroke-linejoin="round" aria-hidden="true">
              <path d="M23 12 17.5 21.5h-11L1 12l5.5-9.5h11Z" />
            </svg>
          </span>
          <div class="grow">
            <strong class="tiny muted">{{ slot.label }}</strong>
            <div class="tiny muted">{{ slot.hint ?? 'Empty' }}</div>
          </div>
          <div class="row-actions"></div>
        </template>
      </div>
    </section>

    <!-- --------------------------------------------------------- stowed -->
    <section v-if="stowed.length" class="section">
      <h3 class="head" style="margin-bottom: 8px">Stowed</h3>
      <div v-for="item in stowed" :key="item.id" class="slot-row">
        <SvgIcon
          :svg="itemIcon({ slot: def(item).slot, rarity: def(item).rarity, palette: def(item).palette, size: 30 })"
          boxed
          :size="30"
        />
        <div class="grow">
          <div class="row-between">
            <strong class="tiny" :class="`rarity-${def(item).rarity}`">{{ def(item).name }}</strong>
            <span class="tiny mono muted">{{ item.durability }}/{{ def(item).maxDurability }}</span>
          </div>
          <div class="tiny muted">
            <template v-if="item.durability <= 0">
              Broken — inactive until repaired, never destroyed.
            </template>
            <template v-else>{{ formatPercent(def(item).value) }} {{ STAT_LABEL[def(item).stat] }}</template>
          </div>
          <div v-if="item.options?.length" class="rolled">
            <span v-for="(option, i) in item.options" :key="i" class="tiny mono roll">
              {{ formatPercent(option.value) }} {{ STAT_LABEL[option.stat] }}
            </span>
          </div>
        </div>
        <div class="row-actions">
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
        </div>
      </div>
    </section>

    <!-- --------------------------------------------------------- skills -->
    <section class="section">
      <div class="row-between" style="margin-bottom: 8px">
        <h3 class="head">Skill lines</h3>
        <span class="tiny mono muted">
          {{ skillPointsUsed }}/{{ SKILLS.totalPointCap }} points
        </span>
      </div>
      <p class="tiny muted note" style="margin-top: 0">
        Each line speeds and enriches its own material only, and total points are
        capped — you specialise, you do not max everything.
      </p>

      <div v-for="skill in SKILL_LIST" :key="skill.key" class="inset row-item">
        <span class="icon-box skill-glyph" style="width: 38px; height: 38px" v-html="skillIcon(skill.key, 20)" />
        <div class="grow">
          <div class="row-between">
            <strong class="tiny">{{ skill.name }}</strong>
            <span class="tiny mono muted">Lv {{ game.skills?.[skill.key].level ?? 1 }}</span>
          </div>
          <div class="bar" style="margin-top: 5px">
            <span
              :style="{
                width: `${((game.skills?.[skill.key].xp ?? 0) / (game.skills?.[skill.key].xpToNext ?? 1)) * 100}%`,
              }"
            />
          </div>
          <div class="tiny muted" style="margin-top: 3px">{{ skill.description }}</div>
        </div>
      </div>
    </section>

    <!-- ------------------------------------------------------- identity -->
    <section class="section">
      <h3 class="head" style="margin-bottom: 8px">Character</h3>
      <div class="inset stack">
        <div class="row-between tiny">
          <span class="muted">Wallet</span>
          <span class="mono">{{ game.character.wallet }}</span>
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
          Levels buy capacity: action points and access to deeper tiles. Not the
          bag, and not the road — those are the Explorer's, and the only way to
          earn them is to walk. Levels never buy raw power.
        </p>
      </div>
    </section>

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
  margin: 9px 0 0;
  line-height: 1.45;
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
}

.stat strong {
  font-size: 15px;
}

/* One row per gathering line. A bare line is dimmed rather than hidden -- the
   gap is the thing worth seeing. */
.lines {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.line-cell {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
}

.line-cell span {
  flex: 1 1 auto;
}

.line-cell.bare {
  opacity: 0.5;
}

/* Rolled lines sit apart from the base stat and read as a set, so two items of
   the same recipe can be compared at a glance. */
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

.slot-row {
  display: flex;
  align-items: center;
  gap: 11px;
  min-height: 60px;
  padding: 9px 11px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--line);
  background: var(--ink-panel);
}

.slot-row + .slot-row {
  margin-top: 7px;
}

.slot-icon {
  width: 42px;
  height: 42px;
}

.empty-slot {
  color: var(--vellum-dim);
  background: rgba(0, 0, 0, 0.22);
}

.row-actions {
  display: flex;
  flex: 0 0 auto;
  justify-content: flex-end;
  gap: 5px;
  min-width: 126px;
}

.skill-glyph {
  color: var(--copper);
}
</style>
