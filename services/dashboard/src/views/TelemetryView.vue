<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Card from 'primevue/card'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { deviceTelemetry, listDevices } from '@/api/devices'
import {
  buildFieldSeries,
  pickFields,
  rangeWindows,
  resolutionForRange,
  type RangeWindow,
} from '@/utils/chart'
import TimeSeriesChart from '@/components/TimeSeriesChart.vue'
import type { Device, TelemetryPoint } from '@/api/types'

const devices = ref<Device[]>([])
const deviceId = ref('')
const range = ref<RangeWindow>(rangeWindows()[2])
const resolution = ref(resolutionForRange(range.value.hours))
const field = ref('')
const points = ref<TelemetryPoint[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const rangeOptions = rangeWindows()
const resolutionOptions = [
  { label: '1 minute', value: '1m' },
  { label: '1 hour', value: '1h' },
  { label: '1 day', value: '1d' },
]

const fields = computed(() => pickFields(points.value))
const chart = computed(() => buildFieldSeries(points.value, field.value, resolution.value))

async function loadDevices(): Promise<void> {
  try {
    const { items } = await listDevices({ limit: 100 })
    devices.value = items
    if (!deviceId.value && items.length > 0) {
      deviceId.value = items[0].id
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load devices.'
  }
}

async function load(): Promise<void> {
  if (!deviceId.value) return
  loading.value = true
  error.value = null
  try {
    const result = await deviceTelemetry(deviceId.value, {
      from: range.value.from,
      to: range.value.to,
      resolution: resolution.value,
    })
    points.value = result.points
    if (!field.value || !fields.value.includes(field.value)) {
      field.value = fields.value[0] ?? ''
    }
  } catch (e) {
    points.value = []
    field.value = ''
    error.value = e instanceof Error ? e.message : 'Failed to load telemetry.'
  } finally {
    loading.value = false
  }
}

function changeRange(next: RangeWindow): void {
  range.value = next
  resolution.value = resolutionForRange(next.hours)
  void load()
}

watch(deviceId, () => void load())
watch(resolution, () => void load())

onMounted(() => {
  void loadDevices()
})
</script>

<template>
  <section class="telemetry">
    <header class="telemetry__header">
      <div>
        <h1 class="telemetry__title">Telemetry</h1>
        <p class="telemetry__subtitle">Raw downsampled values for a single device.</p>
      </div>
    </header>

    <div class="telemetry__filters">
      <div class="field">
        <label for="device">Device</label>
        <Select
          id="device"
          v-model="deviceId"
          :options="devices"
          option-label="name"
          option-value="id"
          :loading="loading"
          placeholder="Select a device"
        />
      </div>
      <div class="field">
        <label for="range">Range</label>
        <Select
          id="range"
          :model-value="range"
          :options="rangeOptions"
          option-label="label"
          @update:model-value="changeRange"
        />
      </div>
      <div class="field">
        <label for="resolution">Resolution</label>
        <Select
          id="resolution"
          v-model="resolution"
          :options="resolutionOptions"
          option-label="label"
          option-value="value"
        />
      </div>
      <div class="field">
        <label for="field">Field</label>
        <Select
          id="field"
          v-model="field"
          :options="fields"
          placeholder="Select a field"
          :disabled="fields.length === 0"
        />
      </div>
      <Button
        class="telemetry__refresh"
        icon="pi pi-refresh"
        label="Refresh"
        severity="secondary"
        :loading="loading"
        @click="load"
      />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
    <Message v-if="!deviceId" severity="warn" :closable="false">
      No devices found. Create a device and publish some data first.
    </Message>

    <Card>
      <template #title>
        <span v-if="field" class="telemetry__card-title">
          {{ deviceId ? devices.find((d) => d.id === deviceId)?.name ?? deviceId : '' }}
          — {{ field }}
        </span>
        <span v-else>Telemetry</span>
      </template>
      <template #content>
        <div v-if="loading" class="telemetry__loading">Loading…</div>
        <TimeSeriesChart v-else :labels="chart.labels" :series="chart.series" />
      </template>
    </Card>
  </section>
</template>

<style scoped>
.telemetry {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.telemetry__title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}
.telemetry__subtitle {
  margin: 0.25rem 0 0;
  color: var(--p-text-muted-color);
}
.telemetry__filters {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.telemetry__filters .field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 10rem;
}
.telemetry__filters .field label {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}
.telemetry__refresh {
  margin-left: auto;
}
.telemetry__loading {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 22rem;
  color: var(--p-text-muted-color);
}
.telemetry__card-title {
  text-transform: capitalize;
}
</style>
