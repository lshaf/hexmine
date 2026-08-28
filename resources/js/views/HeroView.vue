<script setup lang="ts">
/**
 * The prospector sheet, §7 and §8.
 *
 * **It is a condition read-out, not an inventory.** Nine slots -- the five
 * gathering lines and the four worn -- drawn as nine hexagons standing on one
 * baseline, each with a rail beside it. What a player opens this screen to find
 * out is which piece is about to break, and nine rails in a row answer that
 * before a word is read. Names are gone: §13.1 already puts the slot in the
 * silhouette and the rung in the colour, so a name spent a whole row saying
 * what the icon had said. What a piece IS lives one tap deeper.
 *
 * **The stowed list is gone too, and is not lost.** An unworn axe is filed
 * behind the axe slot, which is where somebody looking for it would look --
 * so tapping a slot says what is in your hand AND what else you have for it,
 * with the Equip button on the row it belongs to. A flat list of everything
 * unworn was a second place to keep gear, ordered by nothing.
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
  RARITY_LABEL,
  SKILL_BY_KEY,
  SKILL_LIST,
  SLOT_LABEL,
  STAT_LABEL,
  slotForSkill,
} from '@/game/catalog'
import { formatPercent, formatStat, optionStatLine, swapChanges } from '@/game/formulas'
import { CHARACTER, EQUIPMENT } from '@/game/balance'
import { itemIcon, skillIcon } from '@/icons/procedural'
import GearCell from '@/components/GearCell.vue'
import RepairCost from '@/components/RepairCost.vue'
import GearAction from '@/components/GearAction.vue'
import StatChips from '@/components/StatChips.vue'
import SwapMoves from '@/components/SwapMoves.vue'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipSlot, ItemDef, OwnedItem, SkillKey, StatKey } from '@/game/types'

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
 * §8 -- the four worn slots, in the order a set is put on.
 */
const WORN: EquipSlot[] = ['armor', 'boots', 'gloves', 'weapon']

const equipped = computed(() => {
  const map = {} as Record<EquipSlot, OwnedItem | undefined>
  for (const item of game.equipment) {
    if (!item.equipped) continue
    const def = ITEM_BY_KEY[item.key]
    if (def?.slot) map[def.slot] = item
  }
  return map
})

/** A dashed hexagon: the shape of the hole rather than a picture of nothing. */
const EMPTY_HEX =
  '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" ' +
  'stroke-width="1.1" stroke-dasharray="3 2.6" stroke-linejoin="round" aria-hidden="true">' +
  '<path d="M23 12 17.5 21.5h-11L1 12l5.5-9.5h11Z" /></svg>'

interface KitSlot {
  key: string
  slot: EquipSlot
  /** The gathering line this slot works, or null for worn gear (§8 rule 1). */
  line: SkillKey | null
  /** Said on hover and to a reader. Nothing on the rack is said on screen. */
  label: string
  item: OwnedItem | null
  def: ItemDef | null
  icon: string | null
  fallback: string
}

function cell(slot: EquipSlot, line: SkillKey | null, what: string): KitSlot {
  const item = equipped.value[slot] ?? null
  const def = item ? (ITEM_BY_KEY[item.key] ?? null) : null

  return {
    key: slot,
    slot,
    line,
    label: def ? `${what} — ${def.name}` : `${what} — nothing equipped`,
    item,
    def,
    // §13.1 -- the real thing, drawn from its own slot, rarity and material.
    icon: def
      ? itemIcon({ slot: def.slot, family: def.family, rarity: def.rarity, palette: def.palette, size: 30 })
      : null,
    fallback: line ? skillIcon(line, 24) : EMPTY_HEX,
  }
}

/**
 * §8 rule 1 -- a tool pays out on its own line and nowhere else, so the rack is
 * two banks rather than one row of nine. The bare slots are the useful half:
 * they are the lines you own no tool for.
 */
const toolBank = computed(() =>
  SKILL_LIST.map((skill) => cell(slotForSkill(skill.key), skill.key, skill.name)),
)

const wornBank = computed(() => WORN.map((slot) => cell(slot, null, SLOT_LABEL[slot])))

