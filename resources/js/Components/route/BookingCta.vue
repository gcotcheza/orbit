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
 * `noreferrer` is deliberately NOT added — the Aviasales link is an affiliate
 * one, and stripping the referrer is how that attribution disappears.
 *
 * =============================================================================
 * TWO DESTINATIONS, AND AVIASALES IS THE LOUD ONE
 * =============================================================================
 * This used to be one button pointing at Skyscanner. Orbit showed DUS→AGP at
 * €29 and Skyscanner's cheapest for that date was €68 — because Orbit's fares
 * come from Travelpayouts, which is AVIASALES' cache, and the app was quoting
 * one meta-search and handing the reader to another that had never seen the
 * fare. The primary link is now the search the price came from; Skyscanner
 * survives as a quiet text link, because a second opinion is worth having and
 * it is the site the owner already knows.
 *
 * THE COPY SAYS "SEE THIS FARE", NOT "BOOK". Neither of these links can promise
 * a seat: Orbit is showing a cached price and the site on the other end is the
 * only party that knows what is on sale this second. "Book on Skyscanner" over
 * a price found four days ago was the app sounding more certain than it was —
 * on the one control where being wrong costs somebody a wasted click and their
 * trust in every other number on the screen.
 *
 * THE DISCLAIMER IS ONE LINE AND IT USED TO BE ABOUT SOMETHING ELSE. It said
 * "We don't sell tickets — we hand you off to the airline or an OTA", which
 * answers a question nobody was asking. What a reader needs to know, standing
 * in front of a fare that may be days old, is where the number came from and
 * who has the real one. Both facts now fit in the same sentence, which is why
 * this MERGED rather than gaining a second line: two greyed-out sentences under
 * a button is the shape of small print, and small print is not read.
 *
 * WORD FOR WORD THE DAY SHEET'S — see Components/calendar/DaySheet.vue, which
 * duplicates it rather than sharing it because what the two have in common is
 * the SENTENCE and not the layout. If this copy changes, change it there too.
 *
 * TWO VARIANTS, BECAUSE THE SCREEN ABOVE IT DOES NOT ALWAYS AGREE WITH IT. The
 * accent fill is the loudest element on the route detail, and it was drawn at
 * full volume under a callout reading "Above usual — wait": the page said hold
 * off and then put a glowing button under it, which is the app arguing with
 * itself in front of somebody about to spend money. `secondary` is the same
 * link, the same size and the same tap target, drawn as an outline — the
 * hand-off is still there for somebody who has decided anyway, it is simply no
 * longer the conclusion. WHICH one is the caller's call: this component is not
 * told the advice, only how loudly to say its own line, because a button that
 * read the verdict itself would be a second opinion about it.
 */
defineProps({
  /** The primary hand-off — the search Orbit's own price came out of. */
  aviasalesUrl: { type: String, required: true },

  /*
   * The second opinion. Null-tolerant rather than required: a response from an
   * older build carries no second link, and the honest answer to that is one
   * hand-off rather than none.
   */
  skyscannerUrl: { type: String, default: null },

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
    <a class="booking__cta" :class="`booking__cta--${variant}`" :href="aviasalesUrl" target="_blank" rel="noopener">
      See this fare on Aviasales
      <!-- Stroked from the style block, on the accent fill — see the note in
           AdviceCallout.vue. -->
      <svg width="17" height="17" viewBox="0 0 17 17" fill="none" aria-hidden="true">
        <path d="M5 12L12 5M12 5H6M12 5v6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </a>

    <!-- A TEXT LINK AND NOT A SECOND BUTTON. Two buttons is a choice the reader
         has no basis for making; this is an afterthought they can take or leave,
         drawn at the weight of one. -->
    <a
      v-if="skyscannerUrl"
      class="booking__compare"
      :href="skyscannerUrl"
      target="_blank"
      rel="noopener"
    >
      Compare on Skyscanner
    </a>

    <p class="booking__disclaimer">
      Prices come from recent searches — the booking site shows live availability.
    </p>
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

/* Quieter than the button and louder than the disclaimer under it, which is
   exactly its standing: an option, not the conclusion and not fine print. */
.booking__compare {
  display: block;
  margin-top: 12px;
  text-align: center;

  font-size: var(--text-md);
  font-weight: 600;
  color: var(--accent-ink);
  text-decoration: none;
}

.booking__disclaimer {
  margin-top: 10px;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
}
</style>
