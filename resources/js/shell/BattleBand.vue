<script setup lang="ts">
/**
 * §9.5 -- the two faces, and whatever stands between them.
 *
 * One band, drawn three times: the replay puts the round counter in the middle
 * while it runs, the receipt puts a hairline there when it is over, and the
 * brief puts the odds there before it starts. Same crests, same names, same
 * pair in the same place -- which is the whole argument for it being one
 * component. The exchange ends and the receipt arrives in the same frame, so
 * the thing you were watching has to still be the thing you are reading about;
 * two hand-kept copies of this markup drift into two screens about one fight.
 *
 * It was two copies, comment for comment, before this.
 *
 * The middle column is `auto` so a long word never squeezes a crest, and the
 * names wrap rather than truncate: a crest says what KIND of thing this is and
 * the name is the only thing saying WHICH, so "Barrow K..." is the half that
 * carries no information.
 */
import SvgIcon from '@/components/SvgIcon.vue'

defineProps<{
  /** Their crest and yours, built by the caller: what a crest is SAYING -- a
      corpse, a failing pool -- is the caller's business, not the band's. */
  theirCrest: string
  myCrest: string
  theirName: string
  theirProfile?: string
  theirAttack?: number
  theirDefense?: number
  /** §9.5.4 -- your class, or what stands in for it when nothing is armed. */
  myName?: string
  mySub?: string | null
  myAttack?: number
  myDefense?: number
  /** One frame of recoil, away from the center. The replay's, and nobody
      else's: a receipt has nothing left to flinch at. */
  struckThem?: boolean
  struckYou?: boolean
}>()
</script>

<template>
  <div class="band">
    <div class="corner them" :class="{ struck: struckThem }">
      <SvgIcon :svg="theirCrest" class="crest" />
      <span class="who">
        <strong class="name">{{ theirName }}</strong>
        <span v-if="theirProfile" class="tiny muted block kind">{{ theirProfile }}</span>
        <span v-if="theirAttack !== undefined" class="tiny muted block mono">
          {{ theirAttack }} atk · {{ theirDefense }} def
        </span>
      </span>
    </div>

    <div class="middle">
      <slot />
    </div>

    <div class="corner you" :class="{ struck: struckYou }">
      <span class="who">
        <strong class="name">{{ myName ?? 'You' }}</strong>
        <span v-if="mySub" class="tiny muted block kind">{{ mySub }}</span>
        <span v-if="myAttack !== undefined" class="tiny muted block mono">
          {{ myAttack }} atk · {{ myDefense }} def
        </span>
      </span>
      <SvgIcon :svg="myCrest" class="crest" />
    </div>
  </div>
</template>

<style scoped>
.band {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 10px;
}

.corner {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
  transition: transform 0.1s ease;
}

.corner.you {
  justify-content: flex-end;
  text-align: right;
}

/* The blow lands on the fighter, not just on the number, and each side recoils
   AWAY from the center. One frame is enough -- longer reads as a bug. */
.corner.them.struck {
  transform: translateX(-3px);
}

.corner.you.struck {
  transform: translateX(3px);
}

.crest {
  flex: 0 0 auto;
}

.who {
  min-width: 0;
}

.name {
  display: block;
  font-family: var(--font-display);
  font-size: 15px;
  line-height: 1.15;
  overflow-wrap: anywhere;
}

.block {
  display: block;
  line-height: 1.35;
}

.kind {
  text-transform: capitalize;
}

.mono {
  white-space: nowrap;
}

.middle {
  display: grid;
  place-items: center;
  padding: 0 2px;
}

@media (prefers-reduced-motion: reduce) {
  .corner {
    transition: none;
  }

  .corner.struck {
    transform: none;
  }
}

@media (max-width: 380px) {
  .crest :deep(svg) {
    width: 36px;
    height: 36px;
  }
}
</style>
