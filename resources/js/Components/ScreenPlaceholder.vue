<script setup>
/*
 * A screen that is honest about not existing yet: the title it will have and one line saying
 * when it arrives. Not a blank div, which is indistinguishable from a bug.
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
