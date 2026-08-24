<script setup>
/*
 * The route selector above the month (design/README.md §3). The active chip is INK on BG,
 * deliberately not the accent: the accent means "an action", and these are a filter.
 */
defineProps({
  routes: { type: Array, required: true },
  active: { type: String, default: null },
})

defineEmits(['pick'])
</script>

<template>
  <!-- A group of toggles rather than a `tablist`: the grid is not a tabpanel, and `aria-pressed`
       says the true thing. -->
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
      <!-- The city, under the codes: six chips reading AMS→OPO are six anagrams unless you already
           know them. -->
      <span class="chip__city">{{ route.destination.city }}</span>
    </button>
  </div>
</template>

<style scoped>
/* Bleeds into the screen's gutter so the row scrolls edge to edge — a chip half-cut by the viewport
   says there are more. */
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
  /* Stepped back rather than set to --muted: the active chip is INK on BG, and a fixed grey on it
     would be a colour nobody picked. */
  opacity: 0.68;
}

.chip--active {
  background: var(--ink);
  color: var(--bg);
  border-color: var(--ink);
}

/* Composes with `.chip--active` rather than competing: both are true at once, and a
   paused chip stays selectable. */
.chip--paused {
  opacity: var(--dim-paused);
}
</style>
