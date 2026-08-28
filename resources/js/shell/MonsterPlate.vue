<script setup lang="ts">
/**
 * §9.5.2 -- what a monster IS, before you decide to close with it.
 *
 * The pin already says who is standing here and the preview says whether you
 * win; neither says what the thing is. That gap is the whole reason this
 * exists: a player meeting a Kiln Tortoise for the first time can read its
 * numbers off a fight they have not taken yet, rather than learning them by
 * losing a coat.
 *
 * Every figure here is the CATALOG's, not the fight's. A monster's attack and
 * hp are the same for everybody and the client already mirrors them (§16 is
 * about state, not about static data), so this is a pure read -- no request, no
 * store, and correct while the fight preview is still in flight.
 *
 * What it deliberately does NOT show is your side of it. Whether you win, what
 * it costs and which of your gear dies are the preview's, thirty pixels away
 * on the same plate, and saying them twice would make two answers out of one.
 */
import { computed } from 'vue'
import { MATERIALS } from '@/game/catalog'
import { MONSTERS } from '@/game/monsters'
import { TROPHY_BY_TIER } from '@/game/spoils'
import { monsterCrest } from '@/icons/combatants'
import SvgIcon from '@/components/SvgIcon.vue'
import type { MaterialKey } from '@/game/types'

const props = defineProps<{ monster: string }>()
defineEmits<{ close: [] }>()

const def = computed(() => MONSTERS[props.monster] ?? null)

const crest = computed(() =>
  def.value ? monsterCrest(def.value.profile, def.value.tier, 64, false, def.value.key) : '',
)

/** §9.5.2 -- the profile is what a player reads instead of a level. */
const PROFILE_NOTE: Record<string, string> = {
  brute: 'Hits hard, guards badly. It empties your kit fast and empties fast in turn.',
  carapace: 'Guards hard, hits badly. Getting through the front is the whole fight.',
  swift: 'Middling at both, and it wears a weapon harder than its numbers suggest.',
}

/**
 * §9.5.6 -- and the one number a profile does not explain.
 *
 * `wearBias` moves where the bill lands, never how big it is, so it is said as
 * a sentence rather than as a figure: "1.5" means nothing at the moment you are
 * deciding whether to swing at something.
 */
const wearNote = computed(() =>
  def.value && def.value.wearBias > 1
    ? 'Blunts what it is hit with — more of the bill lands on your weapon and gloves.'
    : null,
)

/** §9.5.8 -- what it pays. Named, never with odds: odds would be a spreadsheet. */
const pays = computed(() => {
  const d = def.value
  if (!d) return []

  const name = (key: string) => MATERIALS[key as MaterialKey]?.name ?? key

  return [
    { label: 'Gold', value: `${d.gold[0]}–${d.gold[1]}`, note: 'needs no strap' },
    { label: 'Always', value: name(d.plate), note: 'plate line' },
    { label: 'Often', value: name(d.ichor), note: 'ichor line' },
    ...(d.rareSpoil ? [{ label: 'Rarely', value: name(d.rareSpoil), note: 'the grade above' }] : []),
    ...(TROPHY_BY_TIER[d.tier]
      ? [{ label: 'Leavings', value: name(TROPHY_BY_TIER[d.tier]!), note: 'worth a gold' }]
      : []),
  ]
})
</script>

<template>
  <div v-if="def" class="wrap" role="dialog" :aria-label="def.name" @click="$emit('close')">
    <div class="plate" @click.stop>
      <div class="inner">
        <header class="head">
          <SvgIcon class="crest" :svg="crest" />
          <div class="grow">
            <span class="label eyebrow">{{ def.profile }} · tier {{ def.tier }}</span>
            <strong class="name">{{ def.name }}</strong>
          </div>
          <button class="close" type="button" aria-label="Close" @click="$emit('close')">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </header>

        <p class="tiny muted blurb">{{ def.description }}</p>

        <!-- §9.5.4/§9.5.5 -- the three solid numbers a fight is decided by, and
             the pool is one of them: durability IS the health bar on both sides
             of the exchange, so it belongs beside the pair rather than under a
             heading of its own. -->
        <div class="figures">
          <span class="fig">
            <span class="label">Attack</span>
            <strong class="mono">{{ def.attack }}</strong>
          </span>
          <span class="fig">
            <span class="label">Defense</span>
            <strong class="mono">{{ def.defense }}</strong>
          </span>
          <span class="fig">
            <span class="label">Pool</span>
            <strong class="mono">{{ def.hp }}</strong>
          </span>
        </div>

        <p class="tiny profile">{{ PROFILE_NOTE[def.profile] }}</p>
        <p v-if="wearNote" class="tiny wear">{{ wearNote }}</p>

        <dl class="pays">
          <div v-for="p in pays" :key="p.label" class="pay">
            <dt class="label">{{ p.label }}</dt>
            <dd>
              <strong class="tiny">{{ p.value }}</strong>
              <span class="tiny muted note">{{ p.note }}</span>
            </dd>
          </div>
        </dl>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wrap {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: grid;
  place-items: center;
  padding: 18px;
  background: rgba(8, 11, 10, 0.55);
}

.plate {
  position: relative;
  width: min(330px, 100%);
  max-height: 100%;
  overflow-y: auto;
}

.inner {
  padding: 13px 14px 14px;
}

.head {
  display: flex;
  align-items: center;
  gap: 11px;
}

.crest {
  flex: 0 0 auto;
  line-height: 0;
}

.name {
  display: block;
  font-size: 15px;
}

.eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.14em;
  font-size: 8.5px;
  color: var(--vellum-dim);
}

.close {
  align-self: flex-start;
  padding: 0;
  border: 0;
  background: none;
  color: var(--vellum-dim);
  cursor: pointer;
}

.close:hover {
  color: var(--vellum);
}

.blurb {
  margin: 9px 0 0;
  line-height: 1.55;
}

/* Solid numbers get a block rather than a meter (§9.5.4): a meter implies a
   roof, and none of these has one. */
.figures {
  display: flex;
  gap: 8px;
  margin-top: 11px;
}

.fig {
  flex: 1 1 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 6px 9px;
  background: rgba(0, 0, 0, 0.28);
}

.fig strong {
  font-family: var(--font-display);
  font-size: 17px;
  line-height: 1;
}

.profile {
  margin: 9px 0 0;
  line-height: 1.5;
  color: var(--vellum-dim);
}

/* §13.3 -- copper for "yes, but". A wear bias is not a warning about danger,
   it is a note about where the bill lands. */
.wear {
  margin: 5px 0 0;
  line-height: 1.5;
  color: var(--copper);
}

.pays {
  margin: 11px 0 0;
  padding-top: 9px;
  border-top: 1px solid var(--line);
}

.pay {
  display: flex;
  align-items: baseline;
  gap: 9px;
}

.pay + .pay {
  margin-top: 5px;
}

.pay dt {
  flex: 0 0 66px;
}

.pay dd {
  display: flex;
  align-items: baseline;
  gap: 7px;
  margin: 0;
  min-width: 0;
}
</style>
