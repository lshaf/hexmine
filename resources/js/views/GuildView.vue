<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { placeLabel } from '@/game/formulas'
import { hexDistance } from '@/map/hexGeometry'
import FlagCanvas from '@/components/FlagCanvas.vue'
import type { GuildRole } from '@/api/types'

const game = useGame()

const mine = computed(() => game.guilds?.mine ?? null)
const applied = computed(() => game.guilds?.applied ?? [])
const applications = computed(() => mine.value?.applications ?? [])

const myRole = computed<GuildRole | null>(() => {
  const me = game.character
  if (!mine.value || !me) return null

  return mine.value.roster.find((r) => r.characterId === String(me.id))?.role ?? null
})

const isOfficer = computed(() => myRole.value === 'owner' || myRole.value === 'officer')

const tab = ref<'roster' | 'requests'>('roster')

watch(isOfficer, (on) => {
  if (!on) tab.value = 'roster'
})

const distanceTo = (col: number, row: number) => {
  const me = game.character
  if (!me) return null

  return hexDistance(me.col, me.row, col, row)
}

onMounted(() => {
  void game.loadGuilds()
})
</script>

<template>
  <div class="page">
    <p v-if="!game.guilds" class="tiny muted nothing">Asking around…</p>

    <template v-else-if="mine">
      <div class="inset banner">
        <FlagCanvas :flag="mine.flag" :size="76" />
        <div class="grow ident">
          <div class="row" style="gap: 7px; align-items: baseline">
            <strong class="name">{{ mine.name }}</strong>
            <span class="tag readout">{{ mine.code }}</span>
          </div>
          <p class="tiny muted note">{{ mine.description || 'Nothing written down yet.' }}</p>
        </div>
      </div>

      <div class="inset hall-row">
        <span class="label">The hall</span>
        <strong class="tiny">{{ placeLabel(mine.settlementName, mine.col, mine.row) }}</strong>
        <span class="tiny muted">
          <template v-if="distanceTo(mine.col, mine.row)">
            {{ distanceTo(mine.col, mine.row) }} hexes away
          </template>
          <template v-else>You are standing in it.</template>
        </span>
        <span class="tiny" :class="game.atGuildHall ? 'open' : 'muted'">
          <template v-if="game.atGuildHall">
            The legendary bench is open. Nothing else in the game reaches this rung.
          </template>
          <template v-else>Legendary work is made here and nowhere else.</template>
        </span>
      </div>

      <div v-if="isOfficer" class="tabs" role="tablist">
        <button
          type="button"
          role="tab"
          class="tab"
          :class="{ on: tab === 'roster' }"
          :aria-selected="tab === 'roster'"
          @click="tab = 'roster'"
        >
          Roster
          <span class="tally">{{ mine.roster.length }}</span>
        </button>
        <button
          type="button"
          role="tab"
          class="tab"
          :class="{ on: tab === 'requests' }"
          :aria-selected="tab === 'requests'"
          @click="tab = 'requests'"
        >
          Requests
          <span class="tally" :class="{ ready: applications.length > 0 }">
            {{ applications.length }}
          </span>
        </button>
      </div>

      <div v-else class="row-between head-row">
        <h3 class="head">Roster</h3>
        <span class="tally">{{ mine.roster.length }}</span>
      </div>

      <!-- ------------------------------------------------------- the roster -->
      <template v-if="tab === 'roster' || !isOfficer">
        <div v-for="row in mine.roster" :key="row.characterId" class="inset member">
          <span class="grow">
            <strong class="tiny">{{ row.name }}</strong>
            <span class="tiny muted block">level {{ row.level }}</span>
          </span>
          <span class="tiny muted rank">{{ row.role }}</span>
        </div>

        <p class="tiny muted hint">
          Running it — the door, the flag, the ranks, leaving — is the guild cell
          on the dock, at a city or a capital.
        </p>
      </template>

      <!-- ----------------------------------------------------- the requests -->
      <template v-else>
        <div v-for="row in applications" :key="row.characterId" class="inset member">
          <span class="grow">
            <strong class="tiny">{{ row.name }}</strong>
            <span class="tiny muted block">level {{ row.level }}</span>
          </span>
          <button
            class="btn btn-sm btn-primary"
            type="button"
            :disabled="game.busy"
            @click="game.decideApplication(row.characterId, true)"
          >
            Let in
          </button>
          <button
            class="btn btn-sm"
            type="button"
            :disabled="game.busy"
            title="They may ask again — this is a refusal, not a ban"
            @click="game.decideApplication(row.characterId, false)"
          >
            Turn away
          </button>
        </div>

        <p v-if="!applications.length" class="tiny muted hint">
          Nobody is asking.
          <template v-if="mine.recruitment === 'closed'">The door is closed.</template>
          <template v-else-if="mine.recruitment === 'open'">
            The door is open, so anybody may walk in without asking.
          </template>
        </p>
      </template>
    </template>

    <template v-else>
      <p class="tiny muted note">
        You are not in a guild. A hall is the only bench in the game that reaches
        legendary (§8.0), and it stands in a city or a capital.
      </p>

      <template v-if="applied.length">
        <div class="row-between head-row">
          <h3 class="head">Waiting on an answer</h3>
          <span class="tally">{{ applied.length }}</span>
        </div>

        <div v-for="id in applied" :key="id" class="inset member">
          <span class="grow tiny">
            {{ game.guilds.guilds.find((g) => g.id === id)?.name ?? 'A guild' }}
          </span>
        </div>
      </template>

      <p class="tiny muted hint">
        Founding one and joining one both happen at a city or a capital — the
        guild cell on the dock, once you are standing in one.
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

.banner {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.ident {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.name {
  font-family: var(--font-display);
  font-size: 16px;
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

.hall-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.open {
  color: var(--sap);
}

.head-row {
  margin-top: 2px;
}

.tabs {
  display: flex;
  gap: 6px;
  margin: 2px 0 0;
}

.tab {
  flex: 1 1 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 8px 10px;
  font-size: 12px;
  color: var(--vellum-dim);
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid transparent;
  clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
}

.tab.on {
  color: var(--vellum);
  border-color: var(--line);
  background: var(--ink-panel);
}

.tally {
  font-family: var(--font-display);
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  padding: 1px 6px;
  background: rgba(0, 0, 0, 0.4);
  color: var(--vellum-dim);
}

.tally.ready {
  background: var(--sap);
  color: var(--ink);
}

.member {
  display: flex;
  align-items: center;
  gap: 9px;
}

.block {
  display: block;
}

.rank {
  text-transform: capitalize;
}
</style>
