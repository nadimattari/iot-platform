<script setup lang="ts">
import { computed } from 'vue'
import Chart from 'primevue/chart'
import type { ChartSeries } from '@/utils/chart'

const props = withDefaults(
  defineProps<{
    labels: string[]
    series: ChartSeries[]
    height?: string
  }>(),
  { height: '22rem' },
)

const PALETTE = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#ec4899']

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.series.map((series, index) => {
    const color = PALETTE[index % PALETTE.length]
    return {
      label: series.label,
      data: series.data,
      borderColor: color,
      backgroundColor: `${color}22`,
      fill: series.label === 'avg' ? false : false,
      tension: 0.3,
      borderWidth: 2,
      pointRadius: 0,
      spanGaps: false,
    }
  }),
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index' as const, intersect: false },
  plugins: {
    legend: { position: 'top' as const, labels: { boxWidth: 12 } },
    tooltip: { mode: 'index' as const, intersect: false },
  },
  scales: {
    x: {
      ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
      grid: { display: false },
    },
    y: { beginAtZero: false },
  },
}))
</script>

<template>
  <div v-if="labels.length && series.length" class="chart" :style="{ height }">
    <Chart type="line" :data="chartData" :options="chartOptions" />
  </div>
  <div v-else class="chart__empty">
    <span class="pi pi-chart-line" aria-hidden="true" />
    <p>No data for the selected range.</p>
  </div>
</template>

<style scoped>
.chart {
  position: relative;
  width: 100%;
}
.chart__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  height: 12rem;
  color: var(--p-text-muted-color);
}
.chart__empty .pi {
  font-size: 1.5rem;
}
.chart__empty p {
  margin: 0;
}
</style>
