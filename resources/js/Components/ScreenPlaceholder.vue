<script setup>
/*
 * A screen that is honest about not existing yet.
 *
 * Five of Orbit's six screens are built in PRs 6-11 (docs/PLAN.md). Until then
 * their routes are real, reachable and in the tab bar — which is what lets the
 * navigation, the tab bar's active state and the auth guard be exercised now —
 * and this is what they render: the title the screen will have, and one line
 * saying when it arrives. Not a blank div, which is indistinguishable from a
 * bug, and not a fake version of the screen, which is worse.
 *
 * It is deleted, view by view, as each screen lands.
 */
defineProps({
  title: { type: String, required: true },
  arrives: { type: String, required: true },
})
</script>

<template>
  <section class="placeholder rise-in">
    <p class="placeholder__eyebrow">Orbit</p>
    <h1 class="placeholder__title">{{ title }}</h1>
    <p class="placeholder__note">{{ arrives }}</p>
  </section>
</template>

<style scoped>
.placeholder {
  padding: 28px var(--gutter);
}

.placeholder__eyebrow {
  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
}

.placeholder__title {
  margin-top: 6px;
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.placeholder__note {
  margin-top: 10px;
  max-width: 30ch;
  font-size: var(--text-lg);
  color: var(--muted);
}
</style>
