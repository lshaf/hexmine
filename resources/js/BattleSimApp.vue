<script setup lang="ts">
/**
 * §9.5 -- the battle bench.
 *
 * Pick a kit, pick a tree, pick a monster, watch the exact exchange a real
 * fight would run. §9.5.4's measured ladder and §9.5.9's cooldowns are both
 * tuning decisions that want measuring, and reading them out of a test run is
 * a worse loop than watching one.
 *
 * THE ARITHMETIC IS THE SERVER'S. Every press is one POST to /api/battle-sim,
 * which runs Formulas::resolveBattle() and the real wear split. Nothing here
 * computes a fight -- a bench that reimplemented what it simulates would be a
 * second opinion that drifts, and the first thing it does when it drifts is
 * lie confidently (§16).
 *
 * The replay is the game's own BattleLive, not a copy of it. That is most of
 * the point: what you are watching is the plate a player sees.
 */
import { computed, onMounted, ref } from 'vue'
import BattleLive from '@/shell/BattleLive.vue'
import BattleSkillList from '@/shell/BattleSkillList.vue'
import SvgIcon from '@/components/SvgIcon.vue'
import { monsterCrest } from '@/icons/combatants'
import { itemIcon } from '@/icons/procedural'
import { ACTION_PATHS } from '@/icons/actions'
import { BATTLE_JOB_FOR_FAMILY, jobFromFight, skillsOfFamily } from '@/game/battle'
import type { BattleJob } from '@/game/types'

interface GearDef {
  key: string
  name: string
  slot: 'weapon' | 'armor' | 'boots' | 'gloves'
  family: string | null
  rarity: string
  palette: string
  attack: number
  defense: number
  maxDurability: number
}

interface MonsterDef {
  key: string
  name: string
  tier: number
  profile: 'brute' | 'carapace' | 'swift'
  attack: number
  defense: number
  hp: number
  description: string
}

interface NodeDef {
  job: string
  tier: number
  jobLevel: number
  name: string
  effect: { kind: string; stat?: string; value: number }
  requires: string[]
  description: string
}

interface Bench {
  gear: GearDef[]
  monsters: MonsterDef[]
  jobs: Record<string, { name: string; kind: string; source: string }>
  nodes: Record<string, NodeDef>
  tierJobLevel: Record<number, number>
  caps: Record<string, number>
  constants: Record<string, number>
}

interface SimResult {
  tree: Record<string, number>
  attack: number
  defense: number
  pool: number
  family: string | null
  monster: string
  monsterHp: number
  roundMs: number
  seed: number
  won: boolean
  rounds: number
  damageTaken: number
  damageDealt: number
  log: BattleJob['log']
  skills: BattleJob['skills']
  wear: Array<{ key: string; name: string; slot: string; lost: number; of: number; destroyed: boolean }>
  bill: number
  over: {
    runs: number
    won: number
    winRate: number
    meanRounds: number
    meanTaken: number
    meanBill: number
  }
}

const SLOTS = ['weapon', 'armor', 'boots', 'gloves'] as const

const bench = ref<Bench | null>(null)
const failed = ref<string | null>(null)
const busy = ref(false)

const kit = ref<Record<string, string>>({ weapon: '', armor: '', boots: '', gloves: '' })
const monster = ref('moss_hound')

/**
 * The tree, as raw inputs rather than as nodes.
 *
 * A bench wants to ask "what would 25% skill power feel like", not "which six
 * nodes add up to that" -- the server clamps every one of these at its own cap
 * on the way in (§7.4.3), so nothing here can ask for a fight the game cannot
 * produce.
 */
const tree = ref({
  jobLevel: 0,
  treeAttack: 0,
  treeDefense: 0,
  skillPower: 0,
  skillCooldown: 0,
  skillStun: 0,
  wearSpared: 0,
  weaponSpared: 0,
  power: 0,
  guard: 0,
})

const seed = ref(1)
const runs = ref(50)

const result = ref<SimResult | null>(null)
const watching = ref(false)

