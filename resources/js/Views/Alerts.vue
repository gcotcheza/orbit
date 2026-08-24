<script setup>
/*
 * Alerts (design/README.md §6) — how and when Orbit reaches the owner: channels, sensitivity,
 * timing, plus the app's theme/sign-out controls.
 */
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import ChangePassword from '@/Components/settings/ChangePassword.vue'
import SegmentedControl from '@/Components/settings/SegmentedControl.vue'
import SettingRow from '@/Components/settings/SettingRow.vue'
import ToggleSwitch from '@/Components/ToggleSwitch.vue'
import { useLayout } from '@/lib/layout'
import { scrollIntoView } from '@/lib/motion'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useThemeStore } from '@/stores/theme'

/*
 * The five settings sections, in the order the pane draws them. The ids are the anchors: `#account`
 * was already one (the landing head links to it), and the other four now are too.
 */
const SECTIONS = [
  { id: 'channels', label: 'Channels', gated: true },
  { id: 'sensitivity', label: 'Sensitivity', gated: true },
  { id: 'timing', label: 'Timing', gated: true },
  { id: 'account', label: 'Account', gated: false },
  { id: 'this-app', label: 'This app', gated: false },
]

// 1024px and up this screen is a section list beside two columns of cards; below it, the phone's
// single column centred in what the rail leaves (docs/DESKTOP-LAYOUT-PLAN.md phase 3).
const { isDesktop } = useLayout()

const router = useRouter()

const auth = useAuthStore()
const { user } = storeToRefs(auth)

const themeStore = useThemeStore()
const { theme } = storeToRefs(themeStore)

const settingsStore = useSettingsStore()
const { settings, sensitivities, googleChecks, status, error, isReady, chosenSensitivity } = storeToRefs(settingsStore)

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

/** "212 left this month · keeps 50 in reserve", or why there is no number. Empty until the
    response lands, which is also what draws the row. `checkedAt` null = no key was configured. */
const googleNote = computed(() => {
  if (googleChecks.value === null) {
    return ''
  }

  const { left, reserve, checkedAt } = googleChecks.value

  if (left === null) {
    return checkedAt === null ? 'Not configured' : 'Unknown right now'
  }

  return `${left} left this month · keeps ${reserve} in reserve`
})

onMounted(settingsStore.load)

/** Only the sections that are actually on the page: three of them wait on the settings landing. */
const sections = computed(() => SECTIONS.filter((one) => !one.gated || isReady.value))

/** The section last asked for, which is '' until something asks. */
const chosen = ref('')

// Falls back to the first section ON THE PAGE, so the list is never left with nothing lit while the
// gated three are still coming. No scroll-spy — two columns share every y (docs/BUSINESS-LOGIC.md §36).
const current = computed(() =>
  sections.value.some((one) => one.id === chosen.value) ? chosen.value : sections.value[0]?.id,
)

const pane = useTemplateRef('pane')

/** Jump the pane to a section, and light its row. */
function jump(id) {
  chosen.value = id

  scrollIntoView(pane.value?.querySelector(`#${id}`), { block: 'start' })
}

/*
 * Scroll to #account only once settings settle (ready/failed), not via router
 * `scrollBehavior` — before that the layout above it is not final.
 */
const route = useRoute()
const accountHeading = useTemplateRef('accountHeading')

watch(
  status,
  async (value) => {
    if (route.hash !== '#account' || (value !== 'ready' && value !== 'failed')) {
      return
    }

    await nextTick()

    chosen.value = 'account'
    scrollIntoView(accountHeading.value, { block: 'start' })
  },
  { immediate: true },
)

function save(patch) {
  settingsStore.change(patch)
}

