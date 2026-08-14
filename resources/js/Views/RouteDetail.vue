<script setup>
/*
 * One route: price history, deal score, advice (design/README.md §2), which
 * arrives in a later PR. No tab bar — see this route's meta in
 * resources/js/router/index.js.
 *
 * The id is declared now so that /route/AMS-LIS is a real, shareable address
 * from this PR rather than becoming one later.
 */
import ScreenPlaceholder from '@/Components/ScreenPlaceholder.vue'

defineProps({
  id: { type: String, required: true },
})
</script>

<template>
  <ScreenPlaceholder :title="id" arrives="The price history, deal score and advice for this route arrive in a later PR." />
</template>
