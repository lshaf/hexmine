<script setup lang="ts">
/**
 * The bottom dock: what you can do, here.
 *
 * "Here" is literal. This reads the hex under the character's feet, never the
 * one that happens to be selected -- aiming at a hex is the tile card's job, and
 * so is traveling to it. The dock is only ever about the ground you stand on.
 *
 * Mining is one mine at a time and it pins you in place until you deal with it,
 * so Mine and Claim are a single slot at two moments of the same mine rather
 * than two buttons competing for attention. Trading, crafting and processing
 * appear only at a settlement (§6) -- there is no trader in the middle of a
 * forest, and graying one out would imply there could be.
 */
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { api } from '@/api/client'
import { MONSTERS } from '@/game/monsters'
import { RECIPES, RING_LABEL, SKILL_BY_KEY } from '@/game/catalog'
import { VARIANT_LABEL } from '@/game/variants'
import { waterLabel } from '@/game/water'
import { formatDuration, placeLabel } from '@/game/formulas'
import { worldParams } from '@/game/worldgen'
import HexAction from './HexAction.vue'
import BattleSkillRail from './BattleSkillRail.vue'
import type { BattlePreview } from '@/api/types'

const game = useGame()

const here = computed(() => game.currentSettlement)

/** The hex underfoot, costed by the server. Everything in the dock keys off it. */
const underfoot = computed(() => game.underfoot)

/** The same hex from the local generator, for naming open country. */
const standing = computed(() => {
  const char = game.character
  return char ? game.tileAt(char.col, char.row) : undefined
})

/**
 * What this place is called. §5.3 water is named by biome, because a tarn and
 * an alkali pan are not the same body of water and calling both "Lake" throws
 * that away.
 */
const placeName = computed(() => {
  // §6 -- a settlement is said with its hex. Two villages can share a name, and
  // the coordinates are what anybody types in to walk here.
  if (here.value) return placeLabel(here.value.name, here.value.col, here.value.row)

  const tile = standing.value
  if (!tile) return 'Unsurveyed'

  return tile.water ? waterLabel(tile.biome, tile.water) : VARIANT_LABEL[tile.variant]
})

/**
 * One mine at a time, so this is a single job or nothing -- and a hunt is a
 * mine. Both pin you to the hex until you claim or drop, so both have to reach
 * this slot; reading only the mining job left a finished hunt with no way to
 * claim it and nothing on the dock saying why everything else was refused.
 */
const working = computed(() => game.fieldJob)
const ready = computed(() => Boolean(working.value && working.value.endsAt <= game.now))

/** Ground worth working. Settlement tiles and the barren center have neither. */
const seam = computed(() => Boolean(underfoot.value?.material))

/**
 * §4.0 -- the same hex worked by hand, costed by the server beside the seam.
 *
 * Bare hands are not a worse version of mining, they are the other verb, so
 * they get their own cell rather than quietly replacing the first one. Mining
 * names the tool it wants and refuses without it; this is the answer standing
 * next to that refusal, which is how the hex stays unblocked.
 */
const gather = computed(() => underfoot.value?.gather)

/**
 * Nothing here is grayed out for want of a tool, and that is the rule.
 *
 * A dead cell has to explain itself in a tooltip nobody opens on a phone, so
 * the cells stay live and the server answers -- once, in a toast, in the same
 * words the preview would have shown. The only thing that takes a verb off the
 * dock is the hex genuinely not having it: no seam, or no herd.
 */
/**
 * §8.2 -- a mine that would finish a tool off says so on the button.
 *
 * The same promise the fight preview makes, on the other verb: destruction is
 * the largest sink in the game and it may never be a surprise. It outranks the
 * yield, because "7 units" is what you came for and "this is your last swing
 * with that axe" is what would change your mind.
 */
const wearWarning = computed(() => underfoot.value?.warnings?.[0] ?? null)


const mineHint = computed(
  () => underfoot.value?.reason ?? wearWarning.value ?? `${underfoot.value?.yield ?? 0} units`,
)
const gatherHint = computed(() => gather.value?.reason ?? `${gather.value?.yield ?? 0} units by hand`)

