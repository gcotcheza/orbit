<script setup>
/*
 * The Account card's one control: change the password, from the phone.
 *
 * COLLAPSED UNTIL ASKED FOR. It is a row like every other row on this screen
 * until somebody taps Change, because three password boxes permanently open
 * under the alert switches would be the loudest thing on a screen about alerts —
 * and it is used roughly never.
 *
 * THE ERRORS ARE THE SERVER'S, WORD FOR WORD. App\Http\Requests\
 * UpdatePasswordRequest phrases every rule for a person ("That is not your
 * current password"), and the field each one belongs to is the key it arrives
 * under, so they land beneath the box they are about. Restating them here would
 * be two copies of the same sentences to keep in step, and the copy that drifts
 * is always the one the person reads.
 *
 * NO STRENGTH METER AND NO MODAL. The rule is twelve characters, the server is
 * the thing that enforces it, and a bar that turns amber is a second opinion
 * that can disagree with the only one that counts.
 */
import { ref } from 'vue'
import SettingRow from '@/Components/settings/SettingRow.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const open = ref(false)
const busy = ref(false)
const changed = ref(false)

/** Field name → the server's sentence about it. */
const errors = ref({})

/** Everything that is not about one field: a throttle, a stale page, a network. */
const notice = ref('')

const form = ref(blank())

function blank() {
  return { current_password: '', password: '', password_confirmation: '' }
}

/*
 * Opening and closing both CLEAR THE FIELDS, which is the whole reason this is
 * one function. A password left sitting in a collapsed form is a password in
 * the DOM of a phone somebody hands over to show a photo, and a half-typed
 * attempt restored on reopening is a form that looks like it remembers secrets.
 */
function toggle() {
  open.value = !open.value
  form.value = blank()
  errors.value = {}
  notice.value = ''
  changed.value = false
}

async function submit() {
  if (busy.value) {
    return
  }

  busy.value = true
  errors.value = {}
  notice.value = ''

  try {
    await auth.changePassword(form.value)

    // Done: collapse, forget everything typed, and say so.
    open.value = false
    form.value = blank()
    changed.value = true
  } catch (failure) {
    absorb(failure)
  } finally {
    busy.value = false
  }
}

/**
 * Turn a failed request into something the form can show.
 *
 * A 422 goes under the fields; everything else is one line above the button,
 * because a status code is not about any particular box. The 401 case is absent
 * on purpose: `lib/http.js` intercepts it and routes to the login screen, which
 * is the honest answer to a session that ended mid-form.
 */
function absorb(failure) {
  const response = failure.response

  if (!response) {
    notice.value = 'Could not reach Orbit. Your password was not changed.'

    return
  }

  switch (response.status) {
    case 422:
      // One sentence per field — the first, which is the rule that failed
      // first and the only one worth putting under a box.
      errors.value = Object.fromEntries(
        Object.entries(response.data?.errors ?? {}).map(([field, messages]) => [field, messages?.[0]]),
      )

      if (Object.keys(errors.value).length === 0) {
        notice.value = 'Orbit would not accept that.'
      }
      break
    case 429:
      notice.value = 'Too many attempts. Wait a minute, then try again.'
      break
    case 419:
      notice.value = 'This page went stale. Reload it and try again.'
      break
    default:
      notice.value = 'Something went wrong. Your password was not changed.'
  }
}
</script>

<template>
  <div>
    <SettingRow title="Password" note="Twelve characters or more">
      <button
        type="button"
        class="toggle"
        :aria-expanded="open"
        aria-controls="change-password"
        @click="toggle"
      >
        {{ open ? 'Cancel' : 'Change' }}
      </button>
    </SettingRow>

    <p v-if="changed" class="done" role="status">Password changed</p>

    <form v-if="open" id="change-password" class="form" novalidate @submit.prevent="submit">
      <label class="field">
        <span class="field__label">Current password</span>
        <input
          v-model="form.current_password"
          class="field__input"
          type="password"
          name="current_password"
          autocomplete="current-password"
          :aria-invalid="Boolean(errors.current_password)"
          required
        >
        <span v-if="errors.current_password" class="field__error">{{ errors.current_password }}</span>
      </label>

      <label class="field">
        <span class="field__label">New password</span>
        <input
          v-model="form.password"
          class="field__input"
          type="password"
          name="password"
          autocomplete="new-password"
          :aria-invalid="Boolean(errors.password)"
          required
        >
        <span v-if="errors.password" class="field__error">{{ errors.password }}</span>
      </label>

      <label class="field">
        <span class="field__label">Confirm new password</span>
        <input
          v-model="form.password_confirmation"
          class="field__input"
          type="password"
          name="password_confirmation"
          autocomplete="new-password"
          required
        >
      </label>

      <p v-if="notice" class="form__notice" role="alert">{{ notice }}</p>

      <button class="form__submit" type="submit" :disabled="busy">
        {{ busy ? 'Changing…' : 'Change password' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.toggle {
  padding: 7px 13px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card2);

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink2);
}

.done {
  margin: 0 16px 15px;
  padding: 9px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--good-ink);
  background: var(--good-bg);
}

.form {
  display: flex;
  flex-direction: column;
  gap: 12px;

  padding: 0 16px 16px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.field__label {
  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  color: var(--muted);
}

.field__input {
  width: 100%;
  padding: 10px 12px;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);
  background: var(--card2);
  color: var(--ink);

  font-size: var(--text-xl);
}

.field__input:focus {
  outline: none;
  border-color: var(--accent);
}

.field__error {
  font-size: var(--text-md);
  color: var(--warn-ink);
}

.form__notice {
  padding: 9px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

.form__submit {
  margin-top: 2px;
  padding: 11px 0;

  border-radius: var(--radius-chip);
  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--on-solid);
}

.form__submit:disabled {
  opacity: 0.6;
  cursor: progress;
}
</style>
