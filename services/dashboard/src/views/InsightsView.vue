<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Card from 'primevue/card'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { listDevices } from '@/api/devices'
import { listGroups } from '@/api/groups'
import { insightsSummary, insightsTimeseries } from '@/api/insights'
import { buildMultiFieldSeries, rangeWindows, type RangeWindow } from '@/utils/chart'
import TimeSeriesChart from '@/components/TimeSeriesChart.vue'
import type { Device, DeviceGroup, InsightsField, TelemetryPoint } from '@/api/types'

const devices = ref<Device[]>([])
const groups = ref<DeviceGroup[]>([])
const groupId = ref('')
const summaryRange = ref<RangeWindow>(rangeWindows()[4])
const summaryFields = ref<InsightsField[]>([])
const summaryLoading = ref(false)
const summaryError = ref<string | null>(null)

const timeseriesDeviceId = ref('')
const bucket = ref('1h')
const seriesRange = ref<RangeWindow>(rangeWindows()[2])
const seriesPoints = ref<TelemetryPoint[]>([])
const seriesLoading = ref(false)
const seriesError = ref<string | null>(null)

const bucketOptions = [
  { label: '1 minute', value: '1m' },
  { label: '1 hour', value: '1h' },
  { label: '1 day', value: '1d' },
]

const groupOptions = computed(() =>
  groups.value.map((group) => ({ label: `${group.name} (${group.device_count})`, value: group.id })),
)

const seriesChart = computed(() => buildMultiFieldSeries(seriesPoints.value, bucket.value))

async function loadDevices(): Promise<void> {
  try {
    const [{ items: deviceItems }, { items: groupItems }] = await Promise.all([
      listDevices({ limit: 100 }),
      listGroups(),
    ])
    devices.value = deviceItems
    groups.value = groupItems
    if (!timeseriesDeviceId.value && deviceItems.length > 0) {
      timeseriesDeviceId.value = deviceItems[0].id
    }
    if (!groupId.value && groupOptions.value.length > 0) {
      groupId.value = groupOptions.value[0].value
    }
  } catch (e) {
    summaryError.value = e instanceof Error ? e.message : 'Failed to load devices.'
  }
}

async function loadSummary(): Promise<void> {
  if (!groupId.value) return
  summaryLoading.value = true
  summaryError.value = null
  try {
    const result = await insightsSummary(groupId.value, {
      from: summaryRange.value.from,
      to: summaryRange.value.to,
    })
    summaryFields.value = result.fields
  } catch (e) {
    summaryFields.value = []
    summaryError.value = e instanceof Error ? e.message : 'Failed to load summary.'
  } finally {
    summaryLoading.value = false
  }
}

async function loadSeries(): Promise<void> {
  if (!timeseriesDeviceId.value) return
  seriesLoading.value = true
  seriesError.value = null
  try {
    const result = await insightsTimeseries(timeseriesDeviceId.value, {
      bucket: bucket.value,
      from: seriesRange.value.from,
      to: seriesRange.value.to,
    })
    seriesPoints.value = result.items
  } catch (e) {
    seriesPoints.value = []
    seriesError.value = e instanceof Error ? e.message : 'Failed to load timeseries.'
  } finally {
    seriesLoading.value = false
  }
}

watch(groupId, () => void loadSummary())
watch(summaryRange, () => void loadSummary())
watch(timeseriesDeviceId, () => void loadSeries())
watch(bucket, () => void loadSeries())
watch(seriesRange, () => void loadSeries())

function changeSummaryRange(next: RangeWindow): void {
  summaryRange.value = next
}

function changeSeriesRange(next: RangeWindow): void {
  seriesRange.value = next
}

onMounted(() => {
  void loadDevices()
})
</script>