/*
 * Empty string from a cleared time input isn't valid (server 422s); ignore it and keep the previous
 * value instead of saving (docs/BUSINESS-LOGIC.md §10).
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
  <div class="screen" :class="{ 'screen--wide': isDesktop }">
    <div class="screen__master">
      <header class="screen__head">
        <h1 class="screen__title">Alerts</h1>
        <p class="screen__note">How and when we reach you.</p>
      </header>

      <!-- Buttons, not links: a hash would be a second history entry per section, and the pane is
           what moves rather than the page. -->
      <nav v-if="isDesktop" class="seclist" aria-label="Settings sections">
        <button
          v-for="one in sections"
          :key="one.id"
          type="button"
          class="seclist__item"
          :class="{ 'seclist__item--active': one.id === current }"
          :data-section="one.id"
          :aria-current="one.id === current ? 'true' : undefined"
          @click="jump(one.id)"
        >
          {{ one.label }}
        </button>
      </nav>
    </div>

    <div ref="pane" class="screen__pane">
      <p v-if="error" class="screen__notice" role="alert">{{ error }}</p>

      <p v-if="status === 'loading' && !isReady" class="screen__state">Loading your settings…</p>

      <div v-else-if="status === 'failed' && !isReady" class="screen__state">
        <p>Could not load your alert settings.</p>
        <button type="button" class="screen__retry" @click="settingsStore.load">Try again</button>
      </div>

      <div class="screen__cards">
        <!-- `v-if`, no longer `v-else-if`: the chain above it is broken by this wrapper, and the
             three conditions were already mutually exclusive. -->
        <template v-if="isReady">
          <div class="set set--channels">
            <h2 id="channels" class="section">Channels</h2>
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
          </div>

          <div class="set set--sensitivity">
            <h2 id="sensitivity" class="section">Sensitivity</h2>
            <section class="card card--padded">
              <SegmentedControl
                :model-value="settings.sensitivity"
                :options="sensitivityOptions"
                label="Alert sensitivity"
                @update:model-value="save({ sensitivity: $event })"
              />
              <!-- Blurb (incl. score number) comes from server/config score.tiers, never
                   hardcoded, so a retuned tier can't go stale here (docs/BUSINESS-LOGIC.md §10). -->
              <p class="blurb">{{ chosenSensitivity?.blurb }}</p>
            </section>
          </div>

          <div class="set set--timing">
            <h2 id="timing" class="section">Timing</h2>
            <section class="card">
              <SettingRow title="Quiet hours" :note="quietNote" class="card__row">
                <ToggleSwitch
                  :model-value="settings.quietHours"
                  label="Quiet hours"
                  @update:model-value="save({ quietHours: $event })"
                />
              </SettingRow>

              <!-- Shown only while quiet hours is on; values persist while hidden.
                   Why: docs/BUSINESS-LOGIC.md §10. -->
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
          </div>
        </template>

        <!-- Account card is here because there's nowhere else: this is the only
             settings surface the tab bar reaches (docs/BUSINESS-LOGIC.md §10). -->
        <div class="set set--account">
          <h2 id="account" ref="accountHeading" class="section">Account</h2>
          <section class="card">
            <!-- Name and email are read-only: they are the seeder's, and no edit endpoint exists. -->
            <div class="account card__row">
              <p class="account__name">{{ user?.name }}</p>
              <p class="account__email">{{ user?.email }}</p>
            </div>

            <ChangePassword />
          </section>
        </div>

        <!-- Theme + sign-out aren't leftovers: kept here as the only screen touching
             the theme store and the session (docs/BUSINESS-LOGIC.md §10). -->
        <div class="set set--app">
          <h2 id="this-app" class="section">This app</h2>
          <section class="card">
            <!-- The note is the condition: it is empty until the response's `meta` lands. -->
            <SettingRow v-if="googleNote" title="Google price checks" :note="googleNote" class="card__row" />

            <div class="controls">
              <SegmentedControl :model-value="theme" :options="THEMES" label="Theme" @update:model-value="themeStore.set" />

              <button type="button" class="signout" @click="signOut">Sign out</button>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.screen {
  padding: 4px var(--gutter) 0;
}

/* No box of their own below 1024px, so the phone's column is the column it always was. */
.screen__master,
.screen__pane,
.screen__cards,
.set {
  display: contents;
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

.window {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 0 16px 15px;
}

/* WRAPS RATHER THAN SHRINKS: a time input has a UA minimum width it will not go under, and the card
   hides its overflow — so a narrow column clips the second one silently (docs/BUSINESS-LOGIC.md §36). */
.window__field {
  flex: 1 1 120px;
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

/* What `card--padded` gave the section, now that a hairline row shares the card. */
.controls {
  padding: 16px;
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


/* --- 1024px and up: the section list as the master, the cards in two columns -----
   Both halves of the query are lib/layout.js's, and they must be edited together
   (docs/DESKTOP-LAYOUT-PLAN.md, docs/BUSINESS-LOGIC.md §36). */

@media (min-width: 1024px) and (min-height: 600px) {
  .screen--wide {
    display: flex;
    height: 100%;
    padding: 0;
  }

  .screen--wide .screen__master {
    flex: 0 0 var(--master-width);
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 22px 18px 18px;
    overflow-y: auto;

    background: var(--panel);
    border-right: 1px solid var(--line);
  }

  .screen--wide .screen__head {
    margin: 0;
  }

  .screen--wide .screen__pane {
    flex: 1;
    min-width: 0;
    display: block;
    padding: 24px 28px;
    overflow-y: auto;
  }

  .screen--wide .screen__notice {
    margin: 0 0 14px;
  }

  /* Placed, not two column elements: one DOM order cannot feed both, and the phone's order wins
     (docs/BUSINESS-LOGIC.md §36). */
  .screen--wide .screen__cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
    gap: 20px;
  }

  .screen--wide .set {
    display: block;
    min-width: 0;
  }

  .screen--wide .set--channels,
  .screen--wide .set--timing {
    grid-column: 1;
  }

  .screen--wide .set--sensitivity,
  .screen--wide .set--account,
  .screen--wide .set--app {
    grid-column: 2;
  }

  /* The grid's gap is the air between cards now, so the label sits on its own card and not 22px
     above it — which would push each column's first row down by a label's margin. */
  .screen--wide .section {
    margin: 0 4px 9px;
  }
}

/* The master pane's section list. Only the frame mounts it, so its rules need no media query —
   the `v-if` is the guard (docs/BUSINESS-LOGIC.md §36). */

.seclist {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.seclist__item {
  display: flex;
  align-items: center;

  height: 44px;
  padding: 0 14px;
  border-radius: 12px;

  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  text-align: left;
  color: var(--muted);
}

/* The card's own edge, as an inset ring so nothing moves: --card on --panel is 1.1:1 and the
   shadow is black, so in the dark theme the current row had no shape at all. */
.seclist__item--active {
  color: var(--accent-ink);
  background: var(--card);
  box-shadow: inset 0 0 0 1px var(--line), var(--shadow);
}
</style>
