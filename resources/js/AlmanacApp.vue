<script setup lang="ts">
/**
 * Root of the almanac page.
 *
 * The game shell floats its screens over the map as cut plates. This one has no
 * map to float over, so it is a plain full-height document: a title strip that
 * says what the page is and how to get back, and the reference itself owning
 * the rest of the height and its own scroll.
 */
import AlmanacView from '@/views/AlmanacView.vue'
import { ACTION_PATHS } from '@/icons/actions'
</script>

<template>
  <div class="page">
    <header class="strip">
      <span class="mark">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.almanac" />
        </svg>
      </span>
      <div class="grow">
        <h1>Almanac</h1>
        <p class="tiny muted">
          Reference. Every material and every item in the catalog, and the road each one
          arrives by — read straight from the static data, so it is the same for every
          character and for none.
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
      <AlmanacView />
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

.strip h1 {
  font-size: 18px;
}

.strip p {
  margin: 3px 0 0;
  line-height: 1.45;
  max-width: 82ch;
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

  /* The blurb is the first thing to go: on a phone it costs a third of the
     screen to say something the entries below already show. */
  .strip p {
    display: none;
  }
}
</style>
