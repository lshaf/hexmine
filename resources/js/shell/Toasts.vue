<script setup lang="ts">
/**
 * Transient feedback, clear of the controls.
 *
 * An idle game gives most of its news on a delay, so when something does happen
 * immediately it has to be visible without standing in front of the controls --
 * which is why it takes the top edge on a wide screen and the empty band just
 * above the bottom stack on a phone, where the top edge is already spoken for.
 * It is pointer-transparent throughout; nothing here is ever clickable.
 *
 * The marker is a draining hexagon, the same perimeter-as-progress idea the
 * gauges use (§13.1). It carries two things at once: color says how the news
 * landed, and the drain says how long it will stay -- so a message that vanishes
 * mid-read was visibly on its way out.
 *
 * §14 item 9 is the full notification design; this is the floor.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'

const game = useGame()

const LIFETIME = 4200

/** Flat-top hexagon with side length 50, so the perimeter is exactly 300. */
const POINTS = '0,43.3 25,0 75,0 100,43.3 75,86.6 25,86.6'
const PERIMETER = 300

/** The same hexagon shrunk about its center, sitting inside the draining ring. */
const CORE = POINTS.split(' ')
  .map((pair) => {
    const [x, y] = pair.split(',').map(Number)
    return `${50 + (x! - 50) * 0.44},${43.3 + (y! - 43.3) * 0.44}`
  })
  .join(' ')

const visible = computed(() =>
  game.log.filter((entry) => game.now - entry.at < LIFETIME).slice(0, 3),
)
</script>

<template>
  <div class="toasts" :style="{ '--life': `${LIFETIME}ms` }">
    <TransitionGroup name="drop">
      <div v-for="entry in visible" :key="entry.id" class="toast plate" :class="entry.tone">
        <div class="inner">
          <svg class="mark" viewBox="-6 -6 112 98.6" aria-hidden="true">
            <polygon :points="CORE" class="core" />
            <polygon :points="POINTS" class="track" fill="none" stroke-width="9" />
            <polygon
              :points="POINTS"
              class="drain"
              fill="none"
              stroke-width="9"
              :stroke-dasharray="PERIMETER"
            />
          </svg>
          <p class="text">{{ entry.text }}</p>
        </div>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
/*
 * Top of the stacking ladder (--z-toast in app.css), and FIXED to the window
 * rather than to whatever happens to be around it.
 *
 * One place, at every width, whatever else is on screen. It used to dodge:
 * absolute inside the app shell, and on a phone it rode --stack-h so it sat
 * above the bottom dock and moved as that dock grew and shrank with what you
 * were standing on. The reasoning was that a phone has no free top edge -- the
 * gauge cluster owns the left and the corner strip the right -- so the toast
 * went looking for a clear band.
 *
 * A toast does not need a clear band. It is the topmost thing in the app and it
 * is gone in a few seconds, so overlapping the HUD costs nothing and moving
 * costs the one thing a notification cannot afford: you have to FIND it. A
 * message that appears somewhere different depending on which hex you are
 * standing on is a message you read late.
 */
.toasts {
  position: fixed;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 7px;
  pointer-events: none;
  z-index: var(--z-toast);
}

.toast {
  --tone: var(--copper);
  width: fit-content;
  max-width: min(430px, calc(100vw - 32px));
}

.inner {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 14px 9px 11px;
}

.mark {
  flex: 0 0 auto;
  width: 23px;
  height: 20px;
}

.core {
  fill: var(--tone);
}

.track {
  stroke: var(--hud-line-soft);
}

/*
 * Drains clockwise from the left vertex over the toast's own lifetime. Driven by
 * CSS rather than the store clock, which only ticks once a second.
 */
.drain {
  stroke: var(--tone);
  animation: drain var(--life) linear forwards;
}

@keyframes drain {
  from {
    stroke-dashoffset: 0;
  }
  to {
    stroke-dashoffset: 300;
  }
}

.text {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.35;
  color: var(--vellum);
}

.good {
  --tone: var(--sap);
}

.bad {
  --tone: var(--ember);
}

.bad .text {
  color: #e8a49f;
}

/* Drops in from above -- the direction it arrives from is the direction it lives. */
.drop-enter-active {
  transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.drop-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.drop-move {
  transition: transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.drop-enter-from {
  opacity: 0;
  transform: translateY(-14px) scale(0.96);
}

.drop-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

@media (prefers-reduced-motion: reduce) {
  .drain {
    animation: none;
  }

  .drop-enter-active,
  .drop-leave-active,
  .drop-move {
    transition: opacity 0.2s ease;
  }

  .drop-enter-from,
  .drop-leave-to {
    transform: none;
  }
}

/*
 * A phone changes the WIDTH and nothing else. There is no room at 390px for a
 * toast to be a neat centred pill, so it spans the width -- but it spans it in
 * the same place it always is, because where a notification appears is the one
 * thing about it that must not depend on anything.
 */
@media (max-width: 560px) {
  .toasts {
    left: 8px;
    right: 8px;
    transform: none;
    align-items: stretch;
    gap: 6px;
  }

  .toast {
    width: 100%;
    max-width: none;
  }
}

@media (max-width: 560px) and (prefers-reduced-motion: no-preference) {
  .drop-enter-from {
    transform: translateY(10px) scale(0.98);
  }

  .drop-leave-to {
    transform: translateY(6px);
  }
}
</style>
