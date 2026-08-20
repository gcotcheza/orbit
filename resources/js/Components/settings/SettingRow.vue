<script setup>
/*
 * One line of a settings card (design/README.md §6). The hairline is drawn by the parent, so
 * the last row of a card has none; the control is a slot, since two of the five are not switches.
 */
defineProps({
  title: { type: String, required: true },

  /** The line underneath. The design has one on every row; it is what turns
   *  "Quiet hours" into a promise the person can check. */
  note: { type: String, default: '' },
})
</script>

<template>
  <div class="row">
    <div v-if="$slots.icon" class="row__icon">
      <slot name="icon"></slot>
    </div>

    <div class="row__text">
      <p class="row__title">{{ title }}</p>
      <p v-if="note" class="row__note">{{ note }}</p>
    </div>

    <slot></slot>
  </div>
</template>

<style scoped>
.row {
  display: flex;
  align-items: center;
  gap: 13px;

  padding: 15px 16px;
}

.row__icon {
  display: flex;
  align-items: center;
  justify-content: center;

  width: 36px;
  height: 36px;
  border-radius: 10px;
  flex-shrink: 0;
}

.row__text {
  flex: 1;
  min-width: 0;
}

.row__title {
  font-size: var(--text-xl);
  font-weight: 600;
  color: var(--ink);
}

.row__note {
  font-size: var(--text-md);
  color: var(--muted);
}
</style>
