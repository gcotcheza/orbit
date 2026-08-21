/**
 * Has this person asked their device to stop moving things about? A missing `matchMedia` reads
 * as "no" rather than reduced-motion, so the affordance is not silently dropped.
 */
export function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
}

/**
 * Bring `element` into view — smoothly, unless smoothly is unwelcome. A null element is a
 * no-op, not a throw: callers reach for template refs that may not be rendered yet.
 *
 * @param {Element|null|undefined} element
 * @param {ScrollIntoViewOptions} options `block` / `inline`, per caller.
 */
export function scrollIntoView(element, options = {}) {
    // `behavior` here beats app.css's reduced-motion override — a JS argument outranks the CSS
    // property by spec. jsdom has no scrollIntoView, hence the optional call.
    element?.scrollIntoView?.({
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        ...options,
    })
}
