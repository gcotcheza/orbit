<script setup>
/*
 * "A new version is ready."
 *
 * THE ONE THING AN INSTALLED APP CANNOT WORK OUT FOR ITSELF. A PWA on a home
 * screen is a page opened once and never closed: it keeps running the JavaScript
 * it downloaded the morning it was launched, whatever the service worker has
 * quietly fetched since. The owner deployed and then looked at the old screens
 * for hours — nothing was broken, and nothing said anything. This is the
 * sentence that was missing. See resources/js/lib/pwa.js for how it finds out.
 *
 * QUIET, AND DISMISSIBLE. It is news rather than a problem: the app underneath
 * it works, the fares in it are today's (nothing with a price in it is ever
 * cached — service-worker.js), and the only thing out of date is the code. So it
 * takes the app's card treatment rather than a tone colour, sits out of the
 * thumb's way, and goes away when told.
 *
 * `role="status"` and not `alert`: nothing has gone wrong, and an assertive
 * announcement over whatever a screen-reader user was reading would be this app
 * interrupting them to talk about itself.
 */
defineProps({
  /**
   * Is the five-item tab bar on screen? It is fixed to the bottom of the
   * viewport, so the toast has to clear it — and on the two screens without one
   * (route detail, login) clearing it would leave the toast floating.
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
/* Fixed to the viewport rather than to the shell, and then centred on the shell's
   own column — the same trick the day sheet uses, so on a laptop the toast
   belongs to the phone in the middle of the window rather than to the window. */
.toast {
  position: fixed;
  inset-inline: 0;
  bottom: calc(14px + env(safe-area-inset-bottom));
  z-index: 30;

  display: flex;
  align-items: center;
  gap: 10px;

  width: calc(100% - 2 * var(--gutter));
  max-width: calc(var(--app-width) - 2 * var(--gutter));
  margin-inline: auto;
  padding: 10px 10px 10px 14px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card);
  box-shadow: var(--shadow);

  animation: orbit-rise 0.4s ease backwards;
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
