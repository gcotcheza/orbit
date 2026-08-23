<script setup>
/*
 * The shell every screen sits inside. There is no <AppLayout>/<BareLayout> pair because
 * swapping that component would destroy the <KeepAlive> below it, and the globe with it.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import IconRail from '@/Components/IconRail.vue'
import TabBar from '@/Components/TabBar.vue'
import UpdateToast from '@/Components/UpdateToast.vue'
import { useLayout } from '@/lib/layout'
import { applyUpdate, dismissUpdate, updateReady } from '@/lib/pwa'

/*
 * Screens kept alive across navigation, by component name — a view listed here MUST declare a
 * matching `name`. Everything else is deliberately absent: a cached screen caches stale data.
 */
const KEPT_ALIVE = ['Home']

const route = useRoute()
const { isDesktop, isPhone } = useLayout()

const hasTabBar = computed(() => route.meta.layout === 'tabs' && isPhone.value)

/* The same five destinations, upright, from 768px — one bar or one rail, never both. */
const hasRail = computed(() => route.meta.layout === 'tabs' && !isPhone.value)

/* `meta.wide: true` owns the frame from 768px; `'desktop'` owns it from 1024 and keeps the phone
   column below that (docs/DESKTOP-LAYOUT-PLAN.md). */
const ownsFrame = computed(
  () => route.meta.wide === true || (route.meta.wide === 'desktop' && isDesktop.value),
)

/** A screen with no pane of its own keeps the phone column, centred in what the rail leaves. */
const isColumn = computed(() => hasRail.value && !ownsFrame.value)
</script>

<template>
  <div class="app-shell" :class="{ 'app-shell--tabs': hasTabBar, 'app-shell--rail': hasRail }">
    <IconRail v-if="hasRail" />

    <main class="app-shell__main" :class="{ 'app-shell__main--column': isColumn }">
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
