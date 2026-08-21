<script setup>
/*
 * The shell every screen sits inside. There is no <AppLayout>/<BareLayout> pair because
 * swapping that component would destroy the <KeepAlive> below it, and the globe with it.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import TabBar from '@/Components/TabBar.vue'
import UpdateToast from '@/Components/UpdateToast.vue'
import { applyUpdate, dismissUpdate, updateReady } from '@/lib/pwa'

/*
 * Screens kept alive across navigation, by component name — a view listed here MUST declare a
 * matching `name`. Everything else is deliberately absent: a cached screen caches stale data.
 */
const KEPT_ALIVE = ['Home']

const route = useRoute()

const hasTabBar = computed(() => route.meta.layout === 'tabs')
</script>

<template>
  <div class="app-shell" :class="{ 'app-shell--tabs': hasTabBar }">
    <main class="app-shell__main">
      <RouterView v-slot="{ Component }">
        <KeepAlive :include="KEPT_ALIVE">
          <component :is="Component" />
        </KeepAlive>
      </RouterView>
    </main>

    <TabBar v-if="hasTabBar" />

    <!-- A SIBLING OF <main>, not something inside it: fixed to the viewport, and outside the
         <KeepAlive> that caches the globe (docs/BUSINESS-LOGIC.md §36). -->
    <UpdateToast
      v-if="updateReady"
      :above-tab-bar="hasTabBar"
      @refresh="applyUpdate"
      @dismiss="dismissUpdate"
    />
  </div>
</template>
