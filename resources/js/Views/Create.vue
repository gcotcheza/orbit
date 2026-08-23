<script setup>
/*
 * The natural-language rule creator (design/README.md §4). Chips are removable and re-parse the
 * sentence; they never rewrite the text (docs/BUSINESS-LOGIC.md §36).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink } from 'vue-router'
import DealRules from '@/Components/rules/DealRules.vue'
import MatchBanner from '@/Components/rules/MatchBanner.vue'
import RuleChip from '@/Components/rules/RuleChip.vue'
import { useLayout } from '@/lib/layout'
import { useRulesStore } from '@/stores/rules'

/* The design's own onboarding example — kept here, not in the store,
   because it is copy that belongs with the screen that shows it. */
const SEED = 'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80'

/** design/README.md §4 — long enough to read while typing, short enough to feel live. */
const DEBOUNCE_MS = 500

/* The head is the master pane's, and both states share that pane — so the copy of both lives here
   rather than inside two branches of the template (docs/DESKTOP-LAYOUT-PLAN.md phase 3). */
const HEADS = {
  writing: { title: 'New deal rule', note: "Describe the trip you're dreaming of." },
  created: { title: 'Rule created', note: 'Orbit is watching for it from now on.' },
}

// 1024px and up the rules list is the master pane and the compose card is the detail; below it,
// the phone's single column centred in what the rail leaves.
const { isDesktop } = useLayout()

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

const head = computed(() => (created.value ? HEADS.created : HEADS.writing))

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
  <div class="screen" :class="{ 'screen--wide': isDesktop }">
    <div class="screen__master">
      <header class="screen__head">
        <h1 class="screen__title">{{ head.title }}</h1>
        <p class="screen__note">{{ head.note }}</p>
      </header>

      <!-- The rules this screen adds to, beside the box that writes them. Not on a phone: the
           watch list is where they live there, and this screen is one column deep. -->
      <DealRules v-if="isDesktop" :new-rule="false" compact />
    </div>

    <div class="screen__pane">
      <div class="screen__col">
        <template v-if="created">
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
.screen__col {
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

/* --- 1024px and up: the rules as the master, the compose card as the pane -----
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

  /*
   * A sentence somebody is writing is prose, and prose does not want an 800px line. The column is
   * capped and left-aligned rather than centred, so it stays anchored to the master beside it.
   */
  .screen--wide .screen__col {
    display: block;
    max-width: 680px;
  }

  .screen--wide .compose,
  .screen--wide .done {
    margin-top: 0;
  }

  /* Room to write in, which is what the pane bought: three rows is a phone's compromise. */
  .screen--wide .compose__input {
    min-height: 120px;
  }

  .screen--wide .cta {
    margin-bottom: 0;
  }
}
</style>