/** §8.1 rule 3 -- how much of the whole kit is still in one piece. */
const kitCondition = computed(() => {
  const all = [...toolBank.value, ...wornBank.value].filter((c) => c.item)
  if (all.length === 0) return null

  const worst = all.reduce((low, c) => {
    const ceiling = c.item!.maxDurability || (c.def?.maxDurability ?? 1)
    const fraction = c.item!.durability / Math.max(1, ceiling)

    return Math.min(low, fraction)
  }, 1)

  return { held: all.length, worst: Math.round(worst * 100) }
})

// -------------------------------------------------------------- the slot plate

/**
 * §7.6's grammar, indoors: tapping a thing opens what it is and the one or two
 * things that can be done with it. The rack has no room for a name and does not
 * want one; this is where the name, the rolled lines and the exact figure live.
 */
const pickedKey = ref<string | null>(null)

const picked = computed(
  () => [...toolBank.value, ...wornBank.value].find((c) => c.key === pickedKey.value) ?? null,
)

function close(): void {
  pickedKey.value = null
}

/**
 * What else you own for this slot. This is where the old Stowed list went: an
 * unworn axe belongs behind the axe, not in a flat pile of everything unworn.
 */
const candidates = computed(() => {
  const slot = picked.value?.slot
  if (!slot) return []

  return game.equipment
    .filter((item) => !item.equipped && ITEM_BY_KEY[item.key]?.slot === slot)
    .map((item) => {
      const def = ITEM_BY_KEY[item.key]!
      const ceiling = item.maxDurability || (def.maxDurability ?? 1)

      return {
        item,
        def,
        ceiling,
        icon: itemIcon({ slot: def.slot, family: def.family, rarity: def.rarity, palette: def.palette, size: 22 }),
        // §8 -- one item per slot, so a spare is never a question on its own:
        // it is a question about the one on the belt. The same call the bag
        // makes, so both screens answer it with one arithmetic (§8.1's falloff
        // and ceiling included, which subtracting two labels cannot see).
        changes: swapChanges(game.equipment, item, picked.value?.item ?? null),
      }
    })
})

/** The tapped piece's ceiling and how much of it is left, said exactly. */
const pickedWear = computed(() => {
  const item = picked.value?.item
  if (!item) return null

  const ceiling = item.maxDurability || (picked.value?.def?.maxDurability ?? 1)

  return { left: item.durability, ceiling, percent: (item.durability / Math.max(1, ceiling)) * 100 }
})

