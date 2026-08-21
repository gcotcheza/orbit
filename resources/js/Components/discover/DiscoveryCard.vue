<script setup>
/*
 * Sibling of SpotlightCard.vue, deliberately not a copy: a discovery has no history, so this
 * card shows its working. Badge and verdict come from the server (docs/BUSINESS-LOGIC.md §16).
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
 * Age is always drawn when known — a discovery is the least verified thing in the app, so age
 * is part of the number here. Null renders as nothing, never as "fresh".
 */
const seen = computed(() => seenLabel(props.discovery.foundAt))

/*
 * Only a relative find gets the "for this route" line; an absolute find's price is already the
 * whole sentence. Compared against the server's lane string, not a client boolean.
 */
const isRelative = computed(() => props.discovery.lane === 'relative')

/**
 * One-line case for this card, or null when the server measured nothing — a card with no line
 * is honest, not broken. "Cheapest", not "0th percentile" (docs/BUSINESS-LOGIC.md §16).
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
        <!-- .find__from stays route-pair-only — e2e reads it to navigate; the lane tag
             is a sibling element, never appended text (docs/BUSINESS-LOGIC.md §16). -->
        <p class="find__from">{{ discovery.origin.iata }} → {{ discovery.destination.iata }}</p>
        <p v-if="isRelative" class="find__lane">Rare price for this route</p>
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
      <!-- data-verified, not a class binding: the two states are attribute selectors below
           and this template ships no colour logic — same call as VerdictPill. -->
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

/* --info, not --good or --warn: a relative find is a different kind, not a better or worse one
   (docs/BUSINESS-LOGIC.md §16). */
.find__lane {
  display: inline-block;
  margin-top: 4px;
  padding: 2px 7px;
  border-radius: var(--radius-pill);

  background: var(--info-bg);
  color: var(--info-ink);

  font-size: var(--text-xs);
  font-weight: 600;
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

/* The ordinary state, not a warning (see docs/BUSINESS-LOGIC.md §16).
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
