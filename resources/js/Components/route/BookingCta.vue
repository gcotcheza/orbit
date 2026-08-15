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
 *
 * TWO VARIANTS, BECAUSE THE SCREEN ABOVE IT DOES NOT ALWAYS AGREE WITH IT. The
 * accent fill is the loudest element on the route detail, and it was drawn at
 * full volume under a callout reading "Above usual — wait": the page said hold
 * off and then put a glowing Book button under it, which is the app arguing
 * with itself in front of somebody about to spend money. `secondary` is the
 * same link, the same size and the same tap target, drawn as an outline — the
 * hand-off is still there for somebody who has decided anyway, it is simply no
 * longer the conclusion. WHICH one is the caller's call: this component is not
 * told the advice, only how loudly to say its own line, because a button that
 * read the verdict itself would be a second opinion about it.
 */
defineProps({
  url: { type: String, required: true },

  /** 'primary' by default; 'secondary' when the advice is a warning. */
  variant: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'secondary'].includes(value),
  },
})
</script>

<template>
  <div class="booking">
    <a class="booking__cta" :class="`booking__cta--${variant}`" :href="url" target="_blank" rel="noopener">
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
}

.booking__cta--primary {
  background: var(--accent);
  color: var(--on-solid);
  box-shadow: 0 8px 20px var(--accent-glow);
}

.booking__cta--primary path {
  stroke: var(--on-solid);
}

/* The outline variant. Same box, same 54 px target, no fill and no glow — the
   card surface with a hairline, which is the quietest thing this palette can
   draw that is still unmistakably a control. The accent survives as the TEXT
   colour so it still reads as the one link on the screen that leaves it. */
.booking__cta--secondary {
  background: var(--card);
  color: var(--accent-ink);
  border: 1px solid var(--line);
}

.booking__cta--secondary path {
  stroke: var(--accent-ink);
}

.booking__disclaimer {
  margin-top: 10px;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
}
</style>
