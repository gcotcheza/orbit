<script setup>
/*
 * The route detail as a screen: the back bar, and the panel the desktop detail pane shows too
 * (docs/BUSINESS-LOGIC.md §36).
 */
import { useRouter } from 'vue-router'
import RouteDetailPanel from '@/Components/route/RouteDetailPanel.vue'
import { goBack } from '@/lib/back'

defineProps({
  id: { type: String, required: true },
})

const router = useRouter()
</script>

<template>
  <section class="detail rise-in">
    <header class="detail__bar">
      <button class="detail__back" @click="goBack(router)">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
          <path d="M11 4l-5 5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Back
      </button>
    </header>

    <RouteDetailPanel :code="id" />
  </section>
</template>

<style scoped>
.detail {
  padding: 4px var(--gutter) 34px;
}

.detail__bar {
  display: flex;
  align-items: center;
  height: 44px;
}

.detail__back {
  display: flex;
  align-items: center;
  gap: 6px;

  font-size: var(--text-xl);
  font-weight: 600;
  color: var(--ink);
}
</style>
