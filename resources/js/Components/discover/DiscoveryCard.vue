<script setup>
/*
 * One route Orbit went and found on its own.
 *
 * THE SIBLING OF Components/globe/SpotlightCard.vue AND DELIBERATELY NOT A
 * COPY OF IT. That card summarises a WATCHED route: a deal score, a verdict
 * pill, a fortnight of sparkline, a percentage below usual — every one of which
 * exists because Orbit has been polling the route for weeks. A discovery has
 * none of that and never will: it is a route nobody watches, usually one Orbit
 * has never priced, and the whole card has to stand on evidence gathered in a
 * single run.
 *
 * So the layout rhymes — big city name, big price, a quiet line underneath —
 * and the CONTENT is different in the one way that matters: this card shows its
 * working. Where the spotlight card says "33% below usual" on the strength of
 * months of history, this one says what it cost, when the price was seen, and
 * whether anybody other than Travelpayouts agrees.
 *
 * =============================================================================
 * THE BADGE IS THE POINT OF THE WHOLE FEATURE, SO IT IS THE FUSSIEST PART
 * =============================================================================
 * There are two states and they are NOT a good/bad pair:
 *
 *   VERIFIED    Google was asked and its own market agrees this route and date
 *               are cheap right now. Rare, earned, and the only state allowed
 *               to look like a claim.
 *   UNVERIFIED  Everything else — no SerpAPI key (the default on this box), no
 *               quota, a run past its cap, or a route Google had no opinion
 *               about. It is the ORDINARY state and it must not read as a
 *               warning: nothing is wrong, Orbit simply did not get a second
 *               opinion and is saying so.
 *
 * Hence the unverified badge is `--muted` on `--card2` and not the warn tint.
 * A yellow "unverified" on nine cards out of ten would train the owner to read
 * the whole strip as suspect, which is the opposite of what the honesty is for.
 *
 * THE LABEL COMES FROM THE SERVER (`verdict.label`), exactly as VerdictPill's
 * does and for the same reason: it is a claim about a third party, and a
 * hard-coded "Verified low by Google" in a template is a sentence that goes on
 * being said the day the check behind it is switched off. This component styles
 * by `verdict.verified`; it does not compose the words.
 *
 * =============================================================================
 * IT IS A LINK, AND WHERE IT GOES IS THE REUSE
 * =============================================================================
 * A real <a> into `/route/AMS-AGP` — long-pressable, openable in a new tab,
 * announced as a link. That screen prices the pair through `POST /api/routes/
 * lookup` if Orbit has nothing recent, creates the route row, and offers the
 * watch button (docs/API.md, "look before you watch"). So tapping a discovery
 * costs nothing until somebody is interested, and this feature added no booking
 * link, no watch action and no second detail screen of its own.
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { departureLabel, euro, seenLabel } from '@/lib/format'

const props = defineProps({
    /** One `GET /api/discoveries` row. */
    discovery: { type: Object, required: true },
})

const price = computed(() => euro(props.discovery.price))

const departure = computed(() => departureLabel(props.discovery.departureDate))

/*
 * HOW OLD THE PRICE IS, AND IT IS ALWAYS DRAWN WHEN IT IS KNOWN — where the
 * route detail only prints it past a threshold.
 *
 * A discovery is the least verified thing in the app: it comes out of a
 * seven-day-deep cache of other people's searches, and the run that found it
 * accepted anything up to three days old (`orbit.discovery.max_found_age_days`).
 * On the detail screen an age is a caveat on a number Orbit polls every
 * morning; here it is part of the number. Null renders as nothing at all rather
 * than as fresh — the rule this whole field exists to enforce.
 */
const seen = computed(() => seenLabel(props.discovery.foundAt))

