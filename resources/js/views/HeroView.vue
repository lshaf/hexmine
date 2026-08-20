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
import { ITEM_BY_KEY, SKILL_LIST, STAT_LABEL } from '@/game/catalog'
import { formatPercent } from '@/game/formulas'
import { EQUIPMENT, SKILLS } from '@/game/balance'
import { itemIcon, skillIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipSlot, OwnedItem, StatKey } from '@/game/types'

const game = useGame()

const SLOTS: Array<{ key: EquipSlot; label: string }> = [
  { key: 'tool', label: 'Tool' },
  { key: 'armor', label: 'Armor' },
  { key: 'boots', label: 'Boots' },
  { key: 'gloves', label: 'Gloves' },
  { key: 'weapon', label: 'Weapon' },
]

const equipped = computed(() => {
  const map = {} as Record<EquipSlot, OwnedItem | undefined>
  for (const item of game.equipment) {
    if (!item.equipped) continue
    const def = ITEM_BY_KEY[item.key]
    if (def) map[def.slot] = item
  }
  return map
})

const stowed = computed(() => game.equipment.filter((e) => !e.equipped))

const skillPointsUsed = computed(() =>
  game.skills ? Object.values(game.skills).reduce((sum, s) => sum + s.level, 0) : 0,
)

const def = (item: OwnedItem) => ITEM_BY_KEY[item.key]!

const durabilityPercent = (item: OwnedItem) =>
  (item.durability / def(item).maxDurability) * 100

const statKeys: StatKey[] = ['yield', 'tripReduction', 'travelSpeed', 'processingSpeed']
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

    <!-- ------------------------------------------------------ equipment -->
    <section class="section">
      <h3 class="head" style="margin-bottom: 8px">Equipment</h3>
      <div v-for="slot in SLOTS" :key="slot.key" class="slot-row">
        <template v-if="equipped[slot.key]">
          <SvgIcon
            :svg="itemIcon({
              slot: def(equipped[slot.key]!).slot,
              tier: def(equipped[slot.key]!).tier,
              palette: def(equipped[slot.key]!).palette,
              size: 30,
            })"
            boxed
            :size="30"
          />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny">{{ def(equipped[slot.key]!).name }}</strong>
              <span class="chip tiny" :class="def(equipped[slot.key]!).tier === 'nft' ? 'chip-nft' : ''">
                {{ formatPercent(def(equipped[slot.key]!).value) }}
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
            <div class="tiny muted">Empty</div>
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
          :svg="itemIcon({ slot: def(item).slot, tier: def(item).tier, palette: def(item).palette, size: 30 })"
          boxed
          :size="30"
        />
        <div class="grow">
          <div class="row-between">
            <strong class="tiny">{{ def(item).name }}</strong>
            <span class="tiny mono muted">{{ item.durability }}/{{ def(item).maxDurability }}</span>
          </div>
          <div class="tiny muted">
            <template v-if="item.durability <= 0">
              Broken — inactive until repaired, never destroyed.
            </template>
            <template v-else>{{ formatPercent(def(item).value) }} {{ STAT_LABEL[def(item).stat] }}</template>
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
          Levels buy capacity: action points, storage, travel range, and access to
          deeper tiles. They never buy raw power.
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
