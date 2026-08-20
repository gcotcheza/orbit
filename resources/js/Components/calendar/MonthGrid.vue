<script setup>
/*
 * The seven-column month (design/README.md §3). THE GRID IS BUILT FROM THE CALENDAR, NOT FROM
 * THE RESPONSE. A day with a fare is a button; a day without one is a div, not a disabled one.
 */
import { computed } from 'vue'
import { euro } from '@/lib/format'
import { HEAT_INK, heatColour } from './heat'
import { WEEKDAYS, buildMonthGrid, dayLabel } from './month'

const props = defineProps({
  month: { type: String, required: true },
  days: { type: Array, default: () => [] },
  // Null for a month with no fares at all — every cell is then neutral, so there is nothing to
  // interpolate across.
  min: { type: Number, default: null },
  max: { type: Number, default: null },
})

defineEmits(['pick'])

const cells = computed(() => buildMonthGrid(props.month, props.days))

function cellStyle(fare) {
  if (fare === null || props.min === null || props.max === null) {
    return null
  }

  return {
    background: heatColour(fare.price, props.min, props.max),
    color: HEAT_INK,
  }
}

function cellLabel(cell) {
  return `${dayLabel(cell.date)}, ${euro(cell.fare.price)}`
}
</script>

<template>
  <div class="grid-card">
    <div class="grid-card__weekdays">
      <span v-for="weekday in WEEKDAYS" :key="weekday">{{ weekday }}</span>
    </div>

    <div class="grid-card__grid">
      <template v-for="cell in cells" :key="cell.key">
        <div v-if="cell.blank" class="cell cell--blank"></div>

        <button v-else-if="cell.fare" class="cell cell--fare tabular" :style="cellStyle(cell.fare)" :aria-label="cellLabel(cell)" @click="$emit('pick', cell.fare)">
          <span class="cell__day">{{ cell.day }}</span>
          <span class="cell__price">{{ euro(cell.fare.price) }}</span>
        </button>

        <div v-else class="cell cell--empty tabular">
          <span class="cell__day">{{ cell.day }}</span>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.grid-card {
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  padding: 16px 14px 18px;
  box-shadow: var(--shadow);
}

.grid-card__weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  padding: 0 2px 9px;

  font-family: var(--font-display);
  font-size: var(--text-sm);
  font-weight: 600;
  text-align: center;
  color: var(--muted);
}

.grid-card__grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 5px;
}

.cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1px;

  aspect-ratio: 1;
  border-radius: var(--radius-cell);
}

/* The days before the 1st. They hold a column open and nothing else. */
.cell--blank {
  visibility: hidden;
}

/* A day inside the month with no fare: present, dated and visibly not a price. A hole in the month
   reads as a rendering fault. */
.cell--empty {
  background: var(--card2);
  color: var(--muted);
}

.cell--fare {
  cursor: pointer;
  transition: transform 0.15s ease;
}

.cell--fare:active {
  transform: scale(0.94);
}

.cell__day {
  font-size: 10px;
  font-weight: 600;
  line-height: 1;
  opacity: 0.7;
}

.cell__price {
  font-family: var(--font-display);
  font-size: 11.5px;
  font-weight: 700;
  line-height: 1;
}
</style>
