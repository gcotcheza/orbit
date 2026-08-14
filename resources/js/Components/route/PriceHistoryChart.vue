<script setup>
/*
 * "Price, last 60 days" (design/README.md §2) — hand-drawn SVG, no chart
 * library. The whole picture is one line, one area, one dashed reference and a
 * dot, and a dependency for that would be more code than this file.
 *
 * PLOTTED BY DATE, NOT BY INDEX. `history` holds up to 60 daily OBSERVATIONS —
 * the days we looked, not the days you fly — and the days we did not poll are
 * simply absent (docs/API.md). Spacing the points evenly would draw a
 * four-day gap as one day and quietly flatten every trend across an outage.
 * The x axis is real elapsed days.
 *
 * THE REFERENCE LINE IS INSIDE THE Y RANGE ON PURPOSE. `median` joins the
 * values the scale is fitted to, so "usual €95" cannot end up drawn off the
 * top of a card whose fares are all €44. When there are no statistics it is
 * null and no line is drawn — the design's instruction is to draw the chart
 * without a reference rather than with one at zero.
 */
import { computed, useId } from 'vue'
import { euro } from './format'

const props = defineProps({
  history: { type: Array, default: () => [] },
  median: { type: Number, default: null },
  tone: { type: String, default: 'normal' },
  trackingDays: { type: Number, default: 0 },
})

// The design's canvas. The plot area is inset top and bottom so the line's
// 2 px stroke and the end dot's radius are never clipped by the viewBox.
const WIDTH = 300
const HEIGHT = 140
const PAD_TOP = 16
const PAD_BOTTOM = 22
const BASE = HEIGHT - PAD_BOTTOM
const PLOT = HEIGHT - PAD_TOP - PAD_BOTTOM

// The design's threshold for "we have not been watching this long enough to
// draw conclusions from" (docs/PLAN.md).
const HONEST_AFTER_DAYS = 14

const gradientId = useId()

/**
 * `YYYY-MM-DD` → a day number, for measuring gaps.
 *
 * UTC parts rather than `new Date(iso)`, which resolves through the viewer's
 * timezone and would shift every observation a day for anyone west of London.
 */
function dayNumber(iso) {
  const [year, month, day] = iso.split('-').map(Number)

  return Date.UTC(year, month - 1, day) / 86400000
}

const chart = computed(() => {
  const points = props.history

  // One point is a dot, not a trend, and zero points are not a chart. Both
  // cases are covered by the tracking note below, which is the honest thing to
  // show in their place.
  if (points.length < 2) {
    return null
  }

  const firstDay = dayNumber(points[0].date)
  const daySpan = dayNumber(points[points.length - 1].date) - firstDay || 1

  const values = points.map((point) => point.price)

  if (props.median !== null) {
    values.push(props.median)
  }

  const low = Math.min(...values)
  const range = Math.max(...values) - low

  const x = (iso) => ((dayNumber(iso) - firstDay) / daySpan) * WIDTH
  // A flat month has no range to scale against; it is drawn down the middle
  // rather than along the floor, which is what dividing by a fallback of 1
  // would give.
  const y = (value) => (range === 0 ? PAD_TOP + PLOT / 2 : BASE - ((value - low) / range) * PLOT)

  const line = points
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(point.date).toFixed(1)} ${y(point.price).toFixed(1)}`)
    .join(' ')

  const last = points[points.length - 1]

  return {
    line,
    area: `${line} L ${WIDTH} ${BASE} L 0 ${BASE} Z`,
    dotX: x(last.date).toFixed(1),
    dotY: y(last.price).toFixed(1),
    medianY: props.median === null ? null : y(props.median).toFixed(1),
  }
})

const note = computed(() => {
  if (props.trackingDays >= HONEST_AFTER_DAYS) {
    return null
  }

  const days = props.trackingDays === 1 ? '1 day' : `${props.trackingDays} days`

  return `Tracking ${days} — this route's history is still filling in.`
})
</script>

