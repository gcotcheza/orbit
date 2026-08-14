<script setup>
/*
 * The five-item bottom bar, per design/README.md §Interactions: Orbit,
 * Calendar, a centre + button, Watch, Alerts.
 *
 * WHY THE ICONS ARE WRITTEN OUT RATHER THAN LOOPED. Four of the five items are
 * the same shape and the fifth — the accent + button — is not: it has no label,
 * a different box, a shadow and a negative top margin. A v-for would need the
 * odd one special-cased anyway, and the price of the loop would be five 22 px
 * icon paths moved out of the file that draws them. They are drawn here, once.
 *
 * ACTIVE STATE IS CSS, NOT JAVASCRIPT. RouterLink puts
 * `router-link-exact-active` on the current item; every icon strokes with
 * `currentColor`, so the whole active/inactive treatment is one colour on one
 * rule. Exact rather than the loose `router-link-active` because '/' is a
 * prefix of every other path and would otherwise light Orbit up on all five
 * screens.
 */
</script>

<template>
  <nav class="tab-bar" aria-label="Primary">
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

    <RouterLink class="tab tab--create" :to="{ name: 'create' }" aria-label="Create an alert rule">
      <span class="tab__button">
        <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
          <path d="M11 5v12M5 11h12" stroke="#fff" stroke-width="2.2" stroke-linecap="round" />
        </svg>
      </span>
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
  /* Fixed rather than sticky: the bar belongs to the app, not to the scroller,
     and every screen already reserves room for it (.app-shell--tabs). Centred
     the same way the shell is, so it stays phone-width on a laptop. */
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

.tab.router-link-exact-active {
  color: var(--accent);
}

.tab__label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.01em;
}

/* The centre button sits proud of the bar and is the only item that keeps its
   accent fill whether or not it is the current screen — it reads as an action,
   not as a destination. */
.tab--create {
  margin-top: -6px;
}

.tab--create.router-link-exact-active {
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
</style>
