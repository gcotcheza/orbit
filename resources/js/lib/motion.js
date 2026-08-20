/**
 * Has this person asked their device to stop moving things about? A missing
 * `matchMedia` reads as "no" rather than reduced-motion, to avoid silently
 * dropping the affordance.
 *
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
export function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
}

/**
 * Bring `element` into view — smoothly, unless smoothly is unwelcome. A null
 * element is a no-op, not a throw: callers reach for template refs that may
 * not be rendered yet.
 *
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * @param {Element|null|undefined} element
 * @param {ScrollIntoViewOptions} options `block` / `inline`, per caller.
 */
export function scrollIntoView(element, options = {}) {
    // `behavior` here beats app.css's reduced-motion override — a JS argument
    // outranks the CSS property by spec.
    // Why: docs/BUSINESS-LOGIC.md §36.
    //
    // jsdom has no scrollIntoView/matchMedia; optional-chained rather than
    // stubbed, so behaviour isn't tested against a fake.
    // Why: docs/BUSINESS-LOGIC.md §36.
    element?.scrollIntoView?.({
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        ...options,
    })
}
