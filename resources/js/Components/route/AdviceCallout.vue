<script setup>
/*
 * The tone-tinted callout under the chart (design/README.md §2). ⚠ `advice` usually mirrors
 * `verdict`; take the tone as given. The glyph reads `confident`, not the tone (docs/API.md).
 */
defineProps({
  title: { type: String, required: true },
  body: { type: String, required: true },
  tone: { type: String, default: 'normal' },
  /** Does Orbit stand behind this? `DealScore::$confident`, via docs/API.md. */
  confident: { type: Boolean, default: true },
})
</script>

<template>
  <div class="callout" :class="`callout--${tone}`">
    <div class="callout__icon">
      <!-- The glyph's colour is set in the style block below, not as a `stroke`
           attribute here: the square is filled with a saturated tone in both
           themes, so the glyph is --on-solid in both, and a presentation
           attribute carrying a var() is honoured by some browsers and dropped
           as invalid by others. -->
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <!-- An hourglass: the thing has been started and has not finished, which
             is exactly what a route Orbit is still learning about is. -->
        <template v-if="!confident">
          <path d="M4.5 3.2h9M4.5 14.8h9" stroke-width="1.6" stroke-linecap="round" />
          <path
            d="M6 3.2v2.3c0 1.7 3 2.3 3 3.5s-3 1.8-3 3.5v2.3M12 3.2v2.3c0 1.7-3 2.3-3 3.5s3 1.8 3 3.5v2.3"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </template>

        <!-- ⚠ A tick is the mark for "checked, and it is fine". Over "Cheap,
             but it may be gone" it contradicts the sentence beside it. -->
        <template v-else-if="tone === 'warn'">
          <path d="M9 3.6v6.6" stroke-width="2" stroke-linecap="round" />
          <path d="M9 13.6v.2" stroke-width="2.2" stroke-linecap="round" />
        </template>

        <path v-else d="M4 9.5l3 3 7-8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
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

.callout__icon path {
  stroke: var(--on-solid);
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
