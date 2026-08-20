<script setup>
</script>

<template>
  <nav class="tab-bar" aria-label="Primary">
    <!-- Icons are written out, not v-for'd — only 4 of 5 share a shape (docs/BUSINESS-LOGIC.md §36). -->
    <RouterLink class="tab" :to="{ name: 'home' }">
      <svg class="tab__icon" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="1.6" />
        <path d="M3 11h16M11 3c2.2 2.2 3.4 5 3.4 8s-1.2 5.8-3.4 8c-2.2-2.2-3.4-5-3.4-8s1.2-5.8 3.4-8Z" stroke="currentColor" stroke-width="1.4" />
      </svg>
      <span class="tab__label">Orbit</span>
    </RouterLink>

    <RouterLink class="tab" :to="{ name: 'calendar' }">
      <svg class="tab__icon" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <rect x="3" y="4" width="16" height="15" rx="2.5" stroke="currentColor" stroke-width="1.6" />
        <path d="M3 8h16M7 2v3M15 2v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        <rect x="6" y="11" width="3" height="3" rx=".6" fill="currentColor" />
      </svg>
      <span class="tab__label">Calendar</span>
    </RouterLink>

    <!-- Centre item goes to /search, not rule creation, since 2026-08-16 (docs/BUSINESS-LOGIC.md §36). -->
    <!-- No aria-label — a stale one broke e2e tab() lookup by name (docs/BUSINESS-LOGIC.md §36). -->
    <RouterLink class="tab tab--search" :to="{ name: 'search' }">
      <span class="tab__button">
        <!-- Stroked from the style block, not a presentation attribute — var() isn't portable there.
             The handle is a separate path so it alone can carry stroke-linecap. -->
        <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
          <circle cx="9.5" cy="9.5" r="5.5" stroke-width="2" />
          <path d="m13.7 13.7 3.8 3.8" stroke-width="2.2" stroke-linecap="round" />
        </svg>
      </span>
      <!-- Labelled like its neighbours — the only unlabelled item the app had (docs/BUSINESS-LOGIC.md §36). -->
      <span class="tab__label">Search</span>
    </RouterLink>

    <RouterLink class="tab" :to="{ name: 'watch' }">
      <svg class="tab__icon" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <path d="M5 6h12M5 11h12M5 16h8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
      </svg>
      <span class="tab__label">Watch</span>
    </RouterLink>

    <RouterLink class="tab" :to="{ name: 'alerts' }">
      <svg class="tab__icon" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <path d="M11 2a4 4 0 0 0-4 4v3.5L5.5 12h11L15 9.5V6a4 4 0 0 0-4-4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
        <path d="M9 16a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.6" />
      </svg>
      <span class="tab__label">Alerts</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.tab-bar {
  /* Fixed, not sticky: belongs to the app, not the scroller — every screen
     already reserves room for it (.app-shell--tabs).
     Why: docs/BUSINESS-LOGIC.md §36. */
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 10;

  max-width: var(--app-width);
  margin-inline: auto;

  display: flex;
  align-items: flex-start;
  justify-content: space-around;

  height: calc(var(--tab-bar-height) + env(safe-area-inset-bottom));
  padding: 11px 14px env(safe-area-inset-bottom);

  background: var(--panel);
  border-top: 1px solid var(--line);
}

.tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;

  color: var(--muted);
  text-decoration: none;
  transition: color 0.18s ease;
}

/* Active state is CSS only: RouterLink sets `router-link-exact-active`, every
   icon strokes with currentColor. Exact avoids '/' matching every route.
   Why: docs/BUSINESS-LOGIC.md §36. */
.tab.router-link-exact-active {
  color: var(--accent);
}

.tab__label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.01em;
}

/* Centre button keeps its accent fill regardless of active state — it reads
   as an action, not a destination.
   Why: docs/BUSINESS-LOGIC.md §36.

   DO NOT change -6px without recomputing: sized so the button + label (59px)
   clears the bar's 67px content height without growing it.
   Why: docs/BUSINESS-LOGIC.md §36. */
.tab--search {
  margin-top: -6px;
}

.tab--search.router-link-exact-active {
  color: var(--muted);
}

.tab__button {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 48px;
  height: 42px;
  border-radius: 14px;

  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.tab__button circle,
.tab__button path {
  stroke: var(--on-solid);
}
</style>
