<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { placeLabel } from '@/game/formulas'
import { hexDistance } from '@/map/hexGeometry'
import FlagCanvas from '@/components/FlagCanvas.vue'
import FlagEditor from '@/components/FlagEditor.vue'
import type { GuildDoor, GuildRole } from '@/api/types'

const game = useGame()

const directory = computed(() => game.guilds)
const cost = computed(() => directory.value?.cost ?? 20000)
const applied = computed(() => directory.value?.applied ?? [])
const mine = computed(() => directory.value?.mine ?? null)

const here = computed(() => game.currentSettlement)

const distanceTo = (col: number, row: number) => {
  const me = game.character
  if (!me) return null

  return hexDistance(me.col, me.row, col, row)
}

const myRole = computed<GuildRole | null>(() => {
  const me = game.character
  if (!mine.value || !me) return null

  return mine.value.roster.find((r) => r.characterId === String(me.id))?.role ?? null
})

const isOwner = computed(() => myRole.value === 'owner')
const isOfficer = computed(() => myRole.value === 'owner' || myRole.value === 'officer')

const DOORS: Array<{ key: GuildDoor; label: string; note: string }> = [
  { key: 'closed', label: 'Closed', note: 'Not listed. Nobody gets in.' },
  { key: 'open', label: 'Open', note: 'Listed, and walking in is enough.' },
  { key: 'approval', label: 'Approval', note: 'Listed, and you decide who comes through.' },
]

/* ---------------------------------------------------------------- founding */

const founding = ref(false)
const name = ref('')
const code = ref('')
const description = ref('')
const flag = ref<string | null>(null)

const canAfford = computed(() => (game.character?.gold ?? 0) >= cost.value)

/**
 * §10.0 -- why founding is not on offer, or null when it is.
 *
 * The hard requirements only: somewhere a hall can stand, no guild already,
 * and the gold. None of them is something the form can help with, which is why
 * they gate the form rather than sit inside it.
 */
const cannotFound = computed(() => {
  if (game.guild) return `You are already one of ${game.guild.name}.`
  if (here.value?.tier !== 'city' && here.value?.tier !== 'capital') {
    return 'A hall stands in a city or a capital.'
  }
  if (!canAfford.value) {
    const short = cost.value - (game.character?.gold ?? 0)
    return `${short} gold short of the ${cost.value} a hall costs.`
  }

  return null
})

/** And why THIS one is not finished, which is a different question. */
const foundingReason = computed(() => {
  if (cannotFound.value) return cannotFound.value
  if (name.value.trim().length < 3) return 'It needs a name.'
  if (!/^[A-Za-z0-9]{2,5}$/.test(code.value.trim())) return 'A code is 2 to 5 letters or digits.'

  return null
})

async function found(): Promise<void> {
  if (foundingReason.value) return

  const ok = await game.foundGuild({
    name: name.value.trim(),
    code: code.value.trim().toUpperCase(),
    description: description.value.trim(),
    flag: flag.value,
  })

  if (ok) {
    founding.value = false
    game.closeHalls()
  }
}

/* ---------------------------------------------------------------- treasury */

const donation = ref<number | null>(null)

const purse = computed(() => game.character?.gold ?? 0)

const canDonate = computed(() => {
  const gold = donation.value ?? 0

  return gold >= 1 && gold <= purse.value
})

async function donate(): Promise<void> {
  if (!canDonate.value) return

  await game.donateToGuild(donation.value ?? 0)
  donation.value = null
}

const HALL_MAX_LEVEL = 5

/**
 * §10.5 -- the two facilities, as rows.
 *
 * Both read the same way: what it stands at now, what level that is, and what
 * the next one costs the treasury. A finished facility says so rather than
 * offering a button that would refuse.
 */