const bySlot = computed(() => {
  const out: Record<string, GearDef[]> = { weapon: [], armor: [], boots: [], gloves: [] }
  for (const g of bench.value?.gear ?? []) out[g.slot]?.push(g)

  return out
})

const monsters = computed(() => bench.value?.monsters ?? [])

/**
 * §7.4 -- the real battle tree, for the family in the slot.
 *
 * The bench asked for raw figures before, which measured shapes no character
 * could build. Picking NODES is what makes it a simulation of the client
 * rather than of the arithmetic underneath it -- and the server aggregates the
 * keys with the very method it aggregates a character's own nodes with, so a
 * simulated Swordhand is a Swordhand.
 */
const owned = ref(new Set<string>())

const treeJob = computed(() => (family.value ? BATTLE_JOB_FOR_FAMILY[family.value] : null))

const depths = computed(() => {
  const b = bench.value
  const job = treeJob.value
  if (!b || !job) return []

  const rows: Array<{ tier: number; level: number; nodes: Array<{ key: string; def: NodeDef }> }> = []

  for (const [key, def] of Object.entries(b.nodes)) {
    if (def.job !== job) continue
    let row = rows.find((r) => r.tier === def.tier)
    if (!row) rows.push((row = { tier: def.tier, level: def.jobLevel, nodes: [] }))
    row.nodes.push({ key, def })
  }

  return rows.sort((a, z) => a.tier - z.tier)
})

/** Whichever nodes belong to the family in the slot. Swapping weapons drops the rest. */
const treeKeys = computed(() =>
  [...owned.value].filter((k) => bench.value?.nodes[k]?.job === treeJob.value),
)

const points = computed(() => treeKeys.value.length)

function toggle(key: string): void {
  const next = new Set(owned.value)
  next.has(key) ? next.delete(key) : next.add(key)
  owned.value = next
}

function clearTree(): void {
  owned.value = new Set()
}

/** Every node of this family's tree, which is what 30 points buys. */
function fillTree(): void {
  const job = treeJob.value
  if (!bench.value || !job) return

  owned.value = new Set(
    Object.entries(bench.value.nodes)
      .filter(([, d]) => d.job === job)
      .map(([k]) => k),
  )
}

/** What the picked kit is worth before the server is asked, so the pickers react. */
const picked = computed(() =>
  SLOTS.map((s) => bench.value?.gear.find((g) => g.key === kit.value[s])).filter(Boolean) as GearDef[],
)

const rough = computed(() => ({
  attack: picked.value.reduce((n, g) => n + g.attack, 0),
  defense: picked.value.reduce((n, g) => n + g.defense, 0),
  pool: picked.value.reduce((n, g) => n + g.maxDurability, 0),
}))

/** The picked piece in one slot, or nothing. */
function gearOf(slot: string): GearDef | undefined {
  return bench.value?.gear.find((g) => g.key === kit.value[slot])
}

/** §13.1 -- slot decides the silhouette, rarity the frame, material the accent. */
function iconFor(g: GearDef): string {
  return itemIcon({
    slot: g.slot,
    family: g.family ?? undefined,
    rarity: g.rarity,
    palette: g.palette,
    size: 24,
  } as Parameters<typeof itemIcon>[0])
}

const family = computed(
  () => bench.value?.gear.find((g) => g.key === kit.value.weapon)?.family ?? null,
)

/**
 * §9.5.9 -- the three the weapon in the slot knows, with or without a tree.
 *
 * The bench asked this question by accident and it turned out to be the good
 * one: put a sword in the slot, buy no nodes at all, and three skills are
 * armed. That is the rule rather than a bug -- they come with the WEAPON, and
 * a point buys the tree that sharpens them -- but nothing on screen said so,
 * so it read as the bench arming things nobody had picked.
 *
 * Before a fight these come off the mirrored table, which carries the name,
 * the mark and the cooldown and deliberately carries no multiplier (§16). The
 * full rows, with every figure the tree moved, arrive WITH the fight -- so the
 * list swaps to those the moment there is one.
 */
const armed = computed(() =>
  result.value?.skills.length
    ? result.value.skills
    : skillsOfFamily(family.value),
)

