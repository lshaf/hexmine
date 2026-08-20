<script setup lang="ts">
/**
 * A panel over the map. Sized so the world stays visible around it -- the map is
 * the screen, and everything else is something you opened on top of it.
 */
defineProps<{
  title: string
  /** For content that wants the room and does its own scrolling -- the atlas. */
  wide?: boolean
}>()
defineEmits<{ (e: 'close'): void }>()
</script>

<template>
  <div class="wrap" role="dialog" :aria-label="title">
    <div class="scrim" @click="$emit('close')" />
    <div class="panel plate" :class="{ wide }">
      <div class="inner">
        <header class="head">
          <h2>{{ title }}</h2>
          <button class="close" type="button" aria-label="Close" @click="$emit('close')">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </header>
        <div class="body" :class="wide ? 'flush' : 'scroll'">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wrap {
  position: absolute;
  inset: 0;
  z-index: var(--z-panel);
  display: grid;
  place-items: center;
  padding: 18px;
}

.scrim {
  position: absolute;
  inset: 0;
  background: rgba(8, 11, 10, 0.6);
  backdrop-filter: blur(2px);
}

.panel {
  position: relative;
  width: min(580px, 100%);
  max-height: min(78vh, 720px);
  display: flex;
  flex-direction: column;
}

.panel.wide {
  width: min(1040px, 100%);
  height: min(86vh, 820px);
  max-height: none;
}

.inner {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.head {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 13px 16px 11px;
  border-bottom: 1px solid var(--hud-line-soft);
}

.head h2 {
  font-size: 17px;
}

.close {
  color: var(--vellum-dim);
  padding: 3px;
}

.close:hover {
  color: var(--vellum);
}

.body {
  flex: 1 1 auto;
  min-height: 0;
  padding: 14px 16px 18px;
}

.body.flush {
  padding: 0;
  overflow: hidden;
}

@media (max-width: 560px) {
  .wrap {
    padding: 10px;
  }

  .panel {
    max-height: 84vh;
  }
}
</style>
