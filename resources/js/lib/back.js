// Checks `history.state.back` first, or a shared-link visitor with no prior entry is walked out of
// the app by router.back() (docs/BUSINESS-LOGIC.md §36).
export function goBack(router) {
    if (window.history.state?.back) {
        router.back()

        return
    }

    router.push({ name: 'home' })
}
