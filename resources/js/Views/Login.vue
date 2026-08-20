<script setup>
/*
 * The only screen a guest can reach.
 *
 * No "create an account", no "forgot your password" — neither route exists on
 * the server (routes/web.php), so offering either would be a link to a 404.
 * Orbit has one account and it is created by a seeder.
 */
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

/**
 * Turn a failed request into one sentence somebody can act on.
 *
 * The 422 message comes from the server and is deliberately the same for a
 * wrong address and a wrong password — see LoginController. The others are
 * written here because their HTTP status is the whole message.
 */
function messageFor(failure) {
  const response = failure.response

  if (!response) {
    return 'Could not reach Orbit. Check the connection and try again.'
  }

  switch (response.status) {
    case 422:
      return response.data?.errors?.email?.[0] ?? 'Those details did not work.'
    case 429:
      return 'Too many attempts. Wait a minute, then try again.'
    case 419:
      // The session behind the page expired while the form sat open. Reloading fetches a fresh
      // token, which is genuinely the fix.
      return 'This page went stale. Reload it and sign in again.'
    default:
      return 'Something went wrong signing in.'
  }
}

async function submit() {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = ''

  try {
    await auth.login({ email: email.value, password: password.value })

    // Back to wherever the guard interrupted, or to the globe.
    await router.replace(route.query.redirect ?? { name: 'home' })
  } catch (failure) {
    error.value = messageFor(failure)
    password.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="login">
    <header class="login__head">
      <p class="login__eyebrow"><span class="login__dot"></span>Tracking live</p>
      <h1 class="login__title">Orbit</h1>
      <p class="login__sub">Sign in to see what got cheap.</p>
    </header>

    <form class="login__form rise-in" novalidate @submit.prevent="submit">
      <label class="field">
        <span class="field__label">Email</span>
        <input
          v-model="email"
          class="field__input"
          type="email"
          name="email"
          autocomplete="username"
          inputmode="email"
          autocapitalize="none"
          spellcheck="false"
          required
        >
      </label>

      <label class="field">
        <span class="field__label">Password</span>
        <input
          v-model="password"
          class="field__input"
          type="password"
          name="password"
          autocomplete="current-password"
          required
        >
      </label>

      <p v-if="error" class="login__error" role="alert">{{ error }}</p>

      <button class="login__submit" type="submit" :disabled="busy">
        {{ busy ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.login {
  display: flex;
  flex-direction: column;
  justify-content: center;
  /* Fills the shell's main area. The safe-area inset is subtracted because
     .app-shell__main has already spent it as padding — 100dvh on top of that
     is a screen that scrolls by the height of the notch. */
  min-height: calc(100dvh - env(safe-area-inset-top));
  padding: 32px var(--gutter);
}

.login__eyebrow {
  display: flex;
  align-items: center;
  gap: 7px;

  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
}

.login__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--good);
  animation: orbit-pulse 2.2s ease-in-out infinite;
}

.login__title {
  margin-top: 8px;
  font-family: var(--font-display);
  font-size: var(--text-3xl);
  font-weight: 700;
  letter-spacing: -0.03em;
  line-height: 1;
  color: var(--ink);
}

.login__sub {
  margin-top: 8px;
  font-size: var(--text-xl);
  color: var(--muted);
}

.login__form {
  display: flex;
  flex-direction: column;
  gap: 12px;

  margin-top: 26px;
  padding: 18px 17px;
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
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
  padding: 11px 13px;
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

.login__error {
  padding: 10px 12px;
  border-radius: var(--radius-chip);
  background: var(--warn-bg);
  color: var(--warn-ink);
  font-size: var(--text-lg);
}

.login__submit {
  margin-top: 4px;
  padding: 13px 0;
  border-radius: var(--radius-chip);
  background: var(--accent);
  box-shadow: 0 6px 16px var(--accent-glow);
  color: var(--on-solid);
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  letter-spacing: 0.01em;
}

.login__submit:disabled {
  opacity: 0.6;
  cursor: progress;
}
</style>
