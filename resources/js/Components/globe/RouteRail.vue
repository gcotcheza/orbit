<script setup>
/*
 * "Fly to a route" — the horizontal rail of chips under the spotlight card
 * (design/README.md §1).
 *
 * Tapping one selects that route: the parent moves the tour to it, which
 * replays the flight and re-draws the card. The active chip is the accent fill
 * with a glow; the rest are cards with a hairline border and a tone dot.
 *
 * IT IS A TAB LIST, semantically. The chips select which of several things the
 * screen is showing, and that is what `role="tablist"` means — so a screen
 * reader announces "AMS→LIS, selected, 1 of 6" instead of six unrelated
 * buttons. The globe and the card are the panel; they are not marked up as one,
 * because `aria-controls` pointing at a decorative canvas would be a lie.
 */
import { euro } from '@/lib/format'

defineProps({
  routes: { type: Array, required: true },
  activeCode: { type: String, required: true },
})

defineEmits(['select'])
</script>

<template>
  <section class="rail">
    <header class="rail__head">
      <h2 class="rail__title">Fly to a route</h2>
      <p class="rail__count">{{ routes.length }} watched</p>
    </header>

    <div class="rail__track" role="tablist" aria-label="Watched routes">
      <button
        v-for="route in routes"
        :key="route.code"
        class="rail__chip"
        :class="{ 'rail__chip--active': route.code === activeCode }"
        type="button"
        role="tab"
        :aria-selected="route.code === activeCode"
        @click="$emit('select', route.code)"
      >
        <span class="rail__dot" :data-tone="route.verdict.tone"></span>

        <!-- THE CITY, under the codes. A rail of AMS→OPO, AMS→FAO, EIN→LIS is
             a row of anagrams to anybody who does not already know them, and
             "fly to a route" is exactly the moment somebody is choosing a
             PLACE rather than a code. The pair is one column so the chip stays
             one tap and the codes keep the line they had. -->
        <span class="rail__where">
          <span>{{ route.origin.iata }}→{{ route.destination.iata }}</span>
          <span class="rail__city">{{ route.destination.city }}</span>
        </span>

        <span class="rail__price tabular">{{ euro(route.price.current) ?? '—' }}</span>
      </button>
    </div>
  </section>
</template>

<style scoped>
.rail__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 20px 10px;
}

.rail__title {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 600;
  color: var(--ink);
}

.rail__count {
  font-size: var(--text-md);
  font-weight: 500;
  color: var(--muted);
}

.rail__track {
  display: flex;
  gap: 9px;
  padding: 2px var(--gutter) 4px;

  overflow-x: auto;
  /* The rail is a phone gesture: it scrolls sideways under the thumb and it
     does not keep a scrollbar on a laptop. */
  scrollbar-width: none;
  scroll-snap-type: x proximity;
}

.rail__track::-webkit-scrollbar {
  display: none;
}

.rail__chip {
  flex: 0 0 auto;
  scroll-snap-align: start;

  display: flex;
  align-items: center;
  gap: 8px;

  padding: 10px 13px;
  border: 1px solid var(--line);
  border-radius: var(--radius-chip);

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink);

  background: var(--card);
  box-shadow: var(--shadow);
  transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
}

.rail__chip--active {
  border-color: var(--accent);
  background: var(--accent);
  /* White on the accent in BOTH themes — the accent is a saturated blue either
     way, and --ink would be near-black on it in the light theme. That is what
     --on-solid is, and the tab bar's centre button reads the same token. */
  color: var(--on-solid);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.rail__where {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1px;
}

/*
 * QUIETENED WITH OPACITY RATHER THAN WITH --muted, which is the same choice
 * `.rail__price` makes one rule below and for the same reason: the active chip
 * is a saturated accent fill, and a fixed grey on it is either invisible or a
 * second colour nobody chose. Inheriting the chip's own ink and stepping it
 * back works on the card and on the accent, in both themes.
 */
.rail__city {
  font-family: var(--font-body);
  font-size: var(--text-sm);
  font-weight: 500;
  opacity: 0.66;
}

.rail__price {
  opacity: 0.78;
}

.rail__dot {
  width: 7px;
  height: 7px;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--muted);
}

.rail__dot[data-tone='good'] {
  background: var(--good);
}

.rail__dot[data-tone='info'] {
  background: var(--info);
}

.rail__dot[data-tone='warn'] {
  background: var(--warn);
}

/* On the active chip the dot is the same white as the label: the tone is being
   said by the card above, and a coloured dot on the accent fill reads as a
   status light rather than as a bullet. */
.rail__chip--active .rail__dot {
  background: var(--on-solid);
}
</style>