/**
 * The sim result dressed as the job BattleLive draws, via the shared adapter.
 *
 * The adapter lives in `game/battle.ts` rather than here: the replay is a pure
 * function of the log, the two pools and the round clock, and if the mapping
 * onto that lived beside the bench then adding a field to the plate would
 * silently stop working on /battle.
 */
const asJob = computed<BattleJob | null>(() =>
  result.value ? jobFromFight(result.value) : null,
)

async function load(): Promise<void> {
  try {
    const res = await fetch('/api/battle-sim', { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error(`${res.status}`)
    bench.value = await res.json()

    // Open on something that actually fights, so the first press says
    // something. The middle of each slot's list is the middle of the ladder.
    for (const slot of SLOTS) {
      const list = bySlot.value[slot] ?? []
      kit.value[slot] = list[Math.floor(list.length / 2)]?.key ?? ''
    }
  } catch (e) {
    failed.value = e instanceof Error ? e.message : 'could not reach the bench'
  }
}

async function run(replay = true): Promise<void> {
  if (busy.value) return
  busy.value = true
  failed.value = null

  try {
    const res = await fetch('/api/battle-sim', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        monster: monster.value,
        gear: SLOTS.map((s) => kit.value[s]).filter(Boolean),
        seed: seed.value,
        runs: runs.value,
        nodes: treeKeys.value,
        ...tree.value,
      }),
    })

    if (!res.ok) throw new Error(await res.text())

    result.value = await res.json()
    watching.value = replay
  } catch (e) {
    failed.value = e instanceof Error ? e.message : 'the bench refused that'
  } finally {
    busy.value = false
  }
}

