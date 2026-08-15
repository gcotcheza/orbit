<script setup>
/*
 * One saved rule on the watch screen (design/README.md §5's rules section).
 *
 * DELIBERATELY QUIET. The boarding passes above it are the stars of that
 * screen — they are the routes the owner actually chose — and a rule is a
 * standing question rather than a thing being watched. So: a hairline border
 * instead of a card, no shadow, the chips as one muted line rather than the
 * create screen's removable pills, and the matches only when asked for.
 *
 * THE CHIPS ARE READ-ONLY HERE. Editing a rule means going back to the create
 * screen where the sentence is; a × on this row would be an edit with no
 * textarea to explain what it did.
 *
 * REMOVE CONFIRMS INLINE rather than in a dialog. A rule is cheap to lose and
 * cheap to retype, so a modal would cost more attention than the mistake does
 * — but it is still one tap away from gone, and the second tap is a different
 * word in a different colour.
 */
import { computed, ref } from 'vue'
import ToggleSwitch from '@/Components/ToggleSwitch.vue'

const props = defineProps({
  /** One element of `GET /api/rules`'s `data`. */
  rule: { type: Object, required: true },

  /** True while a write for this row is in flight. */
  busy: { type: Boolean, default: false },

  /**
   * The code of the match currently being added to the watchlist, if any.
   *
   * THE PARENT'S STATE AND NOT THIS ROW'S, because the parent owns the
   * request. A local flag here would have to be cleared on an event this
   * component never sees, and the honest version of that is a prop.
   */
  watching: { type: String, default: '' },
})

const emit = defineEmits(['toggle', 'remove', 'watch'])

const open = ref(false)
const confirming = ref(false)

/** "From AMS · Max €80 · Fridays" — the chips as a sentence. */
const summary = computed(() => props.rule.chips.map((chip) => chip.label).join(' · '))

const matchLine = computed(() => {
  const { count, cheapest } = props.rule.matches

  if (count === 0) {
    return 'No trips yet'
  }

  return `${count} ${count === 1 ? 'trip' : 'trips'} · from €${cheapest}`
})

function toggleOpen() {
  open.value = !open.value
  confirming.value = false
}
</script>

<template>
  <div class="rule" :class="{ 'rule--paused': !rule.active }">
    <div class="rule__head">
      <button
        type="button"
        class="rule__open"
        :aria-expanded="open"
        @click="toggleOpen"
      >
        <span class="rule__summary">{{ summary }}</span>
        <span class="rule__matches">
          {{ matchLine }}
          <svg
            width="10"
            height="10"
            viewBox="0 0 10 10"
            fill="none"
            class="rule__chevron"
            :class="{ 'rule__chevron--open': open }"
            aria-hidden="true"
          >
            <path d="M2.5 3.5L5 6l2.5-2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
          </svg>
        </span>
      </button>

      <ToggleSwitch
        :model-value="rule.active"
        :disabled="busy"
        :label="`Alerts for ${summary}`"
        @update:model-value="emit('toggle', $event)"
      />
    </div>

    <div v-if="open" class="rule__body">
      <p class="rule__text">“{{ rule.text }}”</p>

      <ul v-if="rule.matches.sample.length > 0" class="rule__list">
        <li v-for="match in rule.matches.sample" :key="match.code" class="match">
          <span class="match__where">
            <span class="match__code">{{ match.code }}</span>
            <span class="match__city">{{ match.destination.city }} · {{ match.cheapest.date }}</span>
          </span>

          <span class="match__price">€{{ match.cheapest.price }}</span>

          <span v-if="match.watched" class="match__watched">Watching</span>
          <button
            v-else
            type="button"
            class="match__watch"
            :disabled="watching === match.code"
            @click="emit('watch', match)"
          >
            Watch
          </button>
        </li>
      </ul>

      <p v-else class="rule__none">
        Nothing matches this rule today. Orbit re-prices the routes it is about every morning.
      </p>

      <div class="rule__actions">
        <button v-if="!confirming" type="button" class="rule__remove" @click="confirming = true">
          Remove rule
        </button>

        <template v-else>
          <button type="button" class="rule__cancel" @click="confirming = false">Keep it</button>
          <button type="button" class="rule__confirm" @click="emit('remove')">Remove</button>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rule {
  border: 1px solid var(--line);
  border-radius: 14px;
  background: var(--card);
}

/* Paused rules stay on the list, dimmed — the switch that brings one back is
   on the row it turned off. */
.rule--paused {
  opacity: 0.58;
}

.rule__head {
  display: flex;
  align-items: center;
  gap: 12px;

  padding: 11px 13px;
}

.rule__open {
  flex: 1;
  min-width: 0;
  text-align: left;
}

.rule__summary {
  display: block;
  overflow: hidden;

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
  text-overflow: ellipsis;
}

.rule__matches {
  display: flex;
  align-items: center;
  gap: 5px;

  margin-top: 3px;

  font-size: var(--text-sm);
  color: var(--muted);
}

.rule__chevron {
  transition: transform 0.2s ease;
}

.rule__chevron--open {
  transform: rotate(180deg);
}

.rule__body {
  padding: 0 13px 13px;
  border-top: 1px solid var(--line2);
}

.rule__text {
  margin-top: 11px;

  font-size: var(--text-md);
  font-style: italic;
  color: var(--muted);
}

.rule__list {
  display: flex;
  flex-direction: column;
  gap: 2px;

  margin-top: 10px;
}

.match {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 7px 0;
}

.match__where {
  flex: 1;
  min-width: 0;
}

.match__code {
  display: block;

  font-family: var(--font-display);
  font-size: var(--text-md);
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--ink2);
}

.match__city {
  display: block;
  overflow: hidden;

  font-size: var(--text-sm);
  color: var(--muted);
  white-space: nowrap;
  text-overflow: ellipsis;
}

.match__price {
  font-family: var(--font-display);
  font-size: var(--text-xl);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.match__watch {
  padding: 6px 11px;
  border-radius: var(--radius-pill);

  font-size: var(--text-sm);
  font-weight: 700;
  color: var(--on-solid);
  background: var(--accent);
}

.match__watch:disabled {
  opacity: 0.5;
  cursor: progress;
}

/* Already on the watchlist: said, not offered. */
.match__watched {
  padding: 6px 11px;

  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--good-ink);
}

.rule__none {
  margin-top: 10px;

  font-size: var(--text-md);
  color: var(--muted);
}

.rule__actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;

  margin-top: 12px;
}

.rule__remove,
.rule__cancel,
.rule__confirm {
  padding: 6px 11px;
  border-radius: var(--radius-chip);

  font-size: var(--text-md);
  font-weight: 600;
}

.rule__remove {
  color: var(--muted);
}

.rule__cancel {
  color: var(--ink2);
  background: var(--card2);
}

.rule__confirm {
  color: var(--warn-ink);
  background: var(--warn-bg);
}
</style>
