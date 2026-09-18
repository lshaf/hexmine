<script setup lang="ts">
/**
 * §9.6.1 -- the mouth.
 *
 * The same shape as a settlement's panel and for the same reason: §10.0.4 says
 * the verbs happen at the place, and a dungeon is a place. What it offers is a
 * contract — which dungeon is decided by which mouth you walked to, so the two
 * axes left are what you want out of it and how hard you want it.
 *
 * **The code is the other half of the door.** It lets somebody in; it does not
 * carry them here. Both halves are asked for, which is why joining has a field
 * rather than a link.
 */
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'

const props = defineProps<{ dungeon: { key: string; name: string } }>()

const game = useGame()

const CATEGORIES = [
  { key: 'tools', label: 'Tools', hint: 'the five gathering lines' },
  { key: 'armor', label: 'Armor', hint: 'coat, boots, gloves' },
  { key: 'weapons', label: 'Weapons', hint: 'shield, sword, daggers' },
] as const

const DIFFICULTIES = [
  { key: 'easy', label: 'Easy', hint: 'the roster as it stands' },
  { key: 'hard', label: 'Hard', hint: 'a tier up, and twice the top rungs' },
] as const

const category = ref<string>('tools')
const difficulty = ref<string>('easy')
const code = ref('')

/** Whatever session this prospector is already in, if any. */
const session = computed(() => game.dungeon)

/** The roster locks at the first descent, so joining has a window (§9.6.1). */
const joinable = computed(() => session.value === null)

const hereName = computed(() => props.dungeon.name)

async function open(): Promise<void> {
  await game.openDungeon(props.dungeon.key, category.value, difficulty.value)
}

async function join(): Promise<void> {
  const typed = code.value.trim().toUpperCase()
  if (typed === '') return
  await game.joinDungeon(typed)
  code.value = ''
}
</script>

<template>
  <div class="mouth">
    <!-- Already in one: what it is, and the way down. -->
    <template v-if="session">
      <div class="standing">
        <p class="label">Your session</p>
        <p class="code">{{ session.code }}</p>
        <p class="terms">
          {{ session.dungeon }} · {{ session.category }} · {{ session.difficulty }}
        </p>
        <p class="roster">
          {{ session.roster }} of 6 on the roster<span v-if="session.locked"> · closed</span>
        </p>
      </div>

      <p class="note">
        Share the code to let somebody in. They have to be standing here too, and
        the roster closes the moment anybody goes down.
      </p>

      <div class="acts">
        <button class="btn" type="button" :disabled="game.busy" @click="game.enterDungeon()">
          Go down
        </button>
        <button class="btn ghost" type="button" :disabled="game.busy" @click="game.leaveDungeon()">
          Leave
        </button>
      </div>
    </template>

    <template v-else>
      <p class="lead">
        {{ hereName }}. Ten floors, twelve hours, and whatever you carry down is
        what you have to mend with.
      </p>

      <p class="label">What you want out of it</p>
      <div class="picks">
        <button
          v-for="c in CATEGORIES"
          :key="c.key"
          class="pick"
          :class="{ on: category === c.key }"
          type="button"
          @click="category = c.key"
        >
          <span class="pick-name">{{ c.label }}</span>
          <span class="pick-hint">{{ c.hint }}</span>
        </button>
      </div>

      <p class="label">How hard</p>
      <div class="picks">
        <button
          v-for="d in DIFFICULTIES"
          :key="d.key"
          class="pick"
          :class="{ on: difficulty === d.key }"
          type="button"
          @click="difficulty = d.key"
        >
          <span class="pick-name">{{ d.label }}</span>
          <span class="pick-hint">{{ d.hint }}</span>
        </button>
      </div>

      <div class="acts">
        <button class="btn" type="button" :disabled="game.busy" @click="open">Open a session</button>
      </div>

      <div v-if="joinable" class="joining">
        <p class="label">Or join one</p>
        <div class="row">
          <input
            v-model="code"
            class="code-field"
            type="text"
            maxlength="12"
            placeholder="CODE"
            spellcheck="false"
            @keyup.enter="join"
          />
          <button class="btn ghost" type="button" :disabled="game.busy || !code.trim()" @click="join">
            Join
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.mouth {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 2px 0 4px;
}

.lead,
.note {
  margin: 0;
  color: var(--vellum-dim);
  font-size: 12px;
  line-height: 1.55;
}

.label {
  margin: 4px 0 0;
  color: var(--copper);
  font-size: 10px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
}

.picks {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

/* §13 -- a chamfer, never a rounded corner. */
.pick {
  flex: 1 1 96px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  border: 0;
  clip-path: var(--plate-clip);
  background: var(--ink-raised);
  color: var(--vellum-dim);
  text-align: left;
  cursor: pointer;
}

.pick.on {
  background: var(--line);
  color: var(--vellum);
}

.pick-name {
  font-size: 12.5px;
}

.pick-hint {
  font-size: 10px;
  color: var(--vellum-dim);
  line-height: 1.35;
}

.acts {
  display: flex;
  gap: 8px;
  margin-top: 4px;
}

.acts .btn {
  flex: 1;
  min-height: 38px;
  background: var(--copper);
  color: var(--ink);
}

.acts .ghost {
  flex: 0 0 auto;
  padding-inline: 16px;
  background: var(--ink-raised);
  color: var(--vellum);
}

.btn:disabled {
  opacity: 0.5;
  cursor: default;
}

.joining .row {
  display: flex;
  gap: 8px;
  margin-top: 5px;
}

.code-field {
  flex: 1;
  min-width: 0;
  padding: 9px 11px;
  border: 0;
  clip-path: var(--plate-clip);
  background: var(--ink-raised);
  color: var(--vellum);
  font-family: var(--font-mono, monospace);
  font-size: 14px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
}

.joining .btn {
  padding-inline: 18px;
  min-height: 0;
  background: var(--ink-raised);
  color: var(--vellum);
}

.standing {
  padding: 10px 12px;
  clip-path: var(--plate-clip);
  background: var(--ink-raised);
}

.code {
  margin: 3px 0 4px;
  font-family: var(--font-mono, monospace);
  font-size: 22px;
  letter-spacing: 0.24em;
  color: var(--gold);
}

.terms,
.roster {
  margin: 0;
  color: var(--vellum-dim);
  font-size: 11px;
}

/* The contract is three catalog keys, so it is title-cased for reading. The
   roster line beside it is a sentence and must not be: "1 Of 6 On The Roster"
   is what happens when a transform meant for one of them covers both. */
.terms {
  text-transform: capitalize;
}

.roster {
  margin-top: 3px;
}
</style>