<template>
  <div class="chart-card" :class="`chart-card--${tone}`">
    <div class="chart-card__head">
      <p class="chart-card__title">Price, last 60 days</p>

      <p v-if="median !== null" class="chart-card__usual">
        <span class="chart-card__dash" aria-hidden="true"></span>usual {{ euro(median) }}
      </p>
    </div>

    <svg v-if="chart" class="chart" viewBox="0 0 300 140" preserveAspectRatio="none" role="img" aria-label="Price history">
      <defs>
        <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
          <stop class="chart__stop" offset="0" stop-opacity=".22" />
          <stop class="chart__stop" offset="1" stop-opacity="0" />
        </linearGradient>
      </defs>

      <line v-if="chart.medianY !== null" class="chart__usual" x1="0" :y1="chart.medianY" x2="300" :y2="chart.medianY" />

      <path class="chart__area" :d="chart.area" :fill="`url(#${gradientId})`" />
      <path class="chart__line" :d="chart.line" pathLength="1" />
      <circle class="chart__dot" :cx="chart.dotX" :cy="chart.dotY" r="4.5" />
    </svg>

    <p v-if="note" class="chart-card__note">{{ note }}</p>
  </div>
</template>

<style scoped>
/* One tone in, four colours out. The card sets the custom property and every
   painted part below reads it, so switching a route from "falling" to "wait"
   is one class on one element. */
.chart-card--good {
  --tone: var(--good);
}

.chart-card--info {
  --tone: var(--info);
}

.chart-card--warn {
  --tone: var(--warn);
}

.chart-card--normal {
  --tone: var(--muted);
}

.chart-card {
  margin-top: 18px;
  padding: 16px 14px 12px;
  border-radius: 20px;

  background: var(--card);
  border: 1px solid var(--line);
  box-shadow: var(--shadow);
}

.chart-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
  padding: 0 4px;
}

.chart-card__title {
  font-size: var(--text-md);
  font-weight: 600;
  color: var(--ink2);
}

.chart-card__usual {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: var(--text-sm);
  color: var(--muted);
}

.chart-card__dash {
  display: inline-block;
  width: 14px;
  border-top: 1.5px dashed var(--muted);
}

.chart-card__note {
  padding: 0 4px;
  font-size: var(--text-md);
  color: var(--muted);
}

/* Stretched to the card's width: the shape of the trend is what this chart is
   for, and 60 points at a fixed aspect ratio would letterbox it on a phone. */
.chart {
  display: block;
  width: 100%;
  height: 150px;
}

.chart__stop {
  stop-color: var(--tone);
}

.chart__usual {
  stroke: var(--muted);
  stroke-width: 1;
  stroke-dasharray: 4 4;
  opacity: 0.55;
}

.chart__area {
  animation: chart-area 1.1s ease both 0.3s;
}

/* `pathLength="1"` on the path normalises its length, so one dash of 1 covers
   the whole line whatever its real geometry and the draw-on is a single
   offset from 1 to 0 — no measuring in JavaScript. */
.chart__line {
  fill: none;
  stroke: var(--tone);
  stroke-width: 2.4;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-dasharray: 1;
  animation: chart-draw 1.2s ease both;
}

.chart__dot {
  fill: var(--card);
  stroke: var(--tone);
  stroke-width: 2.5;

  /* An SVG element's transform origin is the user-space ORIGIN by default, so
     without these two the pop below scales the dot out of the top-left corner
     of the chart instead of out of itself. */
  transform-box: fill-box;
  transform-origin: center;
  animation: chart-pop 0.4s ease both 1.1s;
}

@keyframes chart-draw {
  from {
    stroke-dashoffset: 1;
  }

  to {
    stroke-dashoffset: 0;
  }
}

@keyframes chart-area {
  from {
    opacity: 0;
  }

  to {
    opacity: 1;
  }
}

@keyframes chart-pop {
  from {
    opacity: 0;
    transform: scale(0.4);
  }

  to {
    opacity: 1;
    transform: none;
  }
}
</style>