async function act(run: Promise<unknown>): Promise<void> {
  await run
  close()
}

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
        Gear, a tree and a draft feed one sum and stop at one roof. A second of
        a kind is worth ×{{ EQUIPMENT.stackFalloff }}.
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
        Solid numbers, not percentages. The pool is your gear — a fight's
        damage comes off it.
      </p>
    </section>

    <!-- ------------------------------------------------------------ kit -->
    <!-- The signature: nine hexagons on one baseline, each with the rail that
         says what is left of it. No names — §13.1 already puts the slot in the
         silhouette and the rung in the colour, and a name would only spend the
         width saying it again. Which piece is in trouble is the one question
         this screen exists to answer at a glance. -->
    <section class="inset">
      <div class="row-between" style="margin-bottom: 5px">
        <h3 class="head">Kit</h3>
        <span v-if="kitCondition" class="tiny mono muted">
          {{ kitCondition.held }} in hand · worst at {{ kitCondition.worst }}%
        </span>
        <span v-else class="tiny muted">Nothing equipped</span>
      </div>

      <div class="banks">
        <div class="bank">
          <span class="label bank-name">Lines</span>
          <div class="rack">
            <GearCell
              v-for="c in toolBank"
              :key="c.key"
              :item="c.item"
              :def="c.def"
              :icon="c.icon"
              :fallback="c.fallback"
              :label="c.label"
              @click="pickedKey = c.key"
            />
          </div>
        </div>

        <div class="bank">
          <span class="label bank-name">Worn</span>
          <div class="rack">
            <GearCell
              v-for="c in wornBank"
              :key="c.key"
              :item="c.item"
              :def="c.def"
              :icon="c.icon"
              :fallback="c.fallback"
              :label="c.label"
              @click="pickedKey = c.key"
            />
          </div>
        </div>
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
      </div>

      <!-- The character is soulbound and stays where it is; what ends here is
           the session holding it. Saying what coming back costs is the point —
           a login is a payment (§2), so leaving is not free to undo. -->
      <div class="leave">
        <button class="btn btn-sm" type="button" :disabled="leaving" @click="disconnect">
          {{ leaving ? 'Disconnecting…' : 'Disconnect wallet' }}
        </button>
        <span class="tiny muted">
          Soulbound. It stays with the wallet — signing back in costs another
          transfer.
        </span>
      </div>
    </section>

    <!-- ----------------------------------------------------- slot plate -->
    <!-- Teleported out of the panel for the same reason the bag's popup is:
         the panel carries a backdrop-filter, which would otherwise become the
         containing block for anything fixed inside it. -->
    <Teleport to="body">
      <div v-if="picked" class="pop-wrap" role="dialog" :aria-label="picked.label">
        <div class="pop-scrim" @click="close" />
        <div class="pop plate">
          <div class="pop-inner">
            <header class="pop-head">
              <!-- The same frame the rack draws, at the plate's size: the art
                   fills its hexagon rather than rattling inside a square box. -->
              <span class="hexicon head" :class="{ bare: !picked.icon }">
                <SvgIcon v-if="picked.icon" :svg="picked.icon" />
                <span v-else class="glyph" v-html="picked.fallback" />
              </span>
              <div class="grow">
                <strong :class="picked.def ? `rarity-${picked.def.rarity}` : 'muted'">
                  {{ picked.def?.name ?? SLOT_LABEL[picked.slot] }}
                </strong>
                <p class="tiny muted sub">
                  <template v-if="picked.def">
                    {{ SLOT_LABEL[picked.slot] }} · {{ RARITY_LABEL[picked.def.rarity] }}
                  </template>
                  <template v-else>Nothing in this slot</template>
                </p>
              </div>
              <button class="pop-close" type="button" aria-label="Close" @click="close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </button>
            </header>

            <template v-if="picked.item && picked.def && pickedWear">
              <!-- The thesis of the plate, because it is what the rack was
                   pointing at: the same rail, big enough to carry the figure. -->
              <div class="detail">
                <div class="gauge">
                  <span class="rail">
                    <span class="lit" :style="{ height: `${pickedWear.percent}%` }" />
                  </span>
                  <span class="figure">
                    <strong class="mono left">{{ pickedWear.left }}</strong>
                    <span class="tiny muted">of {{ pickedWear.ceiling }}</span>
                  </span>
                </div>

                <div class="grow facts">
                  <StatChips :def="picked.def" :options="picked.item.options ?? []" />

                  <!-- §8 rule 1 -- what this line is actually worth, and the
                       only per-line figure on the sheet. It belongs with the
                       tool that produces it rather than in a table of five. -->
                  <p v-if="picked.line" class="tiny line-yield">
                    <span class="muted">On {{ SKILL_BY_KEY[picked.line].name.toLowerCase() }}</span>
                    <strong class="mono" :class="{ none: !game.toolYield?.[picked.line] }">
                      {{ formatPercent(game.toolYield?.[picked.line] ?? 0) }} yield
                    </strong>
                  </p>

                  <!-- §8.0.1 -- the rolled lines, said as rolled. Nothing off a
                       shelf ever has one, so the word is the whole distinction. -->
                  <div v-if="picked.item.options?.length" class="rolled">
                    <span class="tiny muted rolled-label">rolled</span>
                    <span v-for="(o, i) in picked.item.options" :key="i" class="tiny mono roll">
                      {{ optionStatLine(o, picked.def) }}
                    </span>
                  </div>
                </div>
              </div>

              <p v-if="picked.item.durability <= 0" class="tiny broken">
                Broken — this slot is paying nothing until it is mended.
              </p>

              <RepairCost :item="picked.item" />

              <div class="acts">
                <GearAction
                  action="repair"
                  label="Repair"
                  wide
                  :disabled="game.busy"
                  @click="act(game.repair(picked.item.id))"
                />
                <GearAction
                  action="stow"
                  label="Stow"
                  wide
                  :disabled="game.busy"
                  @click="act(game.unequip(picked.item.id))"
                />
              </div>
            </template>

            <!-- Where the Stowed list went. What you own for this slot, filed
                 behind the slot, with the button on the row it belongs to. -->
            <div v-if="candidates.length" class="pack">
              <span class="label pack-name">
                {{ picked.item ? 'Also for this slot' : 'In the pack' }}
              </span>
              <div v-for="c in candidates" :key="c.item.id" class="spare">
                <span class="hexicon spare-icon"><SvgIcon :svg="c.icon" /></span>
                <div class="grow">
                  <div class="named">
                    <strong class="tiny" :class="`rarity-${c.def.rarity}`">{{ c.def.name }}</strong>
                    <span class="tiny mono muted">{{ c.item.durability }}/{{ c.ceiling }}</span>
                  </div>
                  <!-- The whole reason this row is here: not what the spare is
                       worth, but what swapping to it buys. The durability above
                       is the rest of the trade. -->
                  <SwapMoves
                    :changes="c.changes"
                    :same="picked.item ? 'Same stats as the one on the belt.' : 'No stats to speak of.'"
                  />
                </div>
                <!-- §8.2 -- a broken spare cannot be put on, so the button
                     that would refuse becomes the button that fixes it. One
                     action per row, and it is never the one that does nothing. -->
                <GearAction
                  v-if="c.item.durability <= 0"
                  action="repair"
                  label="Repair"
                  :disabled="game.busy"
                  @click="act(game.repair(c.item.id))"
                />
                <GearAction
                  v-else
                  action="equip"
                  label="Equip"
                  :disabled="game.busy"
                  @click="act(game.equip(c.item.id))"
                />
              </div>
            </div>

            <p v-else-if="!picked.item" class="tiny muted empty-note">
              Nothing in the pack fits here. A bare line still works — it pays
              the bare-handed rate and brings back scrap.
            </p>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- One pointer, no figures. Levels, points and trees are the Jobs sheet's
         and are not repeated here. -->
    <p class="tiny muted footnote">
      Levels, points and the trees they open live in the Jobs sheet.
    </p>
  </div>
