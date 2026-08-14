<script setup>
/*
 * Alerts / settings (design/README.md §6).
 *
 * The delivery toggles and the sensitivity control arrive in a later PR. TWO
 * controls are here already, and they are not placeholder decoration: they are
 * the only UI in this PR that exercises what this PR built. Without them the
 * theme store applies a theme nobody can change and the session can be started
 * but never ended, and neither of those can be checked on the actual phone.
 *
 * FOR WHOEVER BUILDS THIS SCREEN PROPERLY: keep both. The design puts the theme
 * switch and the account row on exactly this screen; only the surrounding
 * placeholder is meant to be deleted.
 */
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

const router = useRouter()

const auth = useAuthStore()
const { user } = storeToRefs(auth)

const themeStore = useThemeStore()
const { theme } = storeToRefs(themeStore)

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="screen rise-in">
    <header class="screen__head">
      <h1 class="screen__title">Alerts</h1>
      <p class="screen__note">How and when we reach you. The delivery toggles and alert sensitivity arrive in a later PR.</p>
    </header>

    <section class="card">
      <h2 class="card__title">Appearance</h2>
      <div class="segmented" role="group" aria-label="Theme">
        <button type="button" class="segmented__option" :class="{ 'segmented__option--on': theme === 'dark' }" :aria-pressed="theme === 'dark'" @click="themeStore.set('dark')">Dark</button>
        <button type="button" class="segmented__option" :class="{ 'segmented__option--on': theme === 'light' }" :aria-pressed="theme === 'light'" @click="themeStore.set('light')">Light</button>
      </div>
    </section>

    <section class="card">
      <h2 class="card__title">Account</h2>
      <p class="card__line">{{ user?.name }}</p>
      <p class="card__sub">{{ user?.email }}</p>
      <button type="button" class="signout" @click="signOut">Sign out</button>
    </section>
  </div>
</template>

<style scoped>
.screen {
  padding: 28px var(--gutter);
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.screen__title {
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.screen__note {
  margin-top: 4px;
  font-size: var(--text-lg);
  color: var(--muted);
}

.card {
  padding: 16px 17px;
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

.card__title {
  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  color: var(--muted);
}

.card__line {
  margin-top: 10px;
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--ink);
}

.card__sub {
  font-size: var(--text-md);
  color: var(--muted);
}

.segmented {
  display: flex;
  gap: 6px;
  margin-top: 12px;
  padding: 4px;
  border-radius: var(--radius-pill);
  background: var(--card2);
}

.segmented__option {
  flex: 1;
  padding: 8px 0;
  border-radius: var(--radius-pill);
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink2);
  transition: background 0.18s ease, color 0.18s ease;
}

.segmented__option--on {
  background: var(--accent);
  color: #fff;
}

.signout {
  margin-top: 14px;
  width: 100%;
  padding: 11px 0;
  border-radius: var(--radius-chip);
  border: 1px solid var(--line);
  font-size: var(--text-xl);
  font-weight: 600;
  color: var(--warn-ink);
  background: var(--warn-bg);
}
</style>
