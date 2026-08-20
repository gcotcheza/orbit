<script setup>
/*
 * "Fly to a route" — the horizontal rail of chips under the spotlight card
 * (design/README.md §1). Tapping selects the route: parent replays the
 * flight tour and re-draws the card.
 *
 * Tab list semantically (role="tablist"/"tab"): chips select which of
 * several things the screen shows. Globe/card aren't aria-controls'd — that
 * would lie about a decorative canvas being one panel.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
import { nextTick, useTemplateRef, watch } from 'vue'
import { euro } from '@/lib/format'
import { scrollIntoView } from '@/lib/motion'

const props = defineProps({
  routes: { type: Array, required: true },
  activeCode: { type: String, required: true },
})

defineEmits(['select'])

const track = useTemplateRef('track')

/*
 * Selected chip must stay on screen: the tour auto-advances it every ~11s,
 * and off-screen the rail (the one control showing WHERE in the list the
 * camera is) looks like it forgot which route is showing.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `inline: 'center'` (not 'nearest'): edge-visible still reads as "end of
 * list". `block: 'nearest'` stops the browser scrolling the whole PAGE to
 * drag the 360px globe off-screen instead.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Smooth only if wanted (lib/motion.js decides). `nextTick`: the first
 * selection can land before the chip list has rendered.
 */
watch(
  () => props.activeCode,
  (code) => {
    nextTick(() => {
      scrollIntoView(track.value?.querySelector(`[data-code="${CSS.escape(code)}"]`), {
        inline: 'center',
        block: 'nearest',
      })
    })
  },
  { immediate: true },
)
</script>

<template>
  <section class="rail">
    <header class="rail__head">
      <h2 class="rail__title">Fly to a route</h2>
      <p class="rail__count">{{ routes.length }} watched</p>
    </header>

    <div ref="track" class="rail__track" role="tablist" aria-label="Watched routes">
      <button
        v-for="route in routes"
        :key="route.code"
        class="rail__chip"
        :class="{ 'rail__chip--active': route.code === activeCode }"
        :data-code="route.code"
        type="button"
        role="tab"
        :aria-selected="route.code === activeCode"
        @click="$emit('select', route.code)"
      >
        <span class="rail__dot" :data-tone="route.verdict.tone"></span>

        <!-- City under code: a rail of AMS-OPO/AMS-FAO/EIN-LIS reads as
             anagrams to anyone who doesn't already know them.
             Why: docs/BUSINESS-LOGIC.md §36. -->
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
  /* White on accent in both themes (--ink would be near-black on it in
     light); --on-solid also used by the tab bar's centre button.
     Why: docs/BUSINESS-LOGIC.md §36. */
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
 * Opacity, not --muted (same as .rail__price below): a fixed grey is either
 * invisible or a stray color on the accent-filled active chip.
 * Why: docs/BUSINESS-LOGIC.md §36.
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

/* Active chip's dot matches the label (white): tone is already said by the
   card above; a colored dot on the accent fill would read as a status light.
   Why: docs/BUSINESS-LOGIC.md §36. */
.rail__chip--active .rail__dot {
  background: var(--on-solid);
}
</style>