/** A different fight with everything else held still. The whole point of a seed. */
function reroll(): void {
  seed.value = (seed.value + 1) % 100000
  void run()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <!-- The almanac's strip, because this is the almanac's kind of page: a
         standalone instrument over static data, reached from the map and
         returning to it. Two tools that look like two products would be one
         product with a seam down it. -->
    <header class="strip">
      <span class="mark">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.battle" />
        </svg>
      </span>
      <div class="grow">
        <h1>Battle bench</h1>
        <p class="tiny muted">
          The real exchange, run against a kit nobody owns — same code, same
          tree, same wear bill. Nothing here is saved and no character is
          touched.
        </p>
      </div>
      <a class="back" href="/">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
             stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 6 8 12l6 6" />
        </svg>
        Back to the map
      </a>
    </header>

    <div class="body">
      <p v-if="failed" class="inset tiny warn">{{ failed }}</p>

      <div v-if="bench" class="cols">
        <!-- ----------------------------------------------------- the kit -->
        <section class="col">
          <h2 class="head">Kit</h2>

          <!-- §13.1 draws every item as a hex, so four bare dropdowns were the
               least game-like thing on the page. The tile carries the icon and
               the rarity; the select is the tile's own control rather than a
               field sitting next to it. -->
          <div class="slots">
            <label v-for="slot in SLOTS" :key="slot" class="slot" :class="{ empty: !gearOf(slot) }">
              <span class="hex tile">
                <span class="face">
                  <SvgIcon v-if="gearOf(slot)" :svg="iconFor(gearOf(slot)!)" />
                  <span v-else class="tiny muted">—</span>
                </span>
              </span>
              <span class="grow">
                <span class="label">{{ slot }}</span>
                <!-- The select IS the name. Printing it above as a heading too
                     was the same word twice in one row -- so the control wears
                     the rarity tint instead, and nothing is repeated. -->
                <select
                  v-model="kit[slot]"
                  class="pick"
                  :class="gearOf(slot) ? `rarity-${gearOf(slot)!.rarity}` : 'muted'"
                  :aria-label="slot"
                >
                  <option value="">— nothing —</option>
                  <option v-for="g in bySlot[slot]" :key="g.key" :value="g.key">
                    {{ g.name }} · {{ g.rarity }}
                  </option>
                </select>
              </span>
              <span class="readout tiny fig">
                {{ gearOf(slot)?.attack ?? 0 }}/{{ gearOf(slot)?.defense ?? 0 }}
                <span class="label dur">{{ gearOf(slot)?.maxDurability ?? 0 }} dur</span>
              </span>
            </label>
          </div>

          <dl class="rails">
            <div class="rail atk"><dt class="label">Attack</dt><dd class="readout">{{ rough.attack }}</dd></div>
            <div class="rail def"><dt class="label">Defense</dt><dd class="readout">{{ rough.defense }}</dd></div>
            <div class="rail pool"><dt class="label">Pool</dt><dd class="readout">{{ rough.pool }}</dd></div>
            <div v-if="family" class="rail fam">
              <dt class="label">Class</dt>
              <dd><span class="chip">{{ family }}</span></dd>
            </div>
          </dl>

          <!-- §9.5.9 -- what the weapon knows, before a point is spent on
               anything. No cooldown rail here: the rows already carry the mark
               and the number, and a second copy of both would be the accessory
               to take off. The rail is the REPLAY's instrument -- it earns its
               place by moving. -->
          <template v-if="armed.length">
            <h2 class="head">Skills</h2>
            <BattleSkillList :skills="armed" :family="family" :log="result?.log ?? null" />
          </template>

          <div class="row-between head-row">
            <h2 class="head">{{ treeJob ? bench.jobs[treeJob]?.name : 'Tree' }}</h2>
            <span class="label">{{ points }} / 30 points</span>
          </div>

          <p v-if="!treeJob" class="tiny muted lead">Put a weapon in the slot and its tree appears.</p>

          <template v-else>
            <div v-for="row in depths" :key="row.tier" class="depth">
              <span class="label lv">lv {{ row.level }}</span>
              <div class="picks">
                <button
                  v-for="n in row.nodes"
                  :key="n.key"
                  type="button"
                  class="chip node"
                  :class="owned.has(n.key) ? 'chip-on' : ''"
                  :title="`${n.def.name} — ${n.def.description}`"
                  @click="toggle(n.key)"
                >
                  {{ n.def.name }}
                </button>
              </div>
            </div>

            <div class="row tools">
              <button class="btn btn-sm" type="button" @click="fillTree">All 30</button>
              <button class="btn btn-sm" type="button" @click="clearTree">None</button>
            </div>
          </template>

          <h2 class="head">On top of the tree</h2>
          <p class="tiny muted lead">
            Added to whatever the nodes came to, and clamped with them — for
            asking what a shape no tree can currently reach would feel like.
          </p>

          <div class="grid">
            <label class="field"><span class="label">job level</span>
              <input v-model.number="tree.jobLevel" type="number" min="0" max="30"></label>
            <label class="field"><span class="label">tree atk</span>
              <input v-model.number="tree.treeAttack" type="number" min="0" :max="bench.caps.pair"></label>
            <label class="field"><span class="label">tree def</span>
              <input v-model.number="tree.treeDefense" type="number" min="0" :max="bench.caps.pair"></label>
            <label class="field"><span class="label">power %</span>
              <input v-model.number="tree.power" type="number" min="0" max="0.15" step="0.01"></label>
            <label class="field"><span class="label">defense %</span>
              <input v-model.number="tree.guard" type="number" min="0" max="0.15" step="0.01"></label>
            <label class="field"><span class="label">skill power</span>
              <input v-model.number="tree.skillPower" type="number" min="0" :max="bench.caps.skillPower" step="0.01"></label>
            <label class="field"><span class="label">skill cd</span>
              <input v-model.number="tree.skillCooldown" type="number" min="0" :max="bench.caps.skillCooldown"></label>
            <label class="field"><span class="label">skill stun</span>
              <input v-model.number="tree.skillStun" type="number" min="0" :max="bench.caps.skillStun"></label>
            <label class="field"><span class="label">wear spared</span>
              <input v-model.number="tree.wearSpared" type="number" min="0" :max="bench.caps.battleWear" step="0.01"></label>
            <label class="field"><span class="label">weapon spared</span>
              <input v-model.number="tree.weaponSpared" type="number" min="0" :max="bench.caps.weaponWear" step="0.01"></label>
          </div>
        </section>

        <!-- -------------------------------------------- the foe and answer -->
        <section class="col">
          <h2 class="head">Enemy</h2>
          <div class="foes">
            <button
              v-for="m in monsters"
              :key="m.key"
              type="button"
              class="foe"
              :class="{ on: monster === m.key }"
              @click="monster = m.key"
            >
              <span class="hex tile sm">
                <span class="face"><SvgIcon :svg="monsterCrest(m.profile, m.tier, 24)" /></span>
              </span>
              <span class="grow">
                <!-- Tier belongs with the profile: they are the two things a
                     monster IS. It sat in the figure column and cost the name
                     the width it needed, so half the roster read "Ash Reven…". -->
                <span class="label">{{ m.profile }} · t{{ m.tier }}</span>
                <strong class="tiny name">{{ m.name }}</strong>
              </span>
              <span class="readout tiny fig">{{ m.attack }}/{{ m.defense }}</span>
            </button>
          </div>

          <div class="run">
            <label class="field"><span class="label">seed</span>
              <input v-model.number="seed" type="number" min="0"></label>
            <label class="field"><span class="label">samples</span>
              <input v-model.number="runs" type="number" min="1" max="500"></label>
            <button class="btn btn-primary go" type="button" :disabled="busy" @click="run()">
              {{ busy ? 'Running…' : 'Fight' }}
            </button>
          </div>

          <h2 class="head">Result</h2>
          <p v-if="!result" class="tiny muted lead">Press Fight. The replay opens over the page.</p>

          <template v-else>
            <div class="verdict" :class="result.won ? 'won' : 'lost'">
              <strong class="readout">{{ result.won ? 'You win' : 'You lose' }}</strong>
              <span class="label">{{ result.rounds }} rounds · seed {{ result.seed }}</span>
            </div>

            <!-- The almanac's rail, reused. Caps label, hairline in the colour
                 of what it means, figure on the right -- the same habit the
                 skill tooltips and the tree panel read by. -->
            <dl class="rails">
              <div v-if="result.tree.attack || result.tree.skillPower" class="rail">
                <dt class="label">Tree</dt>
                <dd class="readout">
                  +{{ result.tree.attack }}/{{ result.tree.defense }} ·
                  {{ Math.round(result.tree.skillPower * 100) }}% · cd −{{ result.tree.skillCooldown }}
                </dd>
              </div>
              <div class="rail atk"><dt class="label">Dealt</dt>
                <dd class="readout">{{ result.damageDealt }} <span class="muted">of {{ result.monsterHp }}</span></dd></div>
              <div class="rail def"><dt class="label">Taken</dt>
                <dd class="readout">{{ result.damageTaken }} <span class="muted">of {{ result.pool }}</span></dd></div>
              <div class="rail coin"><dt class="label">Bill</dt>
                <dd class="readout gold">{{ result.bill }}</dd></div>
            </dl>

            <h2 class="head sub">Over {{ result.over.runs }} seeds</h2>
            <dl class="rails">
              <div class="rail" :class="result.over.winRate >= 0.5 ? 'win' : 'lose'">
                <dt class="label">Wins</dt>
                <dd class="readout">
                  {{ Math.round(result.over.winRate * 100) }}%
                  <span class="muted">{{ result.over.won }}/{{ result.over.runs }}</span>
                </dd>
              </div>
              <div class="rail"><dt class="label">Rounds</dt><dd class="readout">{{ result.over.meanRounds }}</dd></div>
              <div class="rail"><dt class="label">Taken</dt><dd class="readout">{{ result.over.meanTaken }}</dd></div>
              <div class="rail coin"><dt class="label">Bill</dt><dd class="readout gold">{{ result.over.meanBill }}</dd></div>
            </dl>

            <h2 class="head sub">What it cost</h2>
            <dl class="rails">
              <div v-for="w in result.wear" :key="w.key" class="rail" :class="{ lose: w.destroyed }">
                <dt class="label">{{ w.slot }}</dt>
                <dd class="readout">
                  −{{ w.lost }} <span class="muted">of {{ w.of }}</span>
                  <span v-if="w.destroyed" class="chip chip-off">gone</span>
                </dd>
              </div>
            </dl>

            <div class="row tools">
              <button class="btn btn-sm" type="button" :disabled="busy" @click="watching = true">Watch again</button>
              <button class="btn btn-sm" type="button" :disabled="busy" @click="reroll">Another seed</button>
            </div>
          </template>
        </section>
      </div>

      <p v-else-if="!failed" class="tiny muted lead">Loading the catalog…</p>
    </div>

    <!-- The game's own plate, handed a fight that never happened to anybody.
         §9.5.5 -- the replay is a pure function of the log and the two pools,
         which is exactly why it can be reused here unchanged. -->
    <BattleLive
      v-if="watching && asJob"
      :key="`${result?.seed}-${result?.monster}`"
      :job="asJob"
      :pair="{ attack: result!.attack, defense: result!.defense }"
      @done="watching = false"
    />
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  background: var(--ink);
}

