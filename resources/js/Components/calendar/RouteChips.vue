<script setup>
/*
 * The route selector above the month (design/README.md §3).
 *
 * The active chip is INK on BG — the palette inverted — which is the loudest
 * thing on the screen in both themes and is deliberately not the accent: the
 * accent already means "an action" everywhere else in this app (the tab bar's
 * + button, the Book CTA), and these chips are a filter, not an action.
 */
defineProps({
  routes: { type: Array, required: true },
  active: { type: String, default: null },
})

defineEmits(['pick'])
</script>

<template>
  <!-- A group of toggles rather than a `tablist`: these chips select which
       route the month below is for, but the grid is not a tabpanel and wiring
       one up with `aria-controls` would describe a widget this screen does not
       have. `aria-pressed` says the true thing — this one is the chosen one. -->
  <div class="chips" role="group" aria-label="Route">
    <button
      v-for="route in routes"
      :key="route.code"
      class="chip"
      :class="{ 'chip--active': route.code === active, 'chip--paused': !route.active }"
      :aria-pressed="route.code === active"
      @click="$emit('pick', route.code)"
    >
      <span>{{ route.origin.iata }}→{{ route.destination.iata }}</span>
      <!-- The city, under the codes. Six chips reading AMS→OPO, AMS→FAO,
           EIN→LIS are six anagrams unless you already know them, and this
           screen's question — "when is it cheap?" — is asked about a PLACE.
           Same addition, same reasoning, as the globe's route rail. -->
      <span class="chip__city">{{ route.destination.city }}</span>
    </button>
  </div>
</template>

<style scoped>
/* Bleeds into the screen's gutter so the row scrolls edge to edge, then pads
   it back — a chip half-cut by the viewport is the affordance that says there
   are more of them. */
.chips {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  margin: 14px calc(var(--gutter) * -1) 16px;
  padding: 2px var(--gutter);
  scrollbar-width: none;
}

.chips::-webkit-scrollbar {
  display: none;
}

.chip {
  flex: 0 0 auto;
  padding: 8px 14px;
  border-radius: 11px;

  font-family: var(--font-display);
  font-size: var(--text-lg);
  font-weight: 600;

  background: var(--card);
  color: var(--ink2);
  border: 1px solid var(--line);
}

.chip__city {
  display: block;
  margin-top: 1px;

  font-family: var(--font-body);
  font-size: var(--text-sm);
  font-weight: 500;
  /* Stepped back rather than set to --muted: the active chip is INK on BG, and
     a fixed grey on it would be a colour nobody picked. Opacity inherits
     whichever ink the chip is currently wearing. */
  opacity: 0.68;
}

.chip--active {
  background: var(--ink);
  color: var(--bg);
  border-color: var(--ink);
}

/*
 * A PAUSED ROUTE IS DIMMED HERE TOO, at the same 0.58 the watch screen's rows
 * use. It stays in the list and stays selectable — its calendar is still worth
 * reading, and docs/API.md is explicit that paused routes are not filtered out
 * — but a screen that draws a paused route identically to a live one is a
 * screen quietly disagreeing with the switch the owner just moved.
 *
 * IT COMPOSES WITH `.chip--active` rather than competing with it — one sets a
 * fill, the other an opacity — so the selected chip of a paused route is both
 * inverted and dimmed. Both statements are true at once.
 */
.chip--paused {
  opacity: 0.58;
}
</style>
