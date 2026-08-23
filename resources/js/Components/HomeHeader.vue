<script setup>
/*
 * The landing page's eyebrow and greeting, drawn identically by the phone branch and the frame's
 * master pane (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
 */
defineProps({
  /** "Good morning" — the caller owns the clock, since only it knows when the screen woke up. */
  greeting: { type: String, required: true },

  /** The account link. Off inside the frame, where the rail already carries one. */
  profile: { type: Boolean, default: false },
})
</script>

<template>
  <header class="home__header">
    <div>
      <p class="home__eyebrow">
        <span class="home__live"></span>
        Tracking live
      </p>
      <h1 class="home__greeting">{{ greeting }}</h1>
    </div>

    <!-- Alerts is the nearest real destination for this icon; the hash lands
         on #account, not the top of that screen (docs/BUSINESS-LOGIC.md §36). -->
    <RouterLink
      v-if="profile"
      class="home__profile"
      :to="{ name: 'alerts', hash: '#account' }"
      aria-label="Your account and alert settings"
    >
      <svg width="19" height="19" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <circle cx="10" cy="6.5" r="3.2" stroke="var(--ink2)" stroke-width="1.5" />
        <path d="M4 16.5c0-3 2.7-5 6-5s6 2 6 5" stroke="var(--ink2)" stroke-width="1.5" stroke-linecap="round" />
      </svg>
    </RouterLink>
  </header>
</template>

<style scoped>
.home__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 4px var(--gutter) 6px;
}

.home__eyebrow {
  display: flex;
  align-items: center;
  gap: 7px;

  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
}

.home__live {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--good);
  animation: orbit-pulse 2.2s infinite;
}

.home__greeting {
  margin-top: 3px;
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.home__profile {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;

  width: 40px;
  height: 40px;
  border: 1px solid var(--line);
  border-radius: 50%;

  background: var(--card);
  box-shadow: var(--shadow);
}
</style>