/**
 * §5.5 -- a herd standing on this hex right now. Temporary and time-bucketed,
 * so the cell appears and leaves on its own; there is nothing to un-spawn.
 */
const hunt = computed(() => underfoot.value?.hunt)
const herd = computed(() => Boolean(hunt.value?.herdUntil))

/**
 * How long the herd stays. The only clock on this dock counting something that
 * is not yours: a seam waits, a herd leaves (§5.5). That is the whole reason
 * the cell earns a countdown when Mine never has.
 *
 * Hidden while a mine runs -- one mine at a time means the herd is unactionable
 * then, and a countdown you cannot act on is noise.
 */
const herdLeaves = computed(() => {
  const until = hunt.value?.herdUntil
  if (!until || working.value) return null

  return formatDuration(until - game.now)
})

/**
 * The last quarter of the herd's stay: the offer is closing.
 *
 * A fraction of the lifetime rather than a fixed number of minutes, because
 * GAME_TIME_SCALE compresses the lifetime and an absolute threshold would read
 * "closing" for the whole window at a fast clock. worldParams() already carries
 * the scaled value the server generated the marker from.
 */
const herdGoing = computed(() => {
  const until = hunt.value?.herdUntil
  if (!until) return false

  return until - game.now < worldParams().herdLifetimeMs * 0.25
})

/**
 * §9.5.3 -- something is standing on this hex, and until it is not there is no
 * work here and no road out.
 *
 * The dock says the two exits out loud, because a refusal that names neither
 * reads as a bug: fight it, or wait for its clock. Nothing is grayed out to
 * explain the pin -- the verbs are simply not what this hex is offering.
 */
const pinned = computed(() => Boolean(underfoot.value?.pinned))

/** The pack itself is derived client-side, so the name costs no request. */
const pack = computed(() => game.tileAt(game.character?.col ?? 0, game.character?.row ?? 0)?.pack)

const packLeaves = computed(() => {
  const until = pack.value?.until
  if (!until) return null

  return formatDuration(until - game.now)
})

/**
 * §9.5.5 -- the odds, before anything is committed.
 *
 * Fetched rather than derived: the client knows which monster is standing
 * there, but what a fight would cost depends on gear, a battle job and whatever
 * was drunk, and the server owns all three (§16).
 */
const battle = ref<BattlePreview | null>(null)

/**
 * §9.5.7 -- a corpse standing here, whoever it belongs to.
 *
 * Unlike a pack it does NOT pin: it stands for twenty-four hours, and a hex
 * fenced off for a day would be exactly the griefing §9.5.1 keeps packs off
 * settlements to prevent. So it is a verb beside the others rather than
 * instead of them.
 */
const corpse = computed(() =>
  game.carriers.find(
    (c) => c.col === game.character?.col && c.row === game.character?.row,
  ) ?? null,
)

watch(
  [pinned, () => pack.value?.key, () => corpse.value?.label],
  async ([isPinned]) => {
    battle.value = isPinned || corpse.value ? await api.previewBattle() : null
  },
  { immediate: true },
)

/**
 * §9.5.9 -- the three your weapon knows, on the fight preview.
 *
 * The design has always put them here and nothing drew them: "the skills are
 * on the fight preview because whether to close at all is the decision, and
 * against a long fight these are half of it."
 *
 * Cold, because a fight that has not started has every cooldown full -- which
 * is the rule rather than a placeholder. Read off the battle job the preview
 * already reports, since §9.5.4 makes the family in the slot your class and
 * the job is the same fact said the other way round.
 */
const packSkills = computed(() => battle.value?.skills ?? [])

/**
 * Why the button will not work, and nothing else.
 *
 * The dock used to carry the whole preview here: the verdict, the rounds, the
 * durability bill, and both fighters' attack, defense and pool. That is a
 * paragraph of arithmetic in front of a decision that is one tap, and on a
 * phone it wrapped into lines of unreadable digits.
 *
 * What is left is the one thing a player cannot act without: the reason a tap
 * would do nothing -- already in this fight, already working the hex. A
 * refusal with no explanation is the worst answer a dock can give.
 *
 * The bench at /battle runs the same exchange against any kit, for anybody who
 * wants the numbers before walking into one.
 */
