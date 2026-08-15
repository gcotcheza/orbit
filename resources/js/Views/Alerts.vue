<script setup>
/*
 * Alerts (design/README.md §6) — how and when Orbit reaches the owner.
 *
 * Four cards: the delivery channels, the sensitivity that decides what counts
 * as worth telling them about, the timing, and the two controls that were
 * already here.
 *
 * THE THEME SWITCH AND SIGN-OUT ARE NOT LEFTOVERS. They landed in PR4 as the
 * only UI exercising the theme store and the session, and the design puts both
 * on this screen — the placeholder prose around them is what this PR deletes.
 * They keep their own card at the bottom because they are about the app;
 * everything above is about alerts.
 *
 * THE ACCOUNT CARD IS THE EXCEPTION TO "everything here is about alerts", and
 * it is here because there is nowhere else: this screen is the only settings
 * surface the tab bar reaches, and a password that can only be changed by
 * running the seeder again on a box is one the owner will never change. It sits
 * BELOW the alert settings and ABOVE the app card, which is the order of how
 * often each is touched. Who you are moved into it from the app card in the
 * same PR, so the card says what account the password belongs to.
 *
 * THE SENSITIVITY BLURB COMES FROM THE SERVER. Each level's sentence quotes
 * the deal score it fires at, that number is config/orbit.php's `score.tiers`,
 * and it is the same number the API publishes as a route's `tier`. A "score
 * 80+" typed into this template would be a promise that goes quietly wrong the
 * day the tier is retuned — on the one screen whose entire job is to say what
 * will happen.
 */
import { computed, nextTick, onMounted, useTemplateRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import ChangePassword from '@/Components/settings/ChangePassword.vue'
import SegmentedControl from '@/Components/settings/SegmentedControl.vue'
import SettingRow from '@/Components/settings/SettingRow.vue'
import ToggleSwitch from '@/Components/ToggleSwitch.vue'
import { scrollIntoView } from '@/lib/motion'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useThemeStore } from '@/stores/theme'

const router = useRouter()

const auth = useAuthStore()
const { user } = storeToRefs(auth)

const themeStore = useThemeStore()
const { theme } = storeToRefs(themeStore)

const settingsStore = useSettingsStore()
const { settings, sensitivities, status, error, isReady, chosenSensitivity } = storeToRefs(settingsStore)

const THEMES = [
  { value: 'dark', label: 'Dark' },
  { value: 'light', label: 'Light' },
]

const sensitivityOptions = computed(() =>
  sensitivities.value.map((level) => ({ value: level.level, label: level.name })),
)

/** "No pings 22:00 – 08:00", or the honest version when they are off. */
const quietNote = computed(() => {
  if (settings.value === null) {
    return ''
  }

  return settings.value.quietHours
    ? `No pings ${settings.value.quietStart} – ${settings.value.quietEnd}`
    : 'Orbit may ping at any hour'
})

onMounted(settingsStore.load)

/*
 * =============================================================================
 * ARRIVING AT THE ACCOUNT, WHEN THAT IS WHAT WAS ASKED FOR
 * =============================================================================
 * The home screen's round PERSON button points here with `#account` on it,
 * because that glyph promises the account and this screen opens on alerts. The
 * tab bar's own Alerts item carries no hash and lands at the top, as it should —
 * the two entrances mean different things and now arrive in different places.
 *
 * WHY NOT THE ROUTER'S `scrollBehavior`. It runs one tick after the navigation
 * is confirmed, which on this screen is while `GET /api/settings` is still in
 * flight: the account card is rendered (it is outside the loading gate, because
 * it needs no settings) but everything ABOVE it is not, so it is a few hundred
 * pixels higher than where it will end up. Scrolling to it then and letting four
 * cards appear above it afterwards lands the reader in the middle of the quiet
 * hours. Waiting for the request to settle — either way, `ready` or `failed` —
 * is the difference between arriving at the account and arriving near it.
 *
 * `immediate`, so a screen whose settings were already in the store (this
 * component is not kept alive, but the store is) does not wait for a status
 * change that has already happened.
 */
const route = useRoute()
const accountHeading = useTemplateRef('accountHeading')

watch(
  status,
  async (value) => {
    if (route.hash !== '#account' || value === 'loading') {
      return
    }

    await nextTick()

    scrollIntoView(accountHeading.value, { block: 'start' })
  },
  { immediate: true },
)

function save(patch) {
  settingsStore.change(patch)
}

/*
 * A time input can be cleared, and an empty string is not a time — the server
 * would answer 422 and the switch would revert for a reason nobody typed.
 * Ignoring the empty state leaves the previous value in place, which is what
 * an emptied field means here.
 */
