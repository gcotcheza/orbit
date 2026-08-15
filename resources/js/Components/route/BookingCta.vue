<script setup>
/*
 * The hand-off (design/README.md §2).
 *
 * AN ANCHOR, NOT A BUTTON. It leaves the app, so it has to be something a
 * phone can long-press, copy and open in a new tab — and something a screen
 * reader announces as a link.
 *
 * `rel="noopener"` is not optional on a `target="_blank"`: without it the
 * opened page gets a live `window.opener` handle back into this one.
 * `noreferrer` is deliberately NOT added — the deep link is an affiliate one
 * (docs/PLAN.md: Skyscanner, no API), and stripping the referrer is how that
 * attribution disappears.
 *
 * The disclaimer is part of the component rather than of the screen: the line
 * exists because the button looks like a checkout, and the two should never be
 * able to drift apart.
 */
defineProps({
  url: { type: String, required: true },
})
</script>

<template>
  <div class="booking">
    <a class="booking__cta" :href="url" target="_blank" rel="noopener">
      Book on Skyscanner
      <!-- Stroked from the style block, on the accent fill — see the note in
           AdviceCallout.vue. -->
      <svg width="17" height="17" viewBox="0 0 17 17" fill="none" aria-hidden="true">
        <path d="M5 12L12 5M12 5H6M12 5v6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </a>

    <p class="booking__disclaimer">We don't sell tickets — we hand you off to the airline or an OTA.</p>
  </div>
</template>

<style scoped>
.booking__cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;

  width: 100%;
  height: 54px;
  margin-top: 16px;
  border-radius: 16px;

  font-family: var(--font-display);
  font-size: 16px;
  font-weight: 700;
  text-decoration: none;

  background: var(--accent);
  color: var(--on-solid);
  box-shadow: 0 8px 20px var(--accent-glow);
}

.booking__cta path {
  stroke: var(--on-solid);
}

.booking__disclaimer {
  margin-top: 10px;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
}
</style>
