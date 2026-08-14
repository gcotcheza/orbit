<script setup>
/*
 * The tone-tinted callout under the chart (design/README.md §2).
 *
 * `advice.title` always equals `verdict.label` and `advice.tone` always equals
 * `verdict.tone` — they are generated together server-side so the prose and the
 * gauge cannot disagree (docs/API.md). So this component takes the tone it is
 * given and never derives one from the words.
 */
defineProps({
  title: { type: String, required: true },
  body: { type: String, required: true },
  tone: { type: String, default: 'normal' },
})
</script>

<template>
  <div class="callout" :class="`callout--${tone}`">
    <div class="callout__icon">
      <!-- #fff, not a token: the square is filled with a saturated tone in
           both themes, so its glyph is white in both. TabBar.vue's + button
           writes the same literal for the same reason. A `--on-solid` token
           would be the tidy answer and belongs to tokens.css, which this
           branch does not own. -->
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M4 9.5l3 3 7-8" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </div>

    <div>
      <p class="callout__title">{{ title }}</p>
      <p class="callout__body">{{ body }}</p>
    </div>
  </div>
</template>

<style scoped>
.callout--good {
  --tone: var(--good);
  --tone-bg: var(--good-bg);
  --tone-ink: var(--good-ink);
}

.callout--info {
  --tone: var(--info);
  --tone-bg: var(--info-bg);
  --tone-ink: var(--info-ink);
}

.callout--warn {
  --tone: var(--warn);
  --tone-bg: var(--warn-bg);
  --tone-ink: var(--warn-ink);
}

.callout--normal {
  --tone: var(--muted);
  --tone-bg: var(--neu-bg);
  --tone-ink: var(--neu-ink);
}

.callout {
  display: flex;
  gap: 12px;
  margin-top: 14px;
  padding: 15px 16px;
  border-radius: 18px;
  background: var(--tone-bg);
}

.callout__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: var(--tone);
}

.callout__title {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--tone-ink);
}

.callout__body {
  margin-top: 3px;
  font-size: var(--text-lg);
  line-height: 1.45;
  color: var(--ink2);
}
</style>