/* ------------------------------------------------------------ the strip */
/* Lifted wholesale from the almanac. Two standalone tools that looked like two
   products would be one product with a seam down it. */
.strip {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--line);
  background: var(--ink-panel);
}

.mark {
  display: grid;
  place-items: center;
  width: 38px;
  height: 33px;
  flex: 0 0 auto;
  background: var(--ink-raised);
  color: var(--copper);
  clip-path: var(--hex-clip);
}

.strip h1 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--vellum);
}

.strip p {
  margin: 3px 0 0;
  max-width: 66ch;
  line-height: 1.45;
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: 0 0 auto;
  padding: 7px 12px;
  border: 1px solid var(--line);
  background: var(--ink-raised);
  color: var(--vellum-dim);
  font-size: 11.5px;
  text-decoration: none;
  clip-path: polygon(7px 0, 100% 0, 100% calc(100% - 7px), calc(100% - 7px) 100%, 0 100%, 0 7px);
}

.back:hover {
  color: var(--vellum);
  border-color: var(--copper);
}

.body {
  padding: 18px 16px 60px;
}

.cols {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 26px;
  align-items: start;
  max-width: 1160px;
  margin: 0 auto;
}

@media (max-width: 900px) {
  .cols {
    grid-template-columns: minmax(0, 1fr);
    gap: 22px;
  }
}

