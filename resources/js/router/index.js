// Routes

// createWebHistory, not hashes: every route gets a real, shareable URL (and a PWA launch target);
// routes/web.php answers every non-API path with the same shell (docs/BUSINESS-LOGIC.md §36).

// meta.layout ('tabs' or 'bare') is a string, not a swappable layout component: swapping would
// remount the tree and drop the globe's <KeepAlive> cache (docs/BUSINESS-LOGIC.md §36).

// meta.guestOnly mirrors the server's auth split and is opt-in: a route without it needs a session,
// so screens are private by default (docs/BUSINESS-LOGIC.md §36).
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

export const router = createRouter({
    history: createWebHistory(),

    routes: [
        {
            // `wide`: this screen owns the whole frame above 768px rather than sitting in the
            // phone column the other tabbed screens keep until phases 2-3.
            path: '/',
            name: 'home',
            component: () => import('@/Views/Home.vue'),
            meta: { layout: 'tabs', wide: true },
        },
        {
            // `'desktop'`, not `true`: the month grid and its day panel need the 1024px split, and
            // below it this screen keeps the phone column the rail leaves.
            path: '/calendar',
            name: 'calendar',
            component: () => import('@/Views/Calendar.vue'),
            meta: { layout: 'tabs', wide: 'desktop' },
        },
        {
            // The centre tab, with a bottom bar like the other four: you come back from a search
            // with a route, and the bar is the way back (docs/BUSINESS-LOGIC.md §36).
            path: '/search',
            name: 'search',
            component: () => import('@/Views/Search.vue'),
            meta: { layout: 'tabs', wide: 'desktop' },
        },
        {
            // Still a tabbed screen though no longer a tab: create lost the centre seat to search
            // but kept its bar, and is reached from watch's "+ New rule".
            path: '/create',
            name: 'create',
            component: () => import('@/Views/Create.vue'),
            meta: { layout: 'tabs', wide: 'desktop' },
        },
        {
            path: '/watch',
            name: 'watch',
            component: () => import('@/Views/Watchlist.vue'),
            meta: { layout: 'tabs', wide: 'desktop' },
        },
        {
            path: '/alerts',
            name: 'alerts',
            component: () => import('@/Views/Alerts.vue'),
            meta: { layout: 'tabs', wide: 'desktop' },
        },
        {
            // No tab bar (design/README.md §2): you arrive here from a card, and the screen's own
            // back control is the way out.
            path: '/route/:id',
            name: 'route-detail',
            component: () => import('@/Views/RouteDetail.vue'),
            props: true,
            meta: { layout: 'bare' },
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('@/Views/Login.vue'),
            meta: { layout: 'bare', guestOnly: true },
        },
        {
            // The server hands the shell to any path it does not own; home is the honest answer,
            // since there is no content at an unknown URL to apologise for.
            path: '/:pathMatch(.*)*',
            redirect: { name: 'home' },
        },
    ],

    // Each screen starts scrolled to top, except when the browser's own back button is what did the
    // arriving — that's what `savedPosition` is.
    scrollBehavior(to, from, savedPosition) {
        return savedPosition ?? { top: 0 }
    },
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    // One /api/me round trip per page load, awaited before the first screen is allowed to render.
    await auth.ready()

    if (!to.meta.guestOnly && !auth.isAuthenticated) {
        // `redirect` so that a link into a route detail survives the detour through the login
        // screen.
        return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'home' }
    }

    return true
})