/**
 * The one-line case for this card, or null if there is no case to make.
 *
 * BUILT FROM WHAT THE SERVER MEASURED, and it says nothing when the server
 * measured nothing: `percentile` and `savings` are both null when the
 * verification stage could not fetch the route's own window, which is the
 * ordinary outcome on an obscure pair (Travelpayouts' calendar coverage runs
 * 41–87% even on watched routes). A card with no line here is honest rather
 * than broken.
 *
 * "CHEAPEST" RATHER THAN "0TH PERCENTILE", because a percentile is a word for a
 * report and this is a sentence under a price. The threshold for a discovery to
 * exist at all is the cheapest tenth, so every card that has this line is
 * saying something strong — and the €-figure beside it is what makes it
 * concrete.
 */
const evidence = computed(() => {
    const { percentile, savings } = props.discovery

    if (percentile === null || percentile === undefined) {
        return null
    }

    const where = percentile === 0
        ? 'Cheapest date on this route'
        : `Cheaper than ${Math.round(100 - percentile)}% of dates`

    return savings ? `${where} · €${Math.round(savings)} under its usual` : where
})
</script>

<template>
  <RouterLink
    class="find"
    :to="{ name: 'route-detail', params: { id: discovery.code } }"
  >
    <div class="find__head">
      <div class="find__where">
        <p class="find__from">{{ discovery.origin.iata }} → {{ discovery.destination.iata }}</p>
        <h3 class="find__city">{{ discovery.destination.city }}</h3>
        <p class="find__country">{{ discovery.destination.country }}</p>
      </div>

      <div class="find__money">
        <p class="find__price tabular">{{ price }}</p>
        <p v-if="departure" class="find__when">{{ departure }}</p>
      </div>
    </div>

    <p v-if="evidence" class="find__evidence">{{ evidence }}</p>

    <div class="find__foot">
      <!-- `data-verified` RATHER THAN A CLASS BINDING, so the two states are two
           attribute selectors in the stylesheet below and this template ships no
           colour logic — the same call VerdictPill makes. -->
      <span class="find__badge" :data-verified="discovery.verdict.verified">
        <svg
          v-if="discovery.verdict.verified"
          width="12"
          height="12"
          viewBox="0 0 12 12"
          fill="none"
          aria-hidden="true"
        >
          <path
            d="M2.5 6.2l2.3 2.3 4.7-4.8"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
        {{ discovery.verdict.label }}
      </span>

      <span v-if="seen" class="find__seen">seen {{ seen }}</span>
    </div>
  </RouterLink>
</template>

<style scoped>
.find {
  display: block;
  padding: 14px 15px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);

  background: var(--card);
  text-decoration: none;
}

.find__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.find__from {
  font-family: var(--font-display);
  font-size: var(--text-xs);
  font-weight: 700;
  letter-spacing: 0.13em;
  color: var(--accent-ink);
}

/* An h3, not an h2: the section this sits in has the h2. The visual weight is
   the spotlight card's city minus a step, because a strip of these stacks. */
.find__city {
  margin-top: 3px;
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink);
}

.find__country {
  margin-top: 1px;
  font-size: var(--text-sm);
  color: var(--muted);
}

.find__money {
  text-align: right;
  /* The city name gets the leftovers; the price never wraps. */
  flex-shrink: 0;
}

.find__price {
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  line-height: 1;
  color: var(--ink);
}

/* Directly under the price and in the accent ink, so the pair reads as one
   fact — "€27, Fri Aug 21" — exactly as the spotlight card does it. */
.find__when {
  margin-top: 5px;
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--accent-ink);
}

.find__evidence {
  margin-top: 9px;
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--ink2);
}

.find__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 11px;
}

.find__badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;

  padding: 4px 9px;
  border-radius: var(--radius-pill);

  font-size: var(--text-sm);
  font-weight: 600;
}

/* EARNED, AND IT LOOKS IT. The only place on this card with a colour that
   means something. */
.find__badge[data-verified='true'] {
  background: var(--good-bg);
  color: var(--good-ink);
}

/* THE ORDINARY STATE, AND IT IS NOT A WARNING — see the script's long note.
   Muted on the card's own second surface: present, legible, unalarming. */
.find__badge[data-verified='false'] {
  background: var(--card2);
  color: var(--muted);
}

.find__seen {
  flex-shrink: 0;
  font-size: var(--text-sm);
  color: var(--muted);
}
</style>
