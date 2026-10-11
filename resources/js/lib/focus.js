/**
 * One Tab press inside a modal: the next link or button in `container`, wrapping at either end, so
 * the keyboard never reaches what the modal covers.
 */
export function cycleFocus(container, backwards) {
    const ring = [...container.querySelectorAll('a[href], button')]

    if (ring.length === 0) return

    const step = backwards ? -1 : 1
    const at = ring.indexOf(document.activeElement)
    const from = at === -1 ? (step === 1 ? -1 : 0) : at

    ring[(from + step + ring.length) % ring.length].focus()
}
