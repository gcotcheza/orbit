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
 * fare. The primary link is now the search the price came from; Skyscanner is
 * the second opinion, because it is worth having and it is the site the owner
 * already knows.
 *
 * =============================================================================
 * AND THE SECOND OPINION IS A BUTTON NOW, NOT A LINE OF TEXT
 * =============================================================================
 * It shipped as a 12 px centred text link under the button, on the argument
 * written here at the time: "two buttons is a choice the reader has no basis for
 * making". The owner used it and disagreed, which settles it — on a phone that
 * line does not read as something you can press at all. It was the quietest
 * thing on the screen apart from the disclaimer directly under it, in the same
 * grey, at nearly the same size, and it is one of only two controls on this
 * page that do anything.
 *
 * SO THEY ARE A PAIR, AND THEY ARE NOT EQUALS. Side by side, Skyscanner on the
 * left in the outline treatment and Aviasales on the right with the accent and
 * roughly six-tenths of the width. The reader's basis for choosing is exactly
 * the hierarchy the sizes draw: the loud one is where Orbit's own number came
 * from, the quiet one is a sanity check.
 *
 * BOTH GO QUIET WHEN THE ADVICE IS A WARNING. See the note on `variant` below —
 * with two controls on the line, leaving the accent on one of them would keep
 * the page arguing with itself, just more quietly. The accent is reserved for
 * the case where the app is actually saying yes.
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

  /**
   * 'primary' by default; 'secondary' when the advice is a warning.
   *
   * WITH TWO CONTROLS ON THE LINE THIS DEMOTES THE WHOLE PAIR, because the
   * Skyscanner half is drawn as an outline in every state — so `secondary` is
   * what makes "the app is not saying yes" true of the entire hand-off rather
   * than of one button next to a glowing one.
   */
  variant: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'secondary'].includes(value),
  },
})
</script>

<template>
  <div class="booking">
    <!--
      LEFT THEN RIGHT, AND THE ORDER IS THE OWNER'S: the check before the act.
      Reading order and tap order agree, and the thing that spends money is the
      one furthest from where a thumb rests by accident.
    -->
    <div class="booking__actions">
      <a
        v-if="skyscannerUrl"
        class="booking__link booking__compare"
        :href="skyscannerUrl"
        target="_blank"
        rel="noopener"
      >
        <span>Compare on Skyscanner</span>
      </a>

      <a
        class="booking__link booking__cta"
        :class="`booking__cta--${variant}`"
        :href="aviasalesUrl"
        target="_blank"
        rel="noopener"
      >
        <span>See this fare on Aviasales</span>
        <!-- Stroked from the style block, on the accent fill — see the note in
             AdviceCallout.vue. Only the loud one carries it: two outward arrows
             on one line is decoration, and this is the one that is the point. -->
        <svg width="16" height="16" viewBox="0 0 17 17" fill="none" aria-hidden="true">
          <path d="M5 12L12 5M12 5H6M12 5v6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </a>
    </div>

    <p class="booking__disclaimer">
      Prices come from recent searches — the booking site shows live availability.
    </p>
  </div>
</template>

<style scoped>
/*
 * THE SPLIT IS 4:6 AND IT IS THE HIERARCHY, not a grid. The reader has to be
 * able to tell at a glance which of these two Orbit's own number came out of,
 * and on a line with no fill to look at — the warning case, where both are
 * outlines — the width is the only thing left saying so.
 *
 * `min-width: 0` on the children because they are flex items containing text
 * that must be allowed to wrap: without it a flex item refuses to shrink below
 * its content's intrinsic width and the 4:6 quietly becomes whatever the labels
 * happen to measure.
 */
.booking__actions {
  display: flex;
  gap: 10px;
  margin-top: 16px;
}

/*
 * THE LABELS WRAP RATHER THAN TRUNCATE OR SHRINK. "Compare on Skyscanner" and
 * "See this fare on Aviasales" do not fit on one line each inside a 354 px
 * content column, and every way of making them fit is worse than two lines:
 * ellipsis hides which site it is, a smaller type size makes the pair look like
 * fine print again — which is the defect being fixed — and cutting the labels to
 * "Skyscanner" and "Aviasales" throws away the verbs that say these leave the
 * app. On a wider shell they take one line each and nothing here changes.
 */
.booking__link {
  flex: 4;
  min-width: 0;

  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;

  /* A floor and not a height: the 54 px the design gives the hand-off, kept as
     the minimum so that two lines of label grow the pair instead of spilling
     out of it. */
  min-height: 54px;
  padding: 8px 12px;
  border-radius: 16px;

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  line-height: 1.2;
  text-align: center;
  text-decoration: none;
}

.booking__cta {
  flex: 6;
}

.booking__cta--primary {
  background: var(--accent);
  color: var(--on-solid);
  box-shadow: 0 8px 20px var(--accent-glow);
}

.booking__cta--primary path {
  stroke: var(--on-solid);
}

/*
 * The outline variant, and now BOTH buttons wear it when the advice is a
 * warning. Same box, same tap target, no fill and no glow — the card surface
 * with a hairline, which is the quietest thing this palette can draw that is
 * still unmistakably a control. The accent survives as the TEXT colour, which is
 * what keeps the Aviasales link the louder of the two quiet ones.
 */
.booking__cta--secondary {
  background: var(--card);
  color: var(--accent-ink);
  border: 1px solid var(--line);
}

.booking__cta--secondary path {
  stroke: var(--accent-ink);
}

/* The second opinion, always in the outline treatment: it is a check on the
   number above, never the conclusion. `--ink2` rather than the accent, so that
   under a warning — when the Aviasales button is an outline too — the pair still
   reads in the right order. */
.booking__compare {
  background: var(--card);
  color: var(--ink2);
  border: 1px solid var(--line);
}

.booking__disclaimer {
  margin-top: 10px;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
}
</style>