<template>
  <section class="insights">
    <header class="insights__header">
      <div>
        <h1 class="insights__title">Insights</h1>
        <p class="insights__subtitle">Aggregated summaries from continuous aggregates.</p>
      </div>
    </header>

    <Card>
      <template #title>Group summary</template>
      <template #content>
        <div class="insights__filters">
          <div class="field">
            <label for="group">Group</label>
            <Select
              id="group"
              v-model="groupId"
              :options="groupOptions"
              option-label="label"
              option-value="value"
              placeholder="Select a group"
            />
          </div>
          <div class="field">
            <label for="summary-range">Range</label>
            <Select
              id="summary-range"
              :model-value="summaryRange"
              :options="rangeWindows()"
              option-label="label"
              @update:model-value="changeSummaryRange"
            />
          </div>
        </div>

        <Message v-if="summaryError" severity="error" :closable="false">{{ summaryError }}</Message>
        <Message v-if="!groupId" severity="warn" :closable="false">
          No device groups found. Assign a group to a device to see summaries.
        </Message>

        <div v-if="summaryLoading" class="insights__loading">Loading…</div>
        <div v-else-if="summaryFields.length" class="insights__cards">
          <div v-for="fieldSummary in summaryFields" :key="fieldSummary.field" class="insights__card">
            <h3 class="insights__card-field">{{ fieldSummary.field }}</h3>
            <dl class="insights__card-metrics">
              <div>
                <dt>avg</dt>
                <dd>{{ fieldSummary.avg.toFixed(2) }}</dd>
              </div>
              <div>
                <dt>min</dt>
                <dd>{{ fieldSummary.min.toFixed(2) }}</dd>
              </div>
              <div>
                <dt>max</dt>
                <dd>{{ fieldSummary.max.toFixed(2) }}</dd>
              </div>
              <div>
                <dt>points</dt>
                <dd>{{ fieldSummary.count }}</dd>
              </div>
            </dl>
          </div>
        </div>
        <Message v-else-if="!summaryLoading && groupId" severity="secondary" :closable="false">
          No aggregated data for this group and range yet.
        </Message>
      </template>
    </Card>

    <Card>
      <template #title>Timeseries</template>
      <template #content>
        <div class="insights__filters">
          <div class="field">
            <label for="series-device">Device</label>
            <Select
              id="series-device"
              v-model="timeseriesDeviceId"
              :options="devices"
              option-label="name"
              option-value="id"
              placeholder="Select a device"
            />
          </div>
          <div class="field">
            <label for="bucket">Bucket</label>
            <Select
              id="bucket"
              v-model="bucket"
              :options="bucketOptions"
              option-label="label"
              option-value="value"
            />
          </div>
          <div class="field">
            <label for="series-range">Range</label>
            <Select
              id="series-range"
              :model-value="seriesRange"
              :options="rangeWindows()"
              option-label="label"
              @update:model-value="changeSeriesRange"
            />
          </div>
        </div>

        <Message v-if="seriesError" severity="error" :closable="false">{{ seriesError }}</Message>
        <Message v-if="!timeseriesDeviceId" severity="warn" :closable="false">
          No devices found to chart.
        </Message>

        <div v-if="seriesLoading" class="insights__loading">Loading…</div>
        <TimeSeriesChart
          v-else
          :labels="seriesChart.labels"
          :series="seriesChart.series"
          :height="'20rem'"
        />
      </template>
    </Card>
  </section>
</template>

<style scoped>
.insights {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.insights__title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}
.insights__subtitle {
  margin: 0.25rem 0 0;
  color: var(--p-text-muted-color);
}
.insights__filters {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.insights__filters .field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 10rem;
}
.insights__filters .field label {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}
.insights__loading {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 12rem;
  color: var(--p-text-muted-color);
}
.insights__cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
  gap: 1rem;
}
.insights__card {
  border: 1px solid var(--p-surface-200);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
}
.insights__card-field {
  margin: 0 0 0.5rem;
  font-size: 1rem;
  font-weight: 700;
  text-transform: capitalize;
}
.insights__card-metrics {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.35rem 1rem;
  margin: 0;
}
.insights__card-metrics div {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
}
.insights__card-metrics dt {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}
.insights__card-metrics dd {
  margin: 0;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
</style>