const packBlock = computed(() => battle.value?.reason ?? null)

/**
 * §8.2 -- the terms of every fight, told apart from the hazards of this one.
 *
 * The server sends both in `warnings`. Losing is dying is unconditional, so it
 * is drawn first and a step quieter; "your Stone Axe will not survive this" is
 * about the kit you happen to be wearing, and keeps the full ember.
 *
 * The sentence itself is still the SERVER'S — matched out of the list rather
 * than written here — so there is one copy of it and the plate cannot end up
 * promising something the fight does not do.
 */
const DEATH_TERMS = 'Lose and you die'

const deathTerms = computed(() =>
  (battle.value?.warnings ?? []).find((w) => w.startsWith(DEATH_TERMS)) ?? null,
)

const gearWarnings = computed(() =>
  (battle.value?.warnings ?? []).filter((w) => !w.startsWith(DEATH_TERMS)),
)

/**
 * §10.0 -- what the guild cell has to say at this settlement.
 *
 * Three states and each is a different errand: you have none and could found or
 * join one, you have one and this is where you run it, or you are standing in
 * your own hall and the top rung is open on top of that.
 */
const guildBusiness = computed(() => !game.guild || game.atGuildHall)

const guildHint = computed(() => {
  if (game.atGuildHall) return 'Your hall — the legendary bench is open here'
  if (game.guild) return `${game.guild.name} · the roster, the door, the flag`

  return 'Found a hall, or join one that is recruiting'
})

const corpseHint = computed(() => {
  const c = corpse.value
  if (!c) return ''

  const call = battle.value?.canFight
    ? `${battle.value.expected?.won ? 'You take it' : 'It drives you off'} · `
    : ''

  return c.mine ? `${call}${c.label} comes home` : `${call}${c.label} burns if you take it`
})

const huntHint = computed(() => {
  const h = hunt.value
  if (!h) return ''
  if (h.reason) return h.reason
  return `${h.yield} units`
})

/**
 * What calling it off costs, §11.1 -- leaving a hex mid-progress forfeits the
 * partial yield. Said plainly, because it is the only thing this button does.
 */
const cancelHint = computed(() => {
  if (!working.value) return ''

  return working.value.kind === 'hunting'
    ? 'Leaves the herd, and the time spent on it'
    : 'Forfeits the dig, and frees you to move'
})

const claimHint = computed(() => {
  if (!working.value) return ''

  // §9.5.5 -- a fight has no units and no partial anything. What is waiting is
  // the answer to a question you already asked.
  if (working.value.kind === 'battle') {
    return ready.value ? 'See how it went' : 'Still swinging'
  }

  if (ready.value) return `${working.value.quantity} units waiting`
  return working.value.kind === 'hunting' ? 'Still working this herd' : 'Still working this hex'
})

/** Processing lines this settlement runs, §6. */
const lines = computed(() =>
  here.value ? RECIPES.filter((r) => here.value!.lines.includes(r.skill)) : [],
)

const lineNames = computed(() =>
  [...new Set(lines.value.map((r) => SKILL_BY_KEY[r.skill].name))].join(' · '),
)

const processHint = computed(() => {
  if (!lines.value.length) return 'No lines run here'
  // §6.1 + §8.4 -- work parked elsewhere is not a reason to say no here, only
  // ten lots of it is. What is in the way at THIS settlement is the panel's to
  // say, per line, because that is where the line is chosen.
  if (game.workFull) return `${game.benchJobs.length} lots of work already out`
  return lineNames.value
})

/**
 * What you are doing here, under where you are. A mine locks you to this hex,
 * so its countdown belongs on the dock rather than hidden in a tooltip.
 */
