<script setup>
/*
 * The pill-in-a-trough control (design/README.md §6), used twice — the options are data, so a
 * two-option and a three-option control are the same code. `role="radiogroup"`, not a select.
 */
defineProps({
  /** The chosen option's `value`. Compared with ===, so keep the types honest. */
  modelValue: { type: [String, Number], required: true },

  /** @type {{ value: string|number, label: string }[]} */
  options: { type: Array, required: true },

  /** What the group is choosing between, read out loud. */
  label: { type: String, required: true },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <div class="segmented" role="radiogroup" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      class="segmented__option"
      :class="{ 'segmented__option--on': option.value === modelValue }"
      :aria-checked="option.value === modelValue"
      @click="emit('update:modelValue', option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped>
.segmented {
  display: flex;
  gap: 4px;

  padding: 4px;
  border-radius: 12px;
  background: var(--card2);
}

.segmented__option {
  flex: 1;

  height: 38px;
  border-radius: 9px;

  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink2);

  transition: background 0.18s ease, color 0.18s ease;
}

/* The chosen one is a raised card sitting IN the trough, per the prototype —
   the same surface as the panel behind the control, lifted by a shadow. */
.segmented__option--on {
  background: var(--card);
  color: var(--ink);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}
</style>
