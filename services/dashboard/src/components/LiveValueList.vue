<script setup lang="ts">
import { computed } from 'vue'
import type { LivePoint } from '@/api/types'

const props = defineProps<{
  live: Record<string, LivePoint>
}>()

const points = computed<LivePoint[]>(() =>
  Object.values(props.live).sort((a, b) => {
    const at = a.type
    const bt = b.type
    if (at === 'numeric' && bt !== 'numeric') return -1
    if (bt === 'numeric' && at !== 'numeric') return 1
    return a.field.localeCompare(b.field)
  }),
)

function format(point: LivePoint): string {
  if (point.type !== 'numeric') return String(point.value)
  const { value } = point
  if (Math.abs(value) >= 1000) return value.toFixed(0)
  if (Math.abs(value) >= 10) return value.toFixed(1)
  return value.toFixed(2)
}
</script>

<template>
  <div class="live-values">
    <span v-if="points.length === 0" class="live-values__empty">No live values yet</span>
    <div v-for="point in points" :key="point.field" class="live-values__item">
      <span class="live-values__field">{{ point.field }}</span>
      <span class="live-values__value">{{ format(point) }}</span>
      <span
        v-if="point.quality > 0"
        class="live-values__quality"
        :title="`Quality ${point.quality}`"
      />
      <span v-if="point.type !== 'numeric'" class="live-values__type">{{ point.type }}</span>
    </div>
  </div>
</template>

<style scoped>
.live-values {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.live-values__empty {
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
}
.live-values__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.live-values__field {
  flex: 0 0 9rem;
  font-weight: 600;
  color: var(--p-text-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.live-values__value {
  font-variant-numeric: tabular-nums;
  color: var(--p-primary-color);
}
.live-values__type {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}
.live-values__quality {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  flex: none;
  background: #22c55e;
}
</style>
