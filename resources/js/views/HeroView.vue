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
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import { logout } from '@/wallet/wax'
import {
  ITEM_BY_KEY,
  SKILL_LIST,
  SLOT_LABEL,
  STAT_LABEL,
  slotForSkill,
} from '@/game/catalog'
import { formatPercent, formatStat } from '@/game/formulas'
import { CHARACTER, EQUIPMENT } from '@/game/balance'
import { itemIcon, skillIcon } from '@/icons/procedural'
import GearRow from '@/components/GearRow.vue'
import RepairCost from '@/components/RepairCost.vue'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipSlot, OwnedItem, StatKey } from '@/game/types'

const game = useGame()

const leaving = ref(false)

/**
 * §7 -- claiming a name.
 *
 * Closed until asked for, because the overwhelmingly common case is a player
 * who has one already and is here to read their gear. An always-open text field
 * beside the wallet would make the sheet look like a form.
 */
const naming = ref(false)
const draft = ref('')

const NAME_PATTERN = /^[A-Za-z0-9]+$/

/** The same rules the server holds, said before the round trip rather than
 *  instead of it -- the refusal that counts is still GameService's. */
const nameProblem = computed(() => {
  const value = draft.value.trim()

  if (value === '') return 'Type a name.'
  if (!NAME_PATTERN.test(value)) return 'Letters and digits only.'
  if (value.length < CHARACTER.nameMin || value.length > CHARACTER.nameMax) {
    return `Between ${CHARACTER.nameMin} and ${CHARACTER.nameMax} characters.`
  }
  if (value.toLowerCase() === 'prospector') return 'That is what an unnamed character is called.'

  return ''
})

function startNaming(): void {
  naming.value = true
  draft.value = game.character?.named ? (game.character?.name ?? '') : ''
}

/**
 * The form closes only if the name was actually taken. A refusal the client
 * could not have known about -- somebody already goes by it -- arrives as a
 * toast like every other refusal, and the field stays open with the text still
 * in it so the next attempt is an edit rather than a retype.
 */
async function claimName(): Promise<void> {
  if (nameProblem.value) return

  if (await game.rename(draft.value.trim())) naming.value = false
}

/**
 * Letting go of the wallet, §2.
 *
 * It belongs on this screen and beside the address, because this is the one
 * place that says who you are -- and the thing being disconnected is right
 * above the button that does it.
 *
 * The page is RELOADED rather than the store being emptied. A session ending
 * invalidates every slice of it at once -- character, bag, jobs, quests, the
 * map's live half -- and clearing them by hand would be a second definition of
 * "a fresh session" for the first one to eventually disagree with. It is also
 * the rarest action in the game; there is nothing to optimise.
 */
async function disconnect(): Promise<void> {
  if (leaving.value) return

  leaving.value = true

  try {
    await logout()
  } finally {
    window.location.reload()
  }
}

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
 * carrying the tool, the tree and any draft. The tool's own printed value is
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
      // §7.4.3 -- the piece's ceiling, which a Smith's node can raise above
      // the recipe's.
      maxDurability: tool?.maxDurability || (def?.maxDurability ?? 1),
      wear: tool ? (tool.durability / Math.max(1, tool.maxDurability || (def!.maxDurability ?? 1))) * 100 : 0,
      broken: tool ? tool.durability <= 0 : false,
      yield: game.toolYield?.[skill.key] ?? 0,
    }
  }),
)

/**
 * §8.1 rule 1 -- the load-bearing number on this page.
 *
 * Not a bare percentage but a percentage *of a ceiling*, because the ceiling is
 * the rule: gear, a bought tree and a draft are three roads to the same +15%
 * and none of them passes it. A meter says how much of that road is walked far
 * better than a figure a player has to hold 0.15 in their head to read.
 *
 * Yield is missing on purpose -- §8 makes it a number per line, not one number,
 * and it is on the lines below.
 */
const CEILING = EQUIPMENT.statCeiling

