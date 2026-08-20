<script setup>
/*
 * "ⓘ 6 trips match this right now — cheapest €34" (design/README.md §4).
 * Info tone, shimmers instead of emptying, phrases a partial count as a floor.
 * Why: docs/BUSINESS-LOGIC.md §11.
 */
defineProps({
  /** A parse's `matches`: { count, partial, cheapest, sample }. */
  matches: { type: Object, required: true },

  /** True while a newer parse is in flight. */
  loading: { type: Boolean, default: false },
})
</script>

<template>
  <div class="banner" :class="{ 'banner--loading': loading }" role="status">
    <svg width="17" height="17" viewBox="0 0 17 17" fill="none" class="banner__icon" aria-hidden="true">
      <circle cx="8.5" cy="8.5" r="7" stroke="currentColor" stroke-width="1.4" />
      <path d="M8.5 5v4M8.5 11.5v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
    </svg>

    <p v-if="matches.count > 0 && matches.partial" class="banner__text">
      <b>At least {{ matches.count }}</b>
      match so far — Orbit is still pricing the rest
    </p>

    <p v-else-if="matches.count > 0" class="banner__text">
      <b>{{ matches.count }} {{ matches.count === 1 ? 'trip' : 'trips' }}</b>
      match this right now — cheapest €{{ matches.cheapest }}
    </p>

    <p v-else class="banner__text">
      Nothing matches yet. Orbit is off to price the routes this rule is about — check back in a minute.
    </p>
  </div>
</template>

<style scoped>
.banner {
  position: relative;
  overflow: hidden;

  display: flex;
  align-items: center;
  gap: 9px;

  padding: 12px 14px;
  border-radius: 14px;

  background: var(--info-bg);
  color: var(--info-ink);
}

.banner__icon {
  flex-shrink: 0;
  color: var(--info);
}

.banner__text {
  font-size: var(--text-lg);
  font-weight: 500;
}

.banner__text b {
  font-weight: 700;
}

/* A sheen crossing the banner, not a spinner: the figure under it is still
   readable and still true until the new one lands. */
.banner--loading::after {
  content: '';
  position: absolute;
  inset: 0;

  background: linear-gradient(
    90deg,
    transparent 0%,
    color-mix(in srgb, var(--info) 14%, transparent) 50%,
    transparent 100%
  );

  animation: banner-shimmer 1.1s ease-in-out infinite;
}

@keyframes banner-shimmer {
  from {
    transform: translateX(-100%);
  }

  to {
    transform: translateX(100%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .banner--loading::after {
    animation: none;
    opacity: 0.5;
  }
}
</style>
