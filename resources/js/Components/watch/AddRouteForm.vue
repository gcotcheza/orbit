<script setup>
/*
 * The "Add route" expander (design/README.md §5): three origin buttons, a
 * three-letter destination box, one button.
 *
 * THE THREE ORIGINS ARE WRITTEN OUT HERE and the server validates against
 * `config('orbit.origins')`. That is one fact in two places, and it is the
 * deliberate choice: the alternative is a fifth endpoint whose entire job is
 * to send three strings that have not changed since the design was drawn, on
 * every visit to this screen. If they ever diverge the server wins and says so
 * — "Orbit only tracks departures from AMS, EIN or DUS." is a message this
 * form displays verbatim — so the failure is visible rather than silent.
 *
 * THE CLIENT-SIDE CHECK IS A COURTESY, NOT A GUARD. It catches "LIS " and
 * "lisbon" without a round trip; everything that matters — does the airport
 * exist, is the pair already watched — can only be answered by the server, and
 * this component renders whatever it says.
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  /** The server's 422 message for the last attempt, if there was one. */
  error: { type: String, default: '' },

  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['submit'])

const ORIGINS = ['AMS', 'EIN', 'DUS']

const origin = ref(ORIGINS[0])
const destination = ref('')
const localError = ref('')

const canSubmit = computed(() => destination.value.length === 3 && !props.busy)

// A fresh attempt clears the client-side complaint; the server's own message
// is cleared by whoever owns the request.
watch(destination, () => {
  localError.value = ''
})

/*
 * Upper-cased and stripped to letters AS IT IS TYPED, rather than on submit.
 * A destination box that shows `lis` and sends `LIS` is a box that disagrees
 * with the row it produces, and three letters is short enough that the
 * correction is invisible rather than jarring.
 */
function onInput(event) {
  destination.value = event.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3)
}

function submit() {
  if (props.busy) {
    return
  }

  if (!/^[A-Z]{3}$/.test(destination.value)) {
    localError.value = 'A destination is a three-letter airport code, like LIS.'

    return
  }

  if (destination.value === origin.value) {
    localError.value = 'A route needs two different airports.'

    return
  }

  emit('submit', { origin: origin.value, destination: destination.value })
}

/** Called by the parent once the route has actually landed. */
function reset() {
  destination.value = ''
  localError.value = ''
}

defineExpose({ reset })
</script>

<template>
  <form class="add rise-in" novalidate @submit.prevent="submit">
    <p id="add-origin-label" class="add__label">From</p>
    <div class="add__origins" role="radiogroup" aria-labelledby="add-origin-label">
      <button
        v-for="option in ORIGINS"
        :key="option"
        type="button"
        role="radio"
        class="origin"
        :class="{ 'origin--on': option === origin }"
        :aria-checked="option === origin"
        @click="origin = option"
      >
        {{ option }}
      </button>
    </div>

    <label class="add__label" for="add-destination">To</label>
    <input
      id="add-destination"
      class="add__input"
      :value="destination"
      type="text"
      inputmode="text"
      autocapitalize="characters"
      autocomplete="off"
      spellcheck="false"
      maxlength="3"
      placeholder="e.g. LIS"
      @input="onInput"
    >

    <p v-if="localError || error" class="add__error" role="alert">{{ localError || error }}</p>

    <button class="add__submit" type="submit" :disabled="!canSubmit">
      {{ busy ? 'Adding…' : 'Add route' }}
    </button>
  </form>
</template>

<style scoped>
.add {
  margin-top: 14px;
  padding: 16px;

  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--card);
  box-shadow: var(--shadow);
}

.add__label {
  display: block;

  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--muted);
}

.add__label:not(:first-child) {
  margin-top: 14px;
}

.add__origins {
  display: flex;
  gap: 8px;
  margin-top: 9px;
}

.origin {
  flex: 1;
  height: 42px;
  border-radius: 11px;

  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 700;

  color: var(--ink2);
  background: var(--card2);
  border: 1.5px solid var(--line);

  transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
}

.origin--on {
  color: var(--on-solid);
  background: var(--accent);
  border-color: var(--accent);
}

.add__input {
  width: 100%;
  height: 44px;
  margin-top: 9px;
  padding: 0 14px;

  border: 1.5px solid var(--line);
  border-radius: 11px;
  background: var(--card2);
  color: var(--ink);

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 600;
  letter-spacing: 0.05em;
}

.add__input:focus {
  outline: none;
  border-color: var(--accent);
}

.add__error {
  margin-top: 12px;
  padding: 9px 11px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

.add__submit {
  width: 100%;
  height: 46px;
  margin-top: 14px;
  border-radius: 12px;

  /* The design's own inverted button: ink on the page, page colour on the
     button. It is the only one on this screen, so it does not compete with the
     accent + in the header. */
  background: var(--ink);
  color: var(--bg);

  font-size: var(--text-xl);
  font-weight: 600;
}

.add__submit:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
</style>