const doing = computed(() => {
  if (!working.value) return null
  if (ready.value) return working.value.kind === 'battle' ? 'The fight is over' : 'Reward ready'

  const verb =
    working.value.kind === 'battle' ? 'Fighting' : working.value.kind === 'hunting' ? 'Hunting' : 'Working'

  return `${verb} · ${formatDuration(working.value.endsAt - game.now)}`
})

function mine(): void {
  const char = game.character
  if (char) void game.startMining(char.col, char.row)
}

function gathered(): void {
  const char = game.character
  if (char) void game.startGathering(char.col, char.row)
}

function hunted(): void {
  const char = game.character
  if (char) void game.startHunt(char.col, char.row)
}
</script>

<template>
  <div class="dock plate">
    <div class="inner">
      <!-- Where you are, and what you are doing about it -- two lines, the same
           two the tile card sitting above uses: what this place is, then what it
           is called. The caption the first line used to carry ("You are at") was
           the one thing here that said nothing. -->
      <div class="where">
        <span class="label meta">
          <template v-if="here">{{ here.tier }} · {{ lineNames || 'no lines' }}</template>
          <template v-else-if="standing">Open country · {{ RING_LABEL[standing.ring] }}</template>
          <template v-else>Unsurveyed</template>
        </span>

        <span class="named">
          <h2 class="place">{{ placeName }}</h2>
          <span v-if="doing" class="label doing" :class="{ ready }">{{ doing }}</span>
          <!-- §5.5 -- perishable, so it says when it goes rather than that it is
               here. "Herd" alone would read as scenery. -->
          <span v-else-if="herdLeaves" class="label herd" :class="{ going: herdGoing }">
            Herd moves on in {{ herdLeaves }}
          </span>
        </span>
      </div>

      <div class="actions">
        <!--
          Mine and Claim are the same slot at two moments of one mine, and only
          ONE verb is ever offered at a time.

          While it runs there is nothing to take, so the only thing on offer is
          calling it off. The moment it finishes that button goes away and the
          claim takes its place: a finished haul dropped by a mistap is pure
          loss with nothing bought -- there is no version of that tap anybody
          means. Giving up mid-dig is a real decision (§11.1 forfeits the
          partial yield); giving up a haul that already exists is not.
        -->
        <template v-if="working">
          <HexAction
            v-if="ready || working.kind === 'battle'"
            small
            :icon="working.kind === 'battle' ? 'battle' : 'claim'"
            :label="working.kind === 'battle' ? 'Result' : 'Claim'"
            :primary="ready"
            :disabled="!ready || game.busy"
            :hint="claimHint"
            @activate="game.collect(working.id)"
          />
          <!-- §9.5.5 -- a fight is not one of the things you may walk away
               from. §9.5.3 offers two exits from a pack and once the first is
               chosen there is no third. -->
          <HexAction
            v-else
            small
            icon="drop"
            label="Cancel"
            danger
            :disabled="game.busy"
            :hint="cancelHint"
            @activate="game.abandon(working.id)"
          />
        </template>

        <!-- §9.5.3 -- while a pack holds the hex there are no verbs, only the
             two ways out of the pin. -->
        <template v-else-if="pinned">
          <!--
            Who, when, and what it costs — in that order and at three different
            weights, because they are three different questions. It was four
            sentences of the same size stacked in a column, which made the one
            that mattered (the name) no louder than the clock.
          -->
          <div class="pinned">
            <div class="who">
              <strong class="name">{{ pack ? MONSTERS[pack.key]?.name : 'Something' }}</strong>
              <span v-if="packLeaves" class="tiny leaves">leaves in {{ packLeaves }}</span>
            </div>

            <!--
              §8.2 -- the one thing said every time. An idle game may never take
              something expensive by surprise, and the warnings beside it are
              the specific version of that: THIS fight finishes THAT piece of
              gear.
            -->
            <span v-if="deathTerms" class="tiny warn terms">{{ deathTerms }}</span>
            <span v-for="warning in gearWarnings" :key="warning" class="tiny warn">
              {{ warning }}
            </span>

            <!-- Why a tap would do nothing, when that is the case. -->
            <span v-if="packBlock" class="tiny muted">{{ packBlock }}</span>

            <!-- §9.5.9 -- what you have LEARNED, and nothing else. Every dial is
                 full, because every skill starts a fight on cooldown: a rout
                 never sees one, and knowing that is part of deciding whether to
                 close. -->
            <BattleSkillRail v-if="packSkills.length" class="knows" :skills="packSkills" />
          </div>

          <!-- §9.5.3 -- one of the two exits, and the only one that is an
               action. The other is the clock beside it, which needs no button.
               Never grayed: a loss clears the pack as surely as a win does, so
               fighting is always available even bare-handed -- that is what
               keeps the pin from being a dead end (§5.6). -->
          <HexAction
            small
            icon="battle"
            label="Fight"
            :primary="Boolean(battle?.canFight)"
            :disabled="game.busy"
            :hint="packBlock ?? ''"
            @activate="game.fight()"
          />
        </template>

        <template v-else>
          <!-- Both verbs, always, on any hex that has a seam at all. Which one
               is lit says which one the belt is ready for; neither is closed,
               because §4.0 is about the ground being open and a grayed cell
               says the opposite. -->
          <HexAction
            v-if="seam"
            small
            icon="mine"
            label="Mine"
            :primary="Boolean(underfoot?.canMine) && !herd"
            :disabled="game.busy"
            :hint="mineHint"
            @activate="mine"
          />

          <HexAction
            v-if="seam"
            small
            icon="gather"
            label="Gather"
            :primary="Boolean(gather?.canMine) && underfoot?.bare && !herd"
            :disabled="game.busy"
            :hint="gatherHint"
            @activate="gathered"
          />

          <!-- §5.5 -- present only while a herd is. Beside the other two rather
               than instead of them: a hunt takes no tile slot, so all three
               verbs are genuinely available on the same hex at the same time. -->
          <!-- §9.5.7 -- the hook. Somebody's row is standing on this hex, and
               only its owner can take it back: anybody else killing it burns
               the row rather than moving it (§2). -->
          <HexAction
            v-if="corpse"
            small
            icon="battle"
            :label="corpse.mine ? 'Recover' : 'Corpse'"
            :primary="corpse.mine"
            :disabled="game.busy"
            :hint="corpseHint"
            @activate="game.fight()"
          />

          <HexAction
            v-if="herd"
            small
            icon="hunt"
            label="Hunt"
            :primary="Boolean(hunt?.canHunt)"
            :disabled="game.busy"
            :hint="huntHint"
            @activate="hunted"
          />
        </template>

        <!-- Settlement-only. Absent in the field rather than grayed: the point
             is that these people are not out here. -->
        <template v-if="here">
          <span v-if="working || seam || herd" class="rule" aria-hidden="true" />
          <HexAction small icon="trade" label="Trade" @activate="game.openPanel('shop')" />
          <HexAction small icon="craft" label="Craft" @activate="game.openPanel('craft')" />
          <HexAction
            small
            icon="process"
            label="Process"
            :disabled="!lines.length"
            :hint="processHint"
            @activate="game.openStation()"
          />
          <!-- §10.0 -- a guild is a place before it is a roster, so founding
               one and joining one live where the halls are. Absent at a
               village, because a hall cannot stand in one. -->
          <HexAction
            v-if="here.tier !== 'village'"
            small
            icon="guild"
            label="Guild"
            :good="guildBusiness"
            :hint="guildHint"
            @activate="game.openHalls()"
          />
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * Sized to its contents: the dock is narrow in the field and wide at a
 * settlement, so its silhouette changes with location before you read a word
 * of it.
 *
 * Its *height* is not its own, though. The tile card sits directly on top of it
 * and the two read as one band, so the dock takes the card's cell size and the
 * card's vertical padding -- which is what makes the two plates come out the
 * same height without either of them being told a number.
 */
