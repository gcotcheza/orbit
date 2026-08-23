<script setup>
/*
 * "A new version is ready." — the one thing an installed app cannot work out for itself.
 * Quiet, dismissible, and `role="status"` rather than `alert`: nothing has gone wrong.
 */
defineProps({
  /**
   * Is the five-item tab bar on screen? It is fixed to the bottom, so the toast has to clear it
   * — and on the two screens without one, clearing it would leave the toast floating.
   */
  aboveTabBar: { type: Boolean, default: false },
})

defineEmits(['refresh', 'dismiss'])
</script>

<template>
  <div class="toast" :class="{ 'toast--above-tabs': aboveTabBar }" role="status">
    <p class="toast__text">A new version is ready</p>

    <button type="button" class="toast__go" @click="$emit('refresh')">Refresh</button>

    <button type="button" class="toast__close" aria-label="Dismiss" @click="$emit('dismiss')">
      <!-- Stroked from the style block, like every other glyph in this app: a
           var() in a presentation attribute is not portable. -->
      <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
        <path d="M3.5 3.5l7 7M10.5 3.5l-7 7" stroke-width="1.6" stroke-linecap="round" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
/* Fixed to the viewport, then centred on the shell's own column — the day sheet's trick, so on a
   laptop the toast belongs to the phone. */
.toast {
  position: fixed;
  inset-inline: 0;
  bottom: calc(14px + env(safe-area-inset-bottom));
  z-index: 30;

  display: flex;
  align-items: center;
  gap: 10px;

  width: calc(100% - 2 * var(--gutter));
  max-width: calc(var(--shell-max) - 2 * var(--gutter));
  margin-inline: auto;
  padding: 10px 10px 10px 14px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card);
  box-shadow: var(--shadow);

  animation: orbit-rise 0.4s ease backwards;
}

/* Inside the desktop frame --shell-max is `none`; this stays a phone-width card. The query is
   lib/layout.js's, so the phone cannot reach the rule at all (docs/DESKTOP-LAYOUT-PLAN.md). */
@media (min-width: 768px) and (min-height: 600px) {
  .app-shell--rail .toast {
    max-width: calc(var(--app-width) - 2 * var(--gutter));
  }
}

.toast--above-tabs {
  bottom: calc(var(--tab-bar-height) + env(safe-area-inset-bottom) + 10px);
}

.toast__text {
  flex: 1;
  min-width: 0;
  font-size: var(--text-lg);
  color: var(--ink);
}

/* The accent, because in this app the accent means "an action" — the tab bar's
   +, the booking hand-off and the day-1 call to action are the others. */
.toast__go {
  flex-shrink: 0;
  padding: 8px 14px;
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 700;

  color: var(--on-solid);
  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.toast__close {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  /* A finger's worth of target on a glyph that is 13 px, with the padding
     pulled back out of the layout so it does not stretch the toast. */
  width: 30px;
  height: 30px;
  margin-right: -2px;

  color: var(--muted);
}

.toast__close path {
  stroke: currentColor;
}
</style>
