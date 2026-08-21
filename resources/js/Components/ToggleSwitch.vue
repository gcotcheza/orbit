<script setup>
/*
 * The iOS-style switch: 46×27 track, 21px knob, on = --good, geometry the design's to the
 * pixel. A <button role="switch">, not a checkbox, and it takes an explicit `label`.
 */
defineProps({
  modelValue: { type: Boolean, required: true },

  /** What this switch is for, read out loud. "Email alerts", not "on". */
  label: { type: String, required: true },

  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <button
    type="button"
    role="switch"
    class="switch"
    :class="{ 'switch--on': modelValue }"
    :aria-checked="modelValue"
    :aria-label="label"
    :disabled="disabled"
    @click="emit('update:modelValue', !modelValue)"
  >
    <span class="switch__knob"></span>
  </button>
</template>

<style scoped>
.switch {
  position: relative;
  flex-shrink: 0;

  width: 46px;
  height: 27px;
  border-radius: 14px;

  background: var(--line);
  transition: background 0.2s ease;
}

.switch--on {
  background: var(--good);
}

.switch:disabled {
  opacity: 0.55;
  cursor: progress;
}

.switch__knob {
  position: absolute;
  top: 3px;
  left: 3px;

  width: 21px;
  height: 21px;
  border-radius: 50%;

  /* The knob is white in BOTH themes — a physical object on a coloured track, not a piece of the
     palette. That is what --on-solid is. */
  background: var(--on-solid);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);

  transition: transform 0.2s ease;
}

.switch--on .switch__knob {
  transform: translateX(19px);
}
</style>
