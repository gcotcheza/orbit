<script setup>
defineProps({
  size: { type: Number, default: 15 },
})
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 18 18" fill="none" aria-hidden="true">
    <path d="M6 4l5 5-5 5" stroke="var(--muted)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>