.col {
  min-width: 0;
}

/* Display serif for a section head, matching the almanac's tier headings. It is
   the one place on the page that is not caps-tracked, which is what makes the
   labels read as annotation rather than as more headings. */
.head {
  margin: 22px 0 9px;
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 700;
  color: var(--vellum);
}

.col > .head:first-child,
.head-row:first-child .head {
  margin-top: 0;
}

.head.sub {
  font-size: 12.5px;
  color: var(--vellum-dim);
}

.head-row {
  margin: 22px 0 9px;
  align-items: baseline;
}

.head-row .head {
  margin: 0;
}

.lead {
  margin: 0 0 10px;
  line-height: 1.5;
}

/* -------------------------------------------------------------- the kit */
/* §13.1 draws every item as a hex, so four bare dropdowns were the least
   game-like thing on the page. The tile carries the icon and the rarity; the
   select is the tile's own control rather than a field beside it. */
.slots {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.slot {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 7px 10px;
  background: rgba(0, 0, 0, 0.28);
  clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
  cursor: pointer;
}

.slot.empty {
  opacity: 0.62;
}

.tile {
  width: 38px;
  height: 33px;
  flex: 0 0 auto;
}

.tile.sm {
  width: 32px;
  height: 28px;
}

.tile .face {
  display: grid;
  place-items: center;
  background: var(--ink-raised);
}

.slot .grow,
.foe .grow {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.name {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* The control, sunk into the row. A native select for the keyboard and the
   screen reader; a hairline for the eye. */
.pick {
  width: 100%;
  margin-top: 2px;
  padding: 3px 4px;
  border: 0;
  border-bottom: 1px solid var(--line);
  border-radius: 0;
  background: none;
  color: var(--vellum-dim);
  font: inherit;
  font-size: 10.5px;
}

.pick:focus {
  outline: none;
  border-bottom-color: var(--copper);
  color: var(--vellum);
}

/* Right-hand figures, right-aligned so rows compare down a column. */
.fig {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  flex: 0 0 auto;
  white-space: nowrap;
  color: var(--vellum-dim);
}

.fig .dur {
  color: #6d7770;
}

/* ------------------------------------------------------------ the rails */
/*
 * The almanac's signature, reused rather than reinvented: a hairline in the
 * colour of what the row means, the name in caps, the figure beside it. Every
 * number on this page is one of these, which is the same habit the skill
 * tooltips and the tree panel already read by -- three surfaces, one grammar.
 */
.rails {
  margin: 10px 0 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rails.tight {
  gap: 3px;
  margin-top: 6px;
}

.rail {
  display: grid;
  grid-template-columns: 82px 1fr;
  gap: 8px;
  padding-left: 8px;
  border-left: 2px solid var(--road, var(--line));
}

.rail dt {
  padding-top: 2px;
  color: var(--road, #6d7770);
}

.rail dd {
  margin: 0;
  color: var(--vellum);
  font-size: 12px;
}

.rail.atk { --road: var(--copper); }
.rail.def { --road: #6d8399; }
.rail.pool { --road: var(--line); }
.rail.fam { --road: var(--line); }
/* §3.2 -- gold is the one thing that is money, and the bill is the only gold
   on the page, so the eye finds the cost without hunting. */
.rail.coin { --road: var(--gold); }
.rail.win { --road: var(--sap); }
.rail.lose { --road: var(--ember); }

.gold { color: var(--gold); }

/* ------------------------------------------------------------- the tree */
/* One depth a row, named by the job level that opens it -- the gutter the real
   panel prints (§7.4.2). Chips rather than a hex lattice: this is a bench, and
   what it needs is every node reachable in one press. */
.depth {
  display: flex;
  gap: 9px;
  align-items: baseline;
  margin-bottom: 6px;
}

.depth .lv {
  flex: 0 0 34px;
  color: #6d7770;
}

.picks {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.node {
  color: var(--vellum-dim);
}

.node:hover {
  color: var(--vellum);
}

.tools {
  gap: 8px;
  margin-top: 10px;
}

/* ------------------------------------------------------------ the inputs */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(104px, 1fr));
  gap: 8px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

input {
  width: 100%;
  padding: 6px 8px;
  border: 1px solid var(--line);
  background: var(--ink);
  color: var(--vellum);
  font-family: var(--font-display);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  clip-path: polygon(5px 0, 100% 0, 100% calc(100% - 5px), calc(100% - 5px) 100%, 0 100%, 0 5px);
}

input:focus {
  outline: none;
  border-color: var(--copper);
}

/* ------------------------------------------------------------- the enemy */
.foes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(182px, 1fr));
  gap: 5px;
}

.foe {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 6px 9px;
  background: rgba(0, 0, 0, 0.28);
  clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
  color: var(--vellum);
  text-align: left;
}

.foe:hover {
  background: rgba(193, 121, 63, 0.1);
}

.foe.on {
  background: rgba(193, 121, 63, 0.18);
  box-shadow: inset 2px 0 0 var(--copper);
}

.run {
  display: flex;
  align-items: flex-end;
  gap: 9px;
  margin-top: 12px;
}

.run .field {
  width: 78px;
  flex: 0 0 auto;
}

/* Sized to the word, not to the row. A trigger stretched across seven hundred
   pixels reads as a banner rather than as the one thing to press. */
.run .go {
  padding: 8px 22px;
}

/* ------------------------------------------------------------ the answer */
.verdict {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 11px;
  background: rgba(0, 0, 0, 0.28);
  clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
}

.verdict strong {
  font-size: 17px;
}

/* §13.3 -- sap is worth crossing the screen for, ember is a state to deal
   with. A fight is exactly one or the other. */
.verdict.won strong { color: var(--sap); }
.verdict.lost strong { color: var(--ember); }

.warn {
  color: var(--ember);
}

</style>
