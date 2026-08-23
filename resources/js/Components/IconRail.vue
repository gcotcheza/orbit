<script setup>
/*
 * The tab bar's five destinations as a 76px rail, drawn only above 768px — the phone never mounts
 * this (App.vue, docs/DESKTOP-LAYOUT-PLAN.md). Icons and labels are the bar's own.
 */
</script>

<template>
  <nav class="rail-nav" aria-label="Primary">
    <!-- The app's own mark, which is also the plate every home-screen icon is rasterised from. -->
    <img class="rail-nav__mark" src="/icon.svg" alt="" width="28" height="28">

    <RouterLink class="rail-nav__item" :to="{ name: 'home' }">
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="1.6" />
        <path d="M3 11h16M11 3c2.2 2.2 3.4 5 3.4 8s-1.2 5.8-3.4 8c-2.2-2.2-3.4-5-3.4-8s1.2-5.8 3.4-8Z" stroke="currentColor" stroke-width="1.4" />
      </svg>
      <span>Orbit</span>
    </RouterLink>

    <RouterLink class="rail-nav__item" :to="{ name: 'calendar' }">
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <rect x="3" y="4" width="16" height="15" rx="2.5" stroke="currentColor" stroke-width="1.6" />
        <path d="M3 8h16M7 2v3M15 2v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        <rect x="6" y="11" width="3" height="3" rx=".6" fill="currentColor" />
      </svg>
      <span>Calendar</span>
    </RouterLink>

    <RouterLink class="rail-nav__item rail-nav__item--search" :to="{ name: 'search' }">
      <span class="rail-nav__button">
        <!-- Stroked from the style block, not a presentation attribute — var() is not portable
             there. The bar's centre button, upright. -->
        <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
          <circle cx="9.5" cy="9.5" r="5.5" stroke-width="2" />
          <path d="m13.7 13.7 3.8 3.8" stroke-width="2.2" stroke-linecap="round" />
        </svg>
      </span>
      <span>Search</span>
    </RouterLink>

    <RouterLink class="rail-nav__item" :to="{ name: 'watch' }">
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <path d="M5 6h12M5 11h12M5 16h8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
      </svg>
      <span>Watch</span>
    </RouterLink>

    <RouterLink class="rail-nav__item" :to="{ name: 'alerts' }">
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <path d="M11 2a4 4 0 0 0-4 4v3.5L5.5 12h11L15 9.5V6a4 4 0 0 0-4-4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
        <path d="M9 16a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.6" />
      </svg>
      <span>Alerts</span>
    </RouterLink>

    <RouterLink
      class="rail-nav__profile"
      :to="{ name: 'alerts', hash: '#account' }"
      aria-label="Your account and alert settings"
    >
      <svg width="19" height="19" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <circle cx="10" cy="6.5" r="3.2" stroke="var(--ink2)" stroke-width="1.5" />
        <path d="M4 16.5c0-3 2.7-5 6-5s6 2 6 5" stroke="var(--ink2)" stroke-width="1.5" stroke-linecap="round" />
      </svg>
    </RouterLink>
  </nav>
</template>

<style scoped>
.rail-nav {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;

  width: var(--rail-width);
  padding: 14px 0 env(safe-area-inset-bottom);
  overflow-y: auto;

  background: var(--panel);
  border-right: 1px solid var(--line);
}

.rail-nav__mark {
  width: 28px;
  height: 28px;
  margin-bottom: 10px;
  border-radius: 8px;
}

.rail-nav__item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;

  width: 60px;
  padding: 8px 0;
  border-radius: 12px;

  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.01em;
  color: var(--muted);
  text-decoration: none;
  transition: color 0.18s ease;
}

/* Exact, or '/' matches every route — the tab bar's rule, for the same reason. */
.rail-nav__item.router-link-exact-active {
  color: var(--accent);
}

/* The centre button keeps its accent fill regardless of active state. */
.rail-nav__item--search.router-link-exact-active {
  color: var(--muted);
}

.rail-nav__button {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 48px;
  height: 42px;
  border-radius: 14px;

  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);
}

.rail-nav__button circle,
.rail-nav__button path {
  stroke: var(--on-solid);
}

/* `auto` rather than a spacer element: the rail is a nav, and an empty div in it is not. */
.rail-nav__profile {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;

  width: 36px;
  height: 36px;
  margin: auto 0 6px;
  border: 1px solid var(--line);
  border-radius: 50%;

  background: var(--card);
}
</style>
