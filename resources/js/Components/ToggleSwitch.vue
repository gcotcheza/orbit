<script setup>
/*
 * The iOS-style switch: 46×27 track, 21 px knob, on = --good.
 *
 * design/README.md §5 and §6 draw the SAME switch on the watchlist rows and on
 * every alerts row, so it is one component. Its geometry is the design's, to
 * the pixel — the knob's 3 px inset and 22 px travel are what make the two
 * ends look symmetrical, and they stop being symmetrical the moment somebody
 * rounds the track to 48.
 *
 * IT LIVES IN `Components/` RATHER THAN IN A SCREEN'S FOLDER because three
 * unrelated screens draw it — alerts, the watchlist's boarding passes and the
 * deal rules. It was written under `Components/settings/` while those screens
 * were being built in parallel branches, and moved up here on the DRY pass.
 *
 * A <button role="switch">, NOT a checkbox. It carries no label of its own —
 * the row beside it is the label — so it takes an explicit `label` for
 * assistive technology, and `aria-checked` is what announces the state. A
 * styled checkbox would give the same pixels and would also give a focus
 * outline that lands on an invisible input.
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

  /* The knob is white in BOTH themes — it is a physical object on a coloured
     track, not a piece of the palette, and a knob that turned dark in the dark
     theme would read as the switch being off. That is what --on-solid is. */
  background: var(--on-solid);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);

  transition: transform 0.2s ease;
}

.switch--on .switch__knob {
  transform: translateX(19px);
}
</style>
