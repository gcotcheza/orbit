<script setup>
/*
 * The shell every screen sits inside.
 *
 * WHY THERE IS NO <AppLayout> / <BareLayout> PAIR. The obvious shape is a
 * `layout` component named by route meta and swapped with <component :is>. It
 * was not used, for one concrete reason: swapping that component destroys and
 * recreates everything beneath it, INCLUDING the <KeepAlive> below — so the
 * globe, which is expensive to build and is the reason KeepAlive is here at
 * all, would be torn down and rebuilt every time the user opened a route
 * detail (bare) and came back to the home screen (tabs). That is the single
 * most common navigation in this app.
 *
 * So the chrome is conditional rather than swappable, and the KeepAlive sits in
 * a position that never moves. `meta.layout` still chooses it, exactly as
 * described in the brief; it is just read as a string instead of as a
 * component. See resources/js/router/index.js.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import TabBar from '@/Components/TabBar.vue'
import UpdateToast from '@/Components/UpdateToast.vue'
import { applyUpdate, dismissUpdate, updateReady } from '@/lib/pwa'

/*
 * Screens kept alive across navigation, by component name.
 *
 * The globe (PR6) is the whole reason this exists: it builds a WebGL scene,
 * loads Earth textures and runs a camera tour, and remounting it on every tab
 * switch would restart that tour from the beginning and re-download nothing
 * cheap. Views listed here MUST declare a matching `name` — KeepAlive matches
 * on the component's name, and an SFC that does not set one is silently not
 * cached.
 *
 * Everything else is deliberately absent: a screen that is cheap to build
 * should be rebuilt, because a cached one also caches its stale data.
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

    <!--
      A SIBLING OF <main>, NOT SOMETHING INSIDE IT. It is fixed to the viewport
      and lives for as long as the app does, so putting it in the RouterView
      would tie an announcement about the whole app to whichever screen happened
      to be mounted — and would put a node inside the <KeepAlive> that caches the
      globe. `updateReady` is a module-level ref in lib/pwa.js rather than a
      store: one boolean, one writer, no server state.
    -->
    <UpdateToast
      v-if="updateReady"
      :above-tab-bar="hasTabBar"
      @refresh="applyUpdate"
      @dismiss="dismissUpdate"
    />
  </div>
</template>