.dock {
  width: fit-content;
  max-width: min(620px, calc(100vw - 24px));
}

/* The tile card's own padding, to the pixel -- see the note on .dock above. */
.inner {
  padding: 9px 12px 10px 14px;
  display: flex;
  align-items: center;
  gap: 18px;
}

.where {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
  /* Wide enough for a capital running all five lines, and the dock is sized to
     its contents, so the plate grows rather than the text wrapping to a third
     line. */
  max-width: 300px;
}

/* The name and what is happening to it share the second line: the status is
   about this place, so it reads as part of naming it rather than as a third
   thing stacked underneath. */
.named {
  display: flex;
  align-items: baseline;
  gap: 9px;
  min-width: 0;
}

.place {
  font-size: 16px;
  color: var(--vellum);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.meta,
.named > .label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.named > .label {
  flex: 0 0 auto;
}

.doing {
  color: var(--copper);
}

.doing.ready {
  color: var(--gold);
}

/* Gold, because a herd is an opportunity rather than work in progress -- the
   same reading the map already gives gold in §13.1. Ember once it is closing. */
.herd {
  color: var(--gold);
}

.herd.going {
  color: var(--ember);
}

.actions {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-left: auto;
}

.rule {
  width: 1px;
  align-self: stretch;
  background: var(--hud-line-soft);
  margin: 2px 4px 12px;
}

/*
 * Phones keep the same shape rather than stacking. Three cells is the most the
 * dock ever shows at once -- a settlement has no seam to mine, and a mine can
 * only run out in the field -- so where-you-are and what-you-can-do still fit
 * on one line, and the dock stays a band rather than growing into a panel.
 */
@media (max-width: 560px) {
  .dock,
  .card {
    width: 100%;
    min-width: 0;
    max-width: none;
  }

  .inner {
    gap: 8px;
    padding: 8px 10px 9px 11px;
  }

  .where {
    max-width: none;
    gap: 2px;
  }

  /*
   * §9.5.3 -- a pack owns the hex, so the hex's own business is not on offer:
   * you may not mine, gather, hunt or travel while it stands there. On a phone
   * the two columns fought for the same 130px and the place name lost, which
   * left the dock reading "OPEN COUNTRY · O…" over a fight nobody could see the
   * terms of. The pack takes the row; where you are standing keeps the line
   * above it.
   */
  .inner:has(.pinned) {
    flex-wrap: wrap;
  }

  .inner:has(.pinned) .where {
    flex: 1 0 100%;
  }

  .inner:has(.pinned) .actions {
    margin-left: 0;
  }

  .pinned {
    flex: 1;
    min-width: 0;
  }

  .named {
    gap: 7px;
  }

  .place {
    font-size: 13.5px;
  }

  .actions {
    gap: 2px;
  }

  .rule {
    margin: 2px 1px 11px;
  }
}

/* §9.5.3 -- the pin reads as a statement, not a disabled control. Nothing here
   is a button, because the two ways out are a fight that has its own cell and a
   clock that needs no help. */
.pinned {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 2px 2px 4px;
}

/* The name and its clock on one line: who is here, and how long for. */
.who {
  display: flex;
  align-items: baseline;
  gap: 9px;
  flex-wrap: wrap;
}

/* The loudest thing on the plate, because it is the thing that stopped you. It
   was `tiny`, the same size as the three sentences under it, which left the
   plate reading as four notes of equal weight instead of a name and its terms. */
.name {
  font-family: var(--font-display);
  font-size: 15px;
  line-height: 1.2;
}

/*
 * "leaves in 5m 46s" rather than a line of its own reading "Moves on in ...":
 * two words is enough to say which clock it is, and a bare figure beside a
 * monster reads as how long until it hits you rather than until it goes.
 */
.leaves {
  color: var(--vellum-dim);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.pinned .warn {
  color: var(--ember);
}

/* §13.3 -- ember is a state to deal with, and the terms of a loss are one. Two
   ember lines in a row would be two alarms, so the standing one is dimmed a
   step and a gear warning keeps the full colour. */
.pinned .warn.terms {
  color: #96534f;
}

.knows {
  justify-content: flex-start;
  margin-top: 3px;
}
</style>
