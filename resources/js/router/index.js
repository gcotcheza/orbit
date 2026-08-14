// =============================================================================
// Routes
// =============================================================================
// `createWebHistory`, not hashes: every screen has a real URL, which is what
// makes a route detail shareable and what lets the PWA be launched straight
// into one. The server plays along — routes/web.php answers every non-API path
// with the same shell.
//
// `meta.layout` picks the chrome. Two values today: 'tabs' (the five-item
// bottom bar) and 'bare' (route detail and login, which fill the screen).
// See App.vue for why that is a string on the route rather than a swappable
// layout COMPONENT: swapping the component would remount the tree below it, and
// the globe's <KeepAlive> cache with it, every time the user opened a route and
// came back.
//
// `meta.guestOnly` is the whole authorisation model, and it mirrors the
// server's: routes/web.php puts POST /login behind Laravel's `guest`
// middleware and everything else behind `auth`. It is opt-IN and it is the
// EXCEPTION — a route without it needs a session — so a screen added later is
// private by default and somebody has to type the flag to change that.
// =============================================================================
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

export const router = createRouter({
    history: createWebHistory(),

    routes: [
        {
            path: '/',
            name: 'home',
            component: () => import('@/Views/Home.vue'),
            meta: { layout: 'tabs' },
        },
        {
            path: '/calendar',
            name: 'calendar',
            component: () => import('@/Views/Calendar.vue'),
            meta: { layout: 'tabs' },
        },
        {
            path: '/create',
            name: 'create',
            component: () => import('@/Views/Create.vue'),
            meta: { layout: 'tabs' },
        },
        {
            path: '/watch',
            name: 'watch',
            component: () => import('@/Views/Watchlist.vue'),
            meta: { layout: 'tabs' },
        },
        {
            path: '/alerts',
            name: 'alerts',
            component: () => import('@/Views/Alerts.vue'),
            meta: { layout: 'tabs' },
        },
        {
            // No tab bar, per design/README.md §2: the detail screen is
            // something you came INTO from a card, and its own back control is
            // the way out.
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
            // The server hands the shell to every path it does not own, so a
            // typo in the address bar arrives HERE rather than at a 404 page.
            // Home is the honest answer: this app has no content at an unknown
            // URL to apologise for.
            path: '/:pathMatch(.*)*',
            redirect: { name: 'home' },
        },
    ],

    // Every screen is its own page, so arriving at one should not inherit the
    // last one's scroll offset — except when the browser's own back button is
    // what did the arriving, which is what `savedPosition` is.
    scrollBehavior(to, from, savedPosition) {
        return savedPosition ?? { top: 0 }
    },
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    // One /api/me round trip per page load, awaited before the first screen is
    // allowed to render.
    await auth.ready()

    if (!to.meta.guestOnly && !auth.isAuthenticated) {
        // `redirect` so that a link into a route detail survives the detour
        // through the login screen.
        return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'home' }
    }

    return true
})
