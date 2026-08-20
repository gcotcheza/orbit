<script setup>
/*
 * The natural-language rule creator (design/README.md §4). Chips are
 * removable and re-parse the sentence; they never rewrite the text.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import MatchBanner from '@/Components/rules/MatchBanner.vue'
import RuleChip from '@/Components/rules/RuleChip.vue'
import { useRulesStore } from '@/stores/rules'

/* The design's own onboarding example — kept here, not in the store,
   because it is copy that belongs with the screen that shows it. */
const SEED = 'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80'

/** design/README.md §4 — long enough to read while typing, short enough to feel live. */
const DEBOUNCE_MS = 500

const rules = useRulesStore()
const { chips, matches, parseStatus, understood, error } = storeToRefs(rules)

const text = ref(SEED)
const removed = ref([])
const saving = ref(false)
const created = ref(null)

let timer = null

/* The text the reading on screen is of. Only the CTA waits on it; the chips'
   × never does. Why: docs/BUSINESS-LOGIC.md §11. */
const asked = ref(SEED)

const parsing = computed(() => parseStatus.value === 'parsing')
const failed = computed(() => parseStatus.value === 'failed')
const readingStale = computed(() => parsing.value || text.value !== asked.value)
const canCreate = computed(() => understood.value && !saving.value && !readingStale.value)

/** Ask now, cancelling any wait — every chip change comes through here. */
function ask() {
  clearTimeout(timer)
  asked.value = text.value
  rules.parse(text.value, removed.value)
}

onMounted(ask)

onBeforeUnmount(() => {
  clearTimeout(timer)
  rules.clearReading()
})

/* Typing is the only thing debounced. Text back at the reading's own cancels
   the wait instead of asking twice — unless that ask is the one that failed. */
watch(text, (value) => {
  clearTimeout(timer)

  if (value !== asked.value || failed.value) {
    timer = setTimeout(ask, DEBOUNCE_MS)
  }
})

function removeChip(id) {
  if (!removed.value.includes(id)) {
    removed.value = [...removed.value, id]
    ask()
  }
}

/** Reset puts back everything the sentence says — it does not touch the text. */
function reset() {
  removed.value = []
  ask()
}

function startOver() {
  created.value = null
  text.value = ''
  removed.value = []
  ask()
}