const facilities = computed(() => {
  const g = mine.value
  if (!g) return []

  return [
    {
      key: 'hall' as const,
      name: 'Hall',
      what: 'How many the roster seats.',
      level: g.hallLevel,
      max: HALL_MAX_LEVEL,
      cost: g.hallCost,
      standing: `Seats ${g.rosterCap}`,
    },
    {
      key: 'bench' as const,
      name: 'Bench',
      what: 'How far up §8.0 it makes. Legendary is here and nowhere else.',
      level: g.benchLevel,
      max: g.benchMaxLevel,
      cost: g.benchCost,
      standing: `Reaches ${g.benchReach}`,
    },
  ]
})

/* ----------------------------------------------------------------- editing */

const editing = ref(false)
const draftDescription = ref('')
const draftFlag = ref<string | null>(null)

watch(editing, (on) => {
  if (!on || !mine.value) return

  draftDescription.value = mine.value.description
  draftFlag.value = mine.value.flag
})

async function saveIdentity(): Promise<void> {
  await game.updateGuild({ description: draftDescription.value, flag: draftFlag.value })
  editing.value = false
}
</script>

<template>
  <div class="page">
    <p v-if="!directory" class="tiny muted nothing">Asking around…</p>

    <!-- ================================================ running your own -->
    <template v-else-if="mine">
      <div class="inset banner">
        <FlagCanvas :flag="mine.flag" :size="60" />
        <span class="grow">
          <span class="row" style="gap: 6px; align-items: baseline">
            <strong class="tiny">{{ mine.name }}</strong>
            <span class="tag readout">{{ mine.code }}</span>
          </span>
          <span class="tiny muted block">
            Hall at {{ placeLabel(mine.settlementName, mine.col, mine.row) }}
            <template v-if="distanceTo(mine.col, mine.row)">
              · {{ distanceTo(mine.col, mine.row) }} hexes
            </template>
          </span>
          <span v-if="game.atGuildHall" class="tiny block open">
            You are standing in it — the legendary bench is open.
          </span>
        </span>
      </div>

      <!-- ------------------------------------------------------ identity -->
      <div v-if="editing" class="inset editor">
        <span class="label">The flag</span>
        <FlagEditor v-model="draftFlag" />

        <label class="label" for="guild-desc">What the guild is for</label>
        <textarea id="guild-desc" v-model="draftDescription" class="field" rows="3" maxlength="500" />

        <div class="row-between">
          <button class="btn btn-sm" type="button" @click="editing = false">Leave it</button>
          <button
            class="btn btn-sm btn-primary"
            type="button"
            :disabled="game.busy"
            @click="saveIdentity"
          >
            Save
          </button>
        </div>
      </div>

      <!-- §10.5 -- the treasury, and the two things it buys. -->
      <div class="inset treasury">
        <div class="row-between">
          <span class="label">Treasury</span>
          <strong class="readout pot">{{ mine.gold }}g</strong>
        </div>

        <div class="pair">
          <input
            v-model.number="donation"
            class="field grow"
            type="number"
            min="1"
            :max="purse"
            placeholder="Gold to put in"
          />
          <button
            class="btn btn-sm btn-primary"
            type="button"
            :disabled="!canDonate || game.busy"
            @click="donate"
          >
            Donate
          </button>
        </div>

        <p class="tiny muted note">
          You have {{ purse }}g. What goes in does not come back out — it buys
          facilities, and a facility is the whole roster's.
        </p>
      </div>

      <div v-for="f in facilities" :key="f.key" class="inset facility">
        <span class="grow">
          <strong class="tiny">{{ f.name }}</strong>
          <span class="tiny muted block">
            {{ f.standing }} · level {{ f.level }} of {{ f.max }}
          </span>
          <span class="tiny muted block">{{ f.what }}</span>
        </span>

        <button
          v-if="isOwner && f.cost !== null"
          class="btn btn-sm"
          :class="{ 'btn-primary': mine.gold >= f.cost }"
          type="button"
          :disabled="game.busy || mine.gold < f.cost"
          @click="game.upgradeGuildFacility(f.key)"
        >
          {{ f.cost }}g
        </button>
        <span v-else-if="f.cost === null" class="tiny open">Built out</span>
      </div>

      <!-- §10.0.4 -- who is asking is READ, so it stays in the corner panel.
           What is here is the ranks, which are a decision about the hall. -->
      <div class="row-between head-row">
        <h3 class="head">Roster</h3>
        <span class="tally" :class="{ ready: isOfficer && mine.applications.length > 0 }">
          {{ mine.roster.length }}
        </span>
      </div>

      <p v-if="isOfficer && mine.applications.length" class="tiny note asking">
        {{ mine.applications.length }} asking to join — the Requests tab, guild
        screen.
      </p>

      <div v-for="row in mine.roster" :key="row.characterId" class="inset member">
        <span class="grow">
          <strong class="tiny">{{ row.name }}</strong>
          <span class="tiny muted block">
            level {{ row.level }} · {{ row.role }} · put in {{ row.donated }}g
          </span>
        </span>

        <template v-if="isOwner && row.role !== 'owner'">
          <button
            class="btn btn-sm"
            type="button"
            :disabled="game.busy"
            @click="game.setGuildRole(row.characterId, row.role === 'officer' ? 'member' : 'officer')"
          >
            {{ row.role === 'officer' ? 'Demote' : 'Officer' }}
          </button>
          <button
            class="btn btn-sm"
            type="button"
            :disabled="game.busy"
            title="Hands the guild over. You become an officer."
            @click="game.setGuildRole(row.characterId, 'owner')"
          >
            Hand over
          </button>
        </template>

        <button
          v-if="isOfficer && row.characterId !== String(game.character?.id) && row.role !== 'owner'"
          class="btn btn-sm btn-danger"
          type="button"
          :disabled="game.busy"
          @click="game.removeGuildMember(row.characterId)"
        >
          Remove
        </button>
      </div>

      <!-- ---------------------------------------------------------- door -->
      <div class="inset door">
        <span class="label">The door</span>
        <div v-if="isOfficer" class="doors">
          <button
            v-for="option in DOORS"
            :key="option.key"
            type="button"
            class="btn btn-sm"
            :class="{ 'btn-primary': mine.recruitment === option.key }"
            :disabled="game.busy"
            @click="game.updateGuild({ recruitment: option.key })"
          >
            {{ option.label }}
          </button>
        </div>
        <p class="tiny muted note">
          {{ DOORS.find((d) => d.key === mine!.recruitment)?.note }}
        </p>
      </div>

      <div class="row-between foot">
        <button v-if="isOwner" class="btn btn-sm" type="button" @click="editing = !editing">
          {{ editing ? 'Done' : 'Edit the guild' }}
        </button>
        <span v-else />
        <button
          class="btn btn-sm btn-danger"
          type="button"
          :disabled="game.busy"
          @click="game.leaveGuild()"
        >
          {{ isOwner && mine.roster.length === 1 ? 'Disband' : 'Leave' }}
        </button>
      </div>
    </template>

    <!-- ======================================================== founding -->
    <template v-else-if="founding">
      <div class="inset editor">
        <p class="tiny muted note">
          A hall costs {{ cost }} gold of your own and stands in this settlement.
          It is the only bench in the game that reaches legendary.
        </p>

        <div class="pair">
          <span class="field-set grow">
            <label class="label" for="hall-name">Name</label>
            <input id="hall-name" v-model="name" class="field" maxlength="32" />
          </span>
          <span class="field-set code-set">
            <label class="label" for="hall-code">Code</label>
            <input id="hall-code" v-model="code" class="field" maxlength="5" />
          </span>
        </div>

        <label class="label" for="hall-desc">What it is for</label>
        <textarea id="hall-desc" v-model="description" class="field" rows="2" maxlength="500" />

        <span class="label">The flag</span>
        <FlagEditor v-model="flag" />

        <p v-if="foundingReason" class="tiny warn note">{{ foundingReason }}</p>

        <div class="row-between">
          <button class="btn btn-sm" type="button" @click="founding = false">Never mind</button>
          <button
            class="btn btn-sm btn-primary"
            type="button"
            :disabled="Boolean(foundingReason) || game.busy"
            @click="found"
          >
            Found it — {{ cost }}g
          </button>
        </div>
      </div>
    </template>

    <!-- ==================================================== looking around -->
    <template v-else>
      <div class="row-between head-row">
        <h3 class="head">Taking people on</h3>
        <!-- §10.0 -- offered only where it can be done. What stops it is a
             village underfoot or an empty purse, and neither is fixed here. -->
        <button
          v-if="!cannotFound"
          class="btn btn-sm btn-primary"
          type="button"
          @click="founding = true"
        >
          Found one
        </button>
      </div>

      <p v-if="cannotFound" class="tiny muted note gate">{{ cannotFound }}</p>

      <div v-for="g in directory.guilds" :key="g.id" class="inset guild">
        <FlagCanvas :flag="g.flag" :size="48" />
        <span class="grow">
          <span class="row" style="gap: 6px; align-items: baseline">
            <strong class="tiny">{{ g.name }}</strong>
            <span class="tag readout">{{ g.code }}</span>
          </span>
          <span class="tiny muted block">{{ g.description || 'No word on what they do.' }}</span>
          <span class="tiny muted block">
            {{ g.members }} in it · hall at {{ placeLabel(g.settlementName, g.col, g.row) }}
            <template v-if="distanceTo(g.col, g.row)">
              · {{ distanceTo(g.col, g.row) }} hexes
            </template>
          </span>
        </span>

        <!-- §10.0.1 -- the door says which word goes on the button, before it
             is pressed rather than after. -->
        <button
          v-if="applied.includes(g.id)"
          class="btn btn-sm"
          type="button"
          :disabled="game.busy"
          @click="game.withdrawApplication(g.id)"
        >
          Asked
        </button>
        <button
          v-else
          class="btn btn-sm btn-primary"
          type="button"
          :disabled="game.busy"
          @click="game.joinGuild(g.id)"
        >
          {{ g.recruitment === 'approval' ? 'Ask' : 'Join' }}
        </button>
      </div>

      <p v-if="!directory.guilds.length" class="tiny muted hint">
        Nobody is recruiting.
        <template v-if="cannotFound">Founding one needs a city and {{ cost }} gold.</template>
        <template v-else>Found one yourself.</template>
      </p>
    </template>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.nothing,
