<script setup>
import { computed, onActivated, onDeactivated, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
  /** The destination, byte for byte — the marker query is affiliate attribution. */
  href: { type: String, required: true },
})

const emit = defineEmits(['close'])

const NAMED_SITES = {
  'aviasales.com': 'Aviasales',
  'skyscanner.nl': 'Skyscanner',
}

const panel = ref(null)
let onward = 0

const site = computed(() => {
  const host = new URL(props.href, window.location.href).hostname.replace(/^www\./, '')

  return NAMED_SITES[host] ?? (host || 'the booking site')
})

const sentence = computed(() => {
  const named = site.value.replace(/^./, (first) => first.toUpperCase())

  return `${named} opens in a new tab, so Orbit stays where it is. The price and availability`
    + ' there are theirs, and can differ from what we recorded this morning.'
})

function stops() {
  return [...panel.value.querySelectorAll('a[href], button')]
}

// Capture, and swallowed: a modal over the day sheet must not let one Escape
// close both.
function onKeydown(event) {
  if (event.key !== 'Escape' && event.key !== 'Tab') {
    return
  }

  event.preventDefault()
  event.stopPropagation()

  if (event.key === 'Escape') {
    emit('close')

    return
  }

  const ring = stops()
  const step = event.shiftKey ? -1 : 1
  const at = ring.indexOf(document.activeElement)
  const from = at === -1 ? (step === 1 ? -1 : 0) : at

  ring[(from + step + ring.length) % ring.length].focus()
}

// Removing the anchor inside its own click cancels the navigation it started.
function onContinue() {
  onward = setTimeout(() => emit('close'))
}

function arm() {
  window.addEventListener('keydown', onKeydown, true)
}

function release() {
  window.removeEventListener('keydown', onKeydown, true)
}

onMounted(() => {
  arm()
  panel.value.focus()
})

onActivated(arm)

// `Home` is kept alive (App.vue), so a Back navigation deactivates this rather
// than unmounting it: it must let go of the keyboard and close, not wait.
onDeactivated(() => {
  release()
  emit('close')
})

onUnmounted(() => {
  release()
  clearTimeout(onward)
})
</script>

<template>
  <Teleport to="body">
    <div class="leaving__scrim" @click="$emit('close')"></div>

    <div
      ref="panel"
      class="leaving"
      role="dialog"
      aria-modal="true"
      aria-labelledby="leaving-orbit-title"
      tabindex="-1"
    >
      <h2 id="leaving-orbit-title" class="leaving__title">You're leaving Orbit</h2>
      <p class="leaving__body">{{ sentence }}</p>

      <div class="leaving__actions">
        <button type="button" class="leaving__action leaving__stay" @click="$emit('close')">Stay in Orbit</button>

        <a
          class="leaving__action leaving__continue"
          :href="href"
          target="_blank"
          rel="noopener"
          @click="onContinue"
        >Continue to {{ site }}</a>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.leaving__scrim {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: var(--scrim);
}

.leaving {
  position: fixed;
  z-index: 41;
  top: 50%;
  inset-inline: var(--gutter);
  transform: translateY(-50%);

  max-width: calc(var(--shell-max) - 2 * var(--gutter));
  margin-inline: auto;

  padding: 22px;
  border: 1px solid var(--line);
  border-radius: var(--radius-card);

  background: var(--panel);
  box-shadow: var(--shadow);
}

.leaving__title {
  margin: 0;
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  color: var(--ink);
}

.leaving__body {
  margin: 10px 0 0;
  font-size: var(--text-lg);
  line-height: 1.55;
  color: var(--ink2);
}

.leaving__actions {
  display: flex;
  gap: 10px;
  margin-top: 20px;
}

/* A finger, not a line of text: 44px stands whatever the label wraps to. */
.leaving__action {
  flex: 1;
  min-width: 0;
  min-height: 44px;

  display: flex;
  align-items: center;
  justify-content: center;

  padding: 0 14px;
  border-radius: var(--radius-pill);

  font-family: var(--font-body);
  font-size: var(--text-lg);
  font-weight: 600;
  text-align: center;
  text-decoration: none;
  cursor: pointer;
}

.leaving__stay {
  border: 1px solid var(--line);
  background: transparent;
  color: var(--ink2);
}

.leaving__continue {
  flex: 1.5;
  border: 1px solid transparent;
  background: var(--accent);
  color: var(--on-solid);
}
</style>
