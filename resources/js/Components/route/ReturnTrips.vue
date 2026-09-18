<script setup>
/*
 * What a ROUND TRIP costs on this route, one row per length of stay (design/README.md §2).
 * Every number and every band name is the server's — nothing here is a judgement.
 */
import { computed } from 'vue'
import { departureLabel, euro, seenIfOld } from '@/lib/format'

const props = defineProps({
  /** `data.returns` from docs/API.md: every configured band, `fare: null` where none is held. */
  returns: { type: Array, required: true },
})

function comparison(fare) {
  if (fare.usual === null || fare.usual === undefined || fare.pctBelow === null || fare.pctBelow === undefined) {
    return null
  }

  if (fare.pctBelow === 0) {
    return `Right at its usual ${euro(fare.usual)}`
  }

  // Never "above": a band's price is the cheapest of the pool its usual price is the median of.
  return `${fare.pctBelow}% below its usual ${euro(fare.usual)}`
}

function meta(fare, seen) {
  const stay = `${fare.nights} ${fare.nights === 1 ? 'night' : 'nights'}`
  const parts = [departureLabel(fare.departure), stay]

  if (seen !== null) {
    parts.push(`seen ${seen}`)
  }

  return parts.join(' · ')
}

// How BookingCta.vue opens Aviasales; a return row leaves the app the same way.
const OPENS_AVIASALES = { target: '_blank', rel: 'noopener' }

function toRow({ band, fare }) {
  const named = {
    key: band.nights.join('-'),
    label: band.label,
    nights: `${band.nights[0]}–${band.nights[1]} nights`,
  }

  if (fare === null || fare === undefined) {
    return { ...named, tag: 'div', attrs: {}, fare: null }
  }

  const seen = seenIfOld(fare.foundAt ?? null)
  // `mayBeGone` is the server's, and it is only true past 48 h — so there is always an age here.
  const gone = fare.mayBeGone === true ? `Seen ${seen} — may be gone` : null

  return {
    ...named,
    tag: 'a',
    attrs: { href: fare.booking.aviasales, ...OPENS_AVIASALES },
    fare: {
      price: `from ${euro(fare.current)}`,
      comparison: comparison(fare),
      gone,
      meta: meta(fare, gone === null ? seen : null),
    },
  }
}

const rows = computed(() => props.returns.map(toRow))
</script>

<template>
  <section v-if="rows.length" class="ret">
    <h2 class="ret__title">Return trips</h2>
    <p class="ret__sub">Round trip, by length of stay</p>

    <div class="ret__rows">
      <component
        :is="row.tag"
        v-for="row in rows"
        :key="row.key"
        v-bind="row.attrs"
        class="ret__row"
        :class="row.fare === null ? 'ret__row--none' : 'ret__row--link'"
      >
        <div>
          <p class="ret__name">{{ row.label }}</p>
          <p class="ret__nights">{{ row.nights }}</p>
        </div>

        <div class="ret__fare">
          <template v-if="row.fare">
            <p class="ret__price">{{ row.fare.price }}</p>
            <p class="ret__vs" :class="{ 'ret__vs--none': row.fare.comparison === null }">
              {{ row.fare.comparison ?? 'No usual price yet' }}
            </p>
            <p v-if="row.fare.gone" class="ret__gone">{{ row.fare.gone }}</p>
            <p class="ret__meta">{{ row.fare.meta }}</p>
          </template>

          <p v-else class="ret__none">No return fares seen yet</p>
        </div>

        <!-- Same chevron affordance as WatchRow.vue, sized to this row. -->
        <svg v-if="row.fare" class="ret__chevron" width="15" height="15" viewBox="0 0 18 18" fill="none" aria-hidden="true">
          <path d="M6 4l5 5-5 5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </component>
    </div>
  </section>
</template>

<style scoped>
.ret {
  margin: 20px 2px 0;
}

.ret__title {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--ink);
}

.ret__sub {
  margin-top: 2px;
  font-size: var(--text-md);
  color: var(--muted);
}

.ret__rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 10px;
}

.ret__row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;

  padding: 11px 13px;
  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card);
}

.ret__row--link {
  color: inherit;
  text-decoration: none;
  cursor: pointer;
}

.ret__name {
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink);
}

.ret__row--none .ret__name {
  font-weight: 400;
  color: var(--muted);
}

.ret__nights {
  margin-top: 2px;
  font-size: var(--text-xs);
  color: var(--muted);
}

/* The row's free space is spent here, so a third child lands at the right edge instead of
   pushing the price into the middle of the card. */
.ret__fare {
  margin-left: auto;
  text-align: right;
}

.ret__price {
  font-family: var(--font-display);
  font-size: var(--text-price);
  font-weight: 700;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.ret__vs {
  margin-top: 3px;
  font-size: var(--text-md);
  color: var(--ink2);
}

.ret__vs--none {
  color: var(--muted);
}

.ret__gone {
  display: inline-block;
  margin-top: 4px;
  padding: 3px 8px;
  border-radius: var(--radius-chip);
  background: var(--warn-bg);
  font-size: var(--text-md);
  font-weight: 600;
  color: var(--warn-ink);
}

.ret__meta {
  margin-top: 3px;
  font-size: var(--text-xs);
  color: var(--muted);
}

.ret__none {
  padding-top: 1px;
  font-size: var(--text-md);
  color: var(--muted);
}

.ret__chevron {
  align-self: center;
  flex-shrink: 0;
}

.ret__chevron path {
  stroke: var(--muted);
}
</style>