</template>

<style scoped>
.page {
  /* Sizing and scrolling belong to PanelOverlay. */
  padding: 0;
}

.section {
  margin-top: 10px;
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
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 16px;
}

/* One column on a phone: three meters at a third of 340px cannot carry both a
   label and a reading on one line, and a wrapped label is worse than a taller
   block. */
@media (max-width: 480px) {
  .meters {
    grid-template-columns: 1fr;
  }
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

/* ------------------------------------------------------------------- kit */

/*
 * Two banks, not one row of nine. The split is real -- five slots belong to
 * ground and four belong to your body (§8 rule 1) -- and it is also what lets
 * the rack wrap onto a phone without the rails going ragged.
 */
.banks {
  display: flex;
  flex-wrap: wrap;
  /* Wide enough to clear the icons' overflow: the art is scaled so its hexagon
     lands on the cell, and its square viewBox reaches past that by a few
     pixels, which is what put the WORN label against the row above it. */
  gap: 17px 22px;
}

/* The icon overflows its own box by a few pixels -- it is scaled so its hexagon
   lands on the cell, and its square viewBox is wider than that. The label needs
   clearance from the spill, not from the box. */
.bank {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.bank-name {
  color: #7d8b81;
}

/* The baseline. Every cell is the same height and every gauge starts at the
   same y, which is what makes nine of them a chart rather than nine badges.
   Wrapping is the safety net, not the plan: five on one line is the reading,
   and a bank broken over two lines has lost most of what it was for. */
.rack {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 13px;
  /* The hexagon's WIDTH, which is what every other measurement in the cell is
     derived from -- see GearCell. */
  --cell: 58px;
}

@media (max-width: 560px) {
  .rack {
    --cell: 44px;
    gap: 8px 7px;
  }
}

.broken {
  margin: 8px 0 0;
  color: var(--ember);
}

/* -------------------------------------------------------------- slot plate */

.pop-wrap {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: grid;
  place-items: center;
  padding: 18px;
}

.pop-scrim {
  position: absolute;
  inset: 0;
  background: rgba(8, 11, 10, 0.55);
}

.pop {
  position: relative;
  width: min(340px, 100%);
}

.pop-inner {
  padding: 13px 14px 14px;
}

.pop-head {
  display: flex;
  align-items: center;
  gap: 11px;
}

/*
 * §13.1's frame indoors, and the same three numbers GearCell derives:
 *   height = 0.866 x width   the flats of a regular hexagon, which is what
 *                            `--hex-clip` cuts only when the box is that shape
 *   art    = 1.075 x width   the square viewBox whose inscribed hexagon is
 *                            exactly `--hex` across the points
 *
 * A square box with the clip on it is a hexagon stretched 15% tall, and an icon
 * set to some smaller pixel size rattles around inside it. Both were true here.
 */
.hexicon {
  position: relative;
  flex: 0 0 auto;
  width: var(--hex);
  height: calc(var(--hex) * 0.866);
}

/* The ground sits BEHIND the art rather than clipping it: item art is drawn to
   spill past its own frame, and a clip on the box shears the blades off flat. */
.hexicon::before {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.32);
  clip-path: var(--hex-clip);
}

/* Centred by transform, because `place-items: center` start-aligns an item
   LARGER than its track instead of centring it. */
.hexicon :deep(.svg-icon) {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  display: block;
  width: calc(var(--hex) * 1.075);
  height: calc(var(--hex) * 1.075);
}

.hexicon :deep(.svg-icon svg) {
  display: block;
  width: 100%;
  height: 100%;
}

/* A bare slot's glyph is smaller than its frame, so alignment centres it fine. */
.hexicon.bare {
  display: grid;
  place-items: center;
}

.hexicon.head {
  --hex: 52px;
}

.hexicon.spare-icon {
  --hex: 34px;
}

.pop-head strong {
  font-size: 14px;
}

.glyph {
  display: grid;
  place-items: center;
  color: var(--copper);
}

.sub {
  margin: 2px 0 0;
}

.pop-close {
  align-self: flex-start;
  padding: 0;
  border: 0;
  background: none;
  color: var(--vellum-dim);
  cursor: pointer;
}

.pop-close:hover {
  color: var(--vellum);
}

/* The rack pointed at condition, so condition is what the plate opens with --
   the same rail, tall enough to carry the figure beside it. */
.detail {
  display: flex;
  align-items: stretch;
  gap: 12px;
  margin-top: 12px;
}

.gauge {
  display: flex;
  align-items: stretch;
  gap: 8px;
  flex: 0 0 auto;
}

/* The same scale the rack draws, straightened. The chevron on a cell follows
   the hexagon it belongs to; there is no hexagon here for it to follow, so it
   is a bar -- but it is the SAME bar, fixed sap at the top through gold to
   ember at the foot, with the bottom of it lit. Two grammars for one reading
   would make the plate a second opinion about the cell you tapped. */
.gauge .rail {
  position: relative;
  width: 6px;
  height: 58px;
  background: #2a352e;
}

.gauge .lit {
  position: absolute;
  inset: auto 0 0 0;
  background-image: linear-gradient(to top, var(--ember), var(--gold), var(--sap));
  background-size: 100% 58px;
  background-position: 0 100%;
  background-repeat: no-repeat;
}

.figure {
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.left {
  font-family: var(--font-display);
  font-size: 21px;
  line-height: 1;
}

.facts {
  display: flex;
  flex-direction: column;
  gap: 6px;
  justify-content: center;
  min-width: 0;
}

.line-yield {
  display: flex;
  gap: 6px;
  margin: 0;
}

.line-yield strong {
  color: var(--gold);
}

/* §7.3 -- a tool has no percentage at all, so an unbuilt line honestly reads
   +0%. Gold on a zero would make nothing look like something. */
.line-yield strong.none {
  color: var(--vellum-dim);
}

.rolled {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}

.rolled-label {
  letter-spacing: 0.14em;
  text-transform: uppercase;
  font-size: 8.5px;
}

.roll {
  padding: 1px 6px;
  border-radius: var(--radius-sm);
  background: #1c2519;
  color: #b7d6a4;
}

.acts {
  display: flex;
  gap: 7px;
  margin-top: 12px;
}

/* Where the stowed list went: behind the slot it belongs to. */
.pack {
  margin-top: 13px;
  padding-top: 11px;
  border-top: 1px solid var(--line);
}

.pack-name {
  display: block;
  margin-bottom: 7px;
  color: #7d8b81;
}

.spare {
  display: flex;
  align-items: center;
  gap: 9px;
}

/* The name and what is left of it are one line; what the swap moves is the
   next. Two facts about the same trade, in the order they are asked. */
.spare .grow {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
}

.named {
  display: flex;
  align-items: baseline;
  gap: 7px;
  min-width: 0;
}

.spare + .spare {
  margin-top: 6px;
}

.empty-note {
  margin: 12px 0 0;
  line-height: 1.5;
}

.wallet {
  overflow-wrap: anywhere;
  text-align: right;
}

.footnote {
  margin: 6px 0 0;
  line-height: 1.5;
}
</style>