async function save() {
  if (!canCreate.value) {
    return
  }

  saving.value = true

  try {
    created.value = await rules.create(text.value, removed.value)
  } catch {
    // The store put the sentence to show in `error`; the form stays as it was.
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="screen">
    <template v-if="created">
      <header class="screen__head">
        <h1 class="screen__title">Rule created</h1>
        <p class="screen__note">Orbit is watching for it from now on.</p>
      </header>

      <div class="done rise-in">
        <p class="done__text">“{{ created.text }}”</p>

        <div class="done__chips">
          <span v-for="chip in created.chips" :key="chip.id" class="done__chip">{{ chip.label }}</span>
        </div>

        <p class="done__promise">
          We'll tell you when a trip like this turns up
          <template v-if="created.matches.count > 0">
            — {{ created.matches.count }} already match, from €{{ created.matches.cheapest }}.
          </template>
          <template v-else>
            . Orbit is off to price the routes it is about; the first matches land within the hour.
          </template>
        </p>

        <RouterLink :to="{ name: 'watch' }" class="done__link">See it on your watch list</RouterLink>
        <button type="button" class="done__again" @click="startOver">Write another rule</button>
      </div>
    </template>

    <template v-else>
      <header class="screen__head">
        <h1 class="screen__title">New deal rule</h1>
        <p class="screen__note">Describe the trip you're dreaming of.</p>
      </header>

      <div class="compose">
        <label class="compose__label" for="rule-text">Your rule</label>
        <textarea
          id="rule-text"
          v-model="text"
          class="compose__input"
          rows="3"
          maxlength="500"
          placeholder="cheap weekend somewhere sunny, under €80"
          autocomplete="off"
          spellcheck="false"
        ></textarea>
      </div>

      <div class="understood">
        <h2 class="understood__title">Here's what we understood</h2>
        <button
          v-if="removed.length > 0"
          type="button"
          class="understood__reset"
          @click="reset"
        >
          Reset
        </button>
      </div>

      <div v-if="understood" class="chips">
        <RuleChip
          v-for="chip in chips"
          :key="chip.id"
          :chip="chip"
          @remove="removeChip"
        />
      </div>

      <p v-else-if="parsing && !understood" class="empty">Reading that…</p>

      <p v-else-if="text.trim() === ''" class="empty">
        Name a price, a season, a day of the week or what the trip is for — “a beach week in June under €150”.
      </p>

      <p v-else class="empty">
        Orbit could not read a trip out of that yet. Try naming a price, a season, a day or what the trip is for.
      </p>

      <MatchBanner v-if="understood" class="banner" :matches="matches" :loading="parsing" />

      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <button class="cta" type="button" :disabled="!canCreate" @click="save">
        {{ saving ? 'Creating…' : 'Create rule' }}
      </button>
    </template>
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

/* The textarea is the screen's one focal point, so it carries the accent
   border and glow the design gives it even when it is not focused. */
.compose {
  margin-top: 16px;
  padding: 14px 15px;

  border: 1.5px solid var(--accent);
  border-radius: 18px;
  background: var(--card);
  box-shadow: 0 6px 20px var(--accent-glow);
}

.compose__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

.compose__input {
  display: block;
  width: 100%;
  resize: none;

  border: none;
  background: transparent;
  color: var(--ink);

  font-family: var(--font-body);
  font-size: 15.5px;
  line-height: 1.5;
}

.compose__input:focus {
  outline: none;
}

.compose__input::placeholder {
  color: var(--muted);
}

.understood {
  display: flex;
  align-items: center;
  justify-content: space-between;

  margin: 20px 2px 11px;
}

.understood__title {
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
}

.understood__reset {
  font-size: var(--text-md);
  font-weight: 600;
  color: var(--accent-ink);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
}

.empty {
  padding: 2px;

  font-size: var(--text-lg);
  color: var(--muted);
}

.banner {
  margin-top: 20px;
}

.error {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: var(--radius-chip);

  font-size: var(--text-lg);
  color: var(--warn-ink);
  background: var(--warn-bg);
}

/* The design's inverted button: ink on the page, page colour on the button. */
.cta {
  width: 100%;
  height: 52px;
  margin-top: 18px;
  margin-bottom: 24px;
  border-radius: 15px;

  font-family: var(--font-display);
  font-size: 16px;
  font-weight: 700;
  color: var(--bg);
  background: var(--ink);
}

.cta:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.done {
  margin-top: 16px;
  padding: 18px 16px;

  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  background: var(--card);
  box-shadow: var(--shadow);
}

.done__text {
  font-size: var(--text-xl);
  font-style: italic;
  color: var(--ink);
}

.done__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;

  margin-top: 13px;
}

.done__chip {
  padding: 5px 10px;
  border-radius: var(--radius-pill);

  font-family: var(--font-display);
  font-size: var(--text-md);
  font-weight: 600;
  color: var(--ink2);
  background: var(--card2);
}

.done__promise {
  margin-top: 15px;

  font-size: var(--text-lg);
  color: var(--muted);
}

.done__link {
  display: block;
  width: 100%;
  height: 46px;
  margin-top: 16px;
  border-radius: 12px;

  font-size: var(--text-xl);
  font-weight: 600;
  line-height: 46px;
  text-align: center;
  color: var(--bg);
  background: var(--ink);
}

.done__again {
  width: 100%;
  height: 42px;
  margin-top: 9px;
  border-radius: 12px;

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink2);
}
</style>
