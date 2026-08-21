<script setup lang="ts">
/**
 * Root of the oddity-drops proposal page.
 *
 * Same shape as the almanac and for the same reason: this is a document, not a
 * screen you open mid-trip. It takes no character, makes no request, and has no
 * business loading the map to be read.
 *
 * The one thing the strip has that the almanac's does not is the status stamp.
 * A design proposal that looks like documentation is dangerous — somebody will
 * read these numbers as the rules — so the page says what it is before it says
 * anything else.
 */
import OddityView from '@/views/OddityView.vue'
import { ACTION_PATHS } from '@/icons/actions'
</script>

<template>
  <div class="page">
    <header class="strip">
      <span class="mark">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.effectCraftOption" />
        </svg>
      </span>

      <div class="grow">
        <div class="title">
          <h1>Oddity Drops</h1>
          <span class="stamp">Proposal — not implemented</span>
        </div>
        <p class="tiny muted">
          Amends §4 · §5.1 · §7.3 · §8.0 · §8.1 · §11. A second roll on every worked
          hex, twenty-two new materials, a bare-handed forage verb, and retiring the
          <code>yield</code> stat for cooldown — with nine decisions, one now settled.
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
      <OddityView />
    </div>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: var(--ink);
}

.strip {
  flex: 0 0 auto;
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

.grow {
  flex: 1 1 auto;
  min-width: 0;
}

.title {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.strip h1 {
  font-size: 18px;
}

/* Says what the page is before it says anything else — these numbers are a
   pitch, not the rules, and the difference has to be unmissable. */
.stamp {
  padding: 3px 8px;
  background: var(--copper);
  color: #17110c;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  clip-path: polygon(5px 0, 100% 0, 100% calc(100% - 5px), calc(100% - 5px) 100%, 0 100%, 0 5px);
}

.strip p {
  margin: 3px 0 0;
  line-height: 1.45;
  max-width: 88ch;
}

.strip code {
  font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
  color: var(--copper);
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: 0 0 auto;
  padding: 6px 12px;
  border: 1px solid var(--line);
  background: var(--ink);
  color: var(--vellum-dim);
  font-size: 11.5px;
  font-weight: 600;
  text-decoration: none;
  clip-path: polygon(7px 0, 100% 0, 100% calc(100% - 7px), calc(100% - 7px) 100%, 0 100%, 0 7px);
}

.back:hover {
  border-color: var(--copper);
  color: var(--vellum);
}

.body {
  flex: 1 1 auto;
  min-height: 0;
}

@media (max-width: 560px) {
  .strip {
    padding: 10px 12px;
  }

  /* Same call as the almanac: on a phone the blurb costs a third of the screen
     to say what the document below says properly. The stamp stays. */
  .strip p {
    display: none;
  }
}
</style>