function saveTime(field, value) {
  if (value !== '') {
    save({ [field]: value })
  }
}

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="screen">
    <header class="screen__head">
      <h1 class="screen__title">Alerts</h1>
      <p class="screen__note">How and when we reach you.</p>
    </header>

    <p v-if="error" class="screen__notice" role="alert">{{ error }}</p>

    <p v-if="status === 'loading' && !isReady" class="screen__state">Loading your settings…</p>

    <div v-else-if="status === 'failed' && !isReady" class="screen__state">
      <p>Could not load your alert settings.</p>
      <button type="button" class="screen__retry" @click="settingsStore.load">Try again</button>
    </div>

    <template v-else-if="isReady">
      <h2 class="section">Channels</h2>
      <section class="card">
        <SettingRow title="Email" :note="user?.email ?? 'Where the deals land'" class="card__row">
          <template #icon>
            <span class="icon icon--info">
              <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
                <rect x="2" y="4" width="14" height="10" rx="2" stroke="var(--info)" stroke-width="1.4" />
                <path d="M3 5l6 4 6-4" stroke="var(--info)" stroke-width="1.4" />
              </svg>
            </span>
          </template>

          <ToggleSwitch
            :model-value="settings.emailAlerts"
            label="Email alerts"
            @update:model-value="save({ emailAlerts: $event })"
          />
        </SettingRow>

        <SettingRow title="Push" note="This device, once Orbit is installed">
          <template #icon>
            <span class="icon icon--warn">
              <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
                <path d="M9 2a4 4 0 0 0-4 4v3.5L3.5 12h11L13 9.5V6a4 4 0 0 0-4-4Z" stroke="var(--warn)" stroke-width="1.4" stroke-linejoin="round" />
                <path d="M7 14a2 2 0 0 0 4 0" stroke="var(--warn)" stroke-width="1.4" />
              </svg>
            </span>
          </template>

          <ToggleSwitch
            :model-value="settings.pushAlerts"
            label="Push alerts"
            @update:model-value="save({ pushAlerts: $event })"
          />
        </SettingRow>
      </section>

      <h2 class="section">Sensitivity</h2>
      <section class="card card--padded">
        <SegmentedControl
          :model-value="settings.sensitivity"
          :options="sensitivityOptions"
          label="Alert sensitivity"
          @update:model-value="save({ sensitivity: $event })"
        />
        <p class="blurb">{{ chosenSensitivity?.blurb }}</p>
      </section>

      <h2 class="section">Timing</h2>
      <section class="card">
        <SettingRow title="Quiet hours" :note="quietNote" class="card__row">
          <ToggleSwitch
            :model-value="settings.quietHours"
            label="Quiet hours"
            @update:model-value="save({ quietHours: $event })"
          />
        </SettingRow>

        <!-- Revealed by the switch above, per the design: the window is only
             worth showing while it is doing something. The values are kept
             either way, so switching quiet hours back on restores them. -->
        <div v-if="settings.quietHours" class="window card__row">
          <label class="window__field">
            <span class="window__label">From</span>
            <input
              class="window__input tabular"
              type="time"
              :value="settings.quietStart"
              @change="saveTime('quietStart', $event.target.value)"
            >
          </label>

          <label class="window__field">
            <span class="window__label">Until</span>
            <input
              class="window__input tabular"
              type="time"
              :value="settings.quietEnd"
              @change="saveTime('quietEnd', $event.target.value)"
            >
          </label>
        </div>

        <SettingRow title="Weekly digest" note="A Sunday round-up of deals">
          <ToggleSwitch
            :model-value="settings.weeklyDigest"
            label="Weekly digest"
            @update:model-value="save({ weeklyDigest: $event })"
          />
        </SettingRow>
      </section>
    </template>

    <h2 id="account" ref="accountHeading" class="section">Account</h2>
    <section class="card">
      <!-- Who this is, above the one thing that can be changed about it. The
           name and address are not editable and are not meant to be: they are
           the seeder's, and the API has no endpoint for either. -->
      <div class="account card__row">
        <p class="account__name">{{ user?.name }}</p>
        <p class="account__email">{{ user?.email }}</p>
      </div>

      <ChangePassword />
    </section>

    <h2 class="section">This app</h2>
    <section class="card card--padded">
      <SegmentedControl :model-value="theme" :options="THEMES" label="Theme" @update:model-value="themeStore.set" />

      <button type="button" class="signout" @click="signOut">Sign out</button>
    </section>
  </div>
</template>

<style scoped>
.screen {
  padding: 4px var(--gutter) 0;
}

.screen__head {
  margin: 8px 2px 4px;
}

.screen__title {
  font-family: var(--font-display);
  font-size: var(--text-2xl);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.screen__note {
  margin-top: 2px;
  font-size: var(--text-lg);
  color: var(--muted);
}

.screen__notice {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

.screen__state {
  margin-top: 28px;
  text-align: center;
  font-size: var(--text-lg);
  color: var(--muted);
}

.screen__retry {
  margin-top: 12px;
  padding: 9px 16px;
  border-radius: var(--radius-chip);
  border: 1px solid var(--line);

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink2);
}

.section {
  margin: 22px 4px 9px;

  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--muted);
}

.card {
  overflow: hidden;

  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--card);
  box-shadow: var(--shadow);
}

.card--padded {
  padding: 16px;
}

/* The hairline BETWEEN rows, never after the last one. */
.card__row {
  border-bottom: 1px solid var(--line2);
}

.icon {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 100%;
  height: 100%;
  border-radius: 10px;
}

.icon--info {
  background: var(--info-bg);
}

.icon--warn {
  background: var(--warn-bg);
}

.blurb {
  margin-top: 12px;
  padding: 0 2px;

  font-size: 12.5px;
  line-height: 1.45;
  color: var(--muted);
}

/* --- The quiet window ---------------------------------------------------- */

.window {
  display: flex;
  gap: 10px;
  padding: 0 16px 15px;
}

.window__field {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.window__label {
  font-size: var(--text-sm);
  font-weight: 700;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  color: var(--muted);
}

.window__input {
  width: 100%;
  padding: 9px 11px;

  border: 1px solid var(--line);
  border-radius: 11px;
  background: var(--card2);
  color: var(--ink);

  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 600;
}

.window__input:focus {
  outline: none;
  border-color: var(--accent);
}

/* --- Account ------------------------------------------------------------- */

.account {
  padding: 14px 16px;
}

.account__name {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--ink);
}

.account__email {
  font-size: var(--text-md);
  color: var(--muted);
}

.signout {
  width: 100%;
  margin-top: 14px;
  padding: 11px 0;

  border: 1px solid var(--line);
  border-radius: var(--radius-chip);

  font-size: var(--text-xl);
  font-weight: 600;
  color: var(--warn-ink);
  background: var(--warn-bg);
}
</style>