/** §9.5.4 -- the flat pair and the pool, off the state rather than a preview. */
const combat = computed(
  () => game.state?.combat ?? { attack: 0, defense: 0, pool: 0, job: null, jobLevel: 0 },
)

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
        Gear, a skill tree and a draft all feed this one sum and stop at the
        same roof. A second item of the same kind is worth
        ×{{ EQUIPMENT.stackFalloff }} the first — buying three of a thing does
        not make you three times better.
      </p>
    </section>

    <!-- ------------------------------------------------------- the fight -->
    <!-- §9.5.4 -- solid numbers, and they get their own block for exactly that
         reason: they are not percentages and they do not climb toward the roof
         above. §9.5.5 makes the pool the health bar, so it belongs beside them
         rather than buried on an item. -->
    <section class="inset">
      <div class="row-between" style="margin-bottom: 10px">
        <h3 class="head">In a fight</h3>
        <span v-if="combat.job" class="tiny mono muted">
          {{ combat.job }} {{ combat.jobLevel }}
        </span>
      </div>

      <div class="pairs">
        <div class="pair">
          <span class="label">Attack</span>
          <strong class="mono reading">{{ combat.attack }}</strong>
        </div>
        <div class="pair">
          <span class="label">Defense</span>
          <strong class="mono reading">{{ combat.defense }}</strong>
        </div>
        <div class="pair">
          <span class="label">Pool</span>
          <strong class="mono reading" :class="{ maxed: combat.pool > 0 }">{{ combat.pool }}</strong>
        </div>
      </div>

      <p class="tiny muted note">
        Solid numbers, not percentages — a ±{{ formatPercent(CEILING) }} swing
        cannot decide a fight, so these are the base it is decided on. The pool
        is the durability of the weapon and the worn set, and it is your health:
        what a fight takes off it comes off the gear.
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

            <!-- §8.2 -- what the mend takes, before the button is pressed. It
                 is the largest continuous sink in the game (§11.1), so it is
                 the decision rather than a footnote to it. -->
            <RepairCost v-if="line.tool" :item="line.tool" />
          </template>

          <!-- Empty reads as the slot it is waiting for, the same way the worn
               list below does. What a bare line costs you is the hex card's
               answer, and its button already gives it. -->
          <div v-else class="tiny muted">{{ line.label }}</div>
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
            <template #cost><RepairCost :item="equipped[slot.key]!" /></template>
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
        <!-- §7 -- the name, and the one control that changes it. Above the
             wallet because it is the half a player chose. -->
        <div class="row-between tiny name-row">
          <span class="muted">Name</span>
          <span v-if="!naming" class="named">
            <strong :class="{ unnamed: !game.character.named }">{{ game.character.name }}</strong>
            <button class="btn btn-sm" type="button" @click="startNaming">
              {{ game.character.named ? 'Change' : 'Take a name' }}
            </button>
          </span>
        </div>

        <form v-if="naming" class="naming" @submit.prevent="claimName">
          <div class="row" style="gap: 7px">
            <input
              v-model="draft"
              class="field"
              type="text"
              :maxlength="CHARACTER.nameMax"
              autocapitalize="off"
              autocomplete="off"
              spellcheck="false"
              placeholder="Letters and digits"
              aria-label="New name"
            />
            <button class="btn btn-sm btn-primary" type="submit" :disabled="Boolean(nameProblem) || game.busy">
              Claim
            </button>
            <button class="btn btn-sm" type="button" @click="naming = false">Cancel</button>
          </div>
          <!-- One line, and it holds whichever objection applies: this screen's
               own, or the server's when it refused something this could not
               know -- that somebody already goes by it. -->
          <span class="tiny" :class="nameProblem && draft ? 'bad' : 'muted'">
            {{ (draft && nameProblem) || 'No two prospectors may hold the same name.' }}
          </span>
        </form>

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

      <!-- The character is soulbound and stays where it is; what ends here is
           the session holding it. Saying what coming back costs is the point —
           a login is a payment (§2), so leaving is not free to undo. -->
      <div class="leave">
        <button class="btn btn-sm" type="button" :disabled="leaving" @click="disconnect">
          {{ leaving ? 'Disconnecting…' : 'Disconnect wallet' }}
        </button>
        <span class="tiny muted">
          Your character stays with the wallet. Signing back in means another
          transfer.
        </span>
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

.name-row {
  min-height: 24px;
}

.named {
  display: flex;
  align-items: center;
  gap: 9px;
}

/* The label rather than a name, so it is drawn as one: this is the state of
   not having chosen, not a quiet choice. */
.unnamed {
  color: var(--vellum-dim);
  font-style: italic;
}

.naming {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 2px;
}

.field {
  flex: 1;
  min-width: 0;
  padding: 6px 9px;
  border: 1px solid var(--line);
  background: var(--ink);
  color: var(--vellum);
  font: inherit;
}

.field:focus {
  outline: none;
  border-color: var(--copper);
}

/* §13.3 -- ember is a state to deal with, and a refused name is one. */
.bad {
  color: var(--ember);
}

/* The button first and the consequence beside it, so the sentence explaining
   the cost is read in the same glance as the control that charges it. */
.leave {
  display: flex;
  align-items: center;
  gap: 11px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.leave span {
  flex: 1 1 190px;
  line-height: 1.45;
}

/* One meter per stat. Stacked rather than side by side: the bar is the reading,
   and a bar too short to see is no reading at all. */
.pairs {
  display: flex;
  gap: 8px;
}

.pair {
  flex: 1 1 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 7px 9px;
  background: rgba(0, 0, 0, 0.28);
}

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
