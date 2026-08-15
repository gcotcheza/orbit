// =============================================================================
// Scrolling something into view, politely
// =============================================================================
// Three screens now move the viewport on the user's behalf — the route rail
// follows the tour's selection, the watch screen jumps to the rules section, the
// alerts screen opens on the account when the profile button asked for it — and
// all three have the same two problems, which is why this file exists rather
// than the same six lines three times.
//
// PROBLEM ONE: `behavior: 'smooth'` IS NOT REACHED BY THE STYLESHEET.
// app.css sets `scroll-behavior: auto !important` under
// `prefers-reduced-motion`, which is the right thing and does nothing here: a
// `behavior` passed to scrollIntoView is a JavaScript argument and takes
// precedence over the CSS property by specification. So the media query has to
// be asked in JavaScript, at every call site, or somebody who turned animation
// off gets a smooth scroll anyway.
//
// PROBLEM TWO: NONE OF THIS EXISTS IN jsdom. It has no layout engine, so there
// is no `scrollIntoView` on an element, and it implements no media queries, so
// there is no `matchMedia` on the window. Both are optional-called rather than
// stubbed in the tests: this is a nicety of a real viewport, and a component
// whose behaviour under test depended on a fake one would be testing the fake.
// =============================================================================

/**
 * Has this person asked their device to stop moving things about?
 *
 * A browser that cannot answer has not asked, which is the honest reading of a
 * missing `matchMedia` — and the safe one, since the alternative is treating
 * every such browser as reduced-motion and quietly dropping an affordance.
 */
export function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
}

/**
 * Bring `element` into view — smoothly, unless smoothly is unwelcome.
 *
 * A null element is a no-op rather than a throw: every caller is reaching for a
 * template ref that may not be rendered yet (a list that has not loaded, a
 * section that is hidden while there is nothing in it), and "scroll to the thing
 * that is not there" has one sensible answer.
 *
 * @param {Element|null|undefined} element
 * @param {ScrollIntoViewOptions} options `block` / `inline`, per caller.
 */
export function scrollIntoView(element, options = {}) {
    element?.scrollIntoView?.({
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        ...options,
    })
}