.hint {
  margin: 12px 0 0;
  text-align: center;
  line-height: 1.5;
}

.banner,
.guild {
  display: flex;
  align-items: center;
  gap: 10px;
}

.block {
  display: block;
}

.open {
  color: var(--sap);
}

.tag {
  font-size: 11px;
  padding: 1px 5px;
  background: rgba(0, 0, 0, 0.4);
  color: var(--copper);
}

.note {
  margin: 0;
  line-height: 1.5;
}

.gate {
  padding: 7px 9px;
  border-left: 2px solid var(--line);
}

.warn {
  color: var(--ember);
}

.editor {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.head-row {
  margin-top: 2px;
}

.tally {
  font-family: var(--font-display);
  font-size: 11px;
  padding: 1px 6px;
  background: rgba(0, 0, 0, 0.4);
  color: var(--vellum-dim);
}

.tally.ready {
  background: var(--sap);
  color: var(--ink);
}

.treasury,
.facility {
  display: flex;
  gap: 8px;
}

.treasury {
  flex-direction: column;
}

.facility {
  align-items: center;
}

.pot {
  font-family: var(--font-display);
  font-size: 15px;
  color: var(--gold);
}

.asking {
  padding: 7px 9px;
  border-left: 2px solid var(--sap);
  color: var(--sap);
}

.member {
  display: flex;
  align-items: center;
  gap: 9px;
}

.door {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.doors {
  display: flex;
  gap: 6px;
}

.field {
  width: 100%;
  padding: 6px 8px;
  font: inherit;
  font-size: 12px;
  color: var(--vellum);
  background: rgba(0, 0, 0, 0.3);
  border: 1px solid var(--line);
}

.field-set {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.pair {
  display: flex;
  gap: 8px;
}

.code-set {
  width: 84px;
  flex: 0 0 auto;
}

.foot {
  margin-top: 4px;
}
</style>
