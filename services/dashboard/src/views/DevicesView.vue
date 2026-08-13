<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { mercure } from '@/mercure/client'
import { useDevicesStore } from '@/stores/devices'
import DeviceStatusTag from '@/components/DeviceStatusTag.vue'
import LiveValueList from '@/components/LiveValueList.vue'
import type { LiveEvent } from '@/api/types'

const store = useDevicesStore()
const router = useRouter()

const protocolOptions = [
  { label: 'All protocols', value: '' },
  { label: 'MQTT', value: 'mqtt' },
  { label: 'LoRaWAN', value: 'lorawan' },
  { label: 'Modbus TCP', value: 'modbus' },
  { label: 'HTTP', value: 'http' },
]

function onLive(event: LiveEvent): void {
  if ('points' in event) store.applyLive(event)
}

function openDevice(id: string): void {
  void router.push({ name: 'device-detail', params: { id } })
}

function changePage(page: number): void {
  void store.loadList({ page: page + 1 })
}

function changeProtocol(protocol: string): void {
  void store.loadList({ page: 1, protocol })
}

onMounted(() => {
  void store.loadList()
  mercure.subscribe('/devices/*', onLive)
})
onUnmounted(() => {
  mercure.unsubscribe('/devices/*', onLive)
})
</script>

<template>
  <section class="devices">
    <header class="devices__header">
      <div>
        <h1 class="devices__title">Devices</h1>
        <p class="devices__subtitle">Provisioned devices and their live telemetry.</p>
      </div>
      <Select
        :model-value="store.protocol"
        :options="protocolOptions"
        option-label="label"
        option-value="value"
        placeholder="Filter by protocol"
        @update:model-value="changeProtocol"
      />
    </header>

    <Message v-if="store.error" severity="error" :closable="false">{{ store.error }}</Message>

    <DataTable
      :value="store.rows"
      :loading="store.loading"
      striped-rows
      size="small"
      scrollable
      scroll-height="flex"
    >
      <template #empty>No devices found.</template>
      <Column header="Name" style="min-width: 16rem">
        <template #body="{ data }">
          <button class="devices__name" type="button" @click="openDevice(data.id)">
            {{ data.name }}
          </button>
          <small class="devices__id">{{ data.id }}</small>
        </template>
      </Column>
      <Column field="protocol" header="Protocol" style="width: 8rem">
        <template #body="{ data }">
          <Tag :value="data.protocol" :severity="data.protocol === 'mqtt' ? 'info' : 'warn'" />
        </template>
      </Column>
      <Column header="Live values" style="min-width: 16rem">
        <template #body="{ data }">
          <LiveValueList :live="data.live" />
        </template>
      </Column>
      <Column header="Status" style="width: 10rem">
        <template #body="{ data }">
          <DeviceStatusTag :status="data.status" />
        </template>
      </Column>
      <Column field="enabled" header="Enabled" style="width: 7rem">
        <template #body="{ data }">
          <Tag :value="data.enabled ? 'Yes' : 'No'" :severity="data.enabled ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column header="" style="width: 5rem">
        <template #body="{ data }">
          <Button
            icon="pi pi-angle-right"
            text
            rounded
            aria-label="Open device"
            @click="openDevice(data.id)"
          />
        </template>
      </Column>
    </DataTable>

    <Paginator
      :rows="store.pageSize"
      :total-records="store.total"
      :first="(store.page - 1) * store.pageSize"
      @update:first="changePage"
    />
  </section>
</template>

<style scoped>
.devices {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 1rem;
}
.devices__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.devices__title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}
.devices__subtitle {
  margin: 0.25rem 0 0;
  color: var(--p-text-muted-color);
}
.devices__name {
  background: none;
  border: none;
  padding: 0;
  font: inherit;
  font-weight: 600;
  color: var(--p-primary-color);
  cursor: pointer;
  display: block;
  text-align: left;
}
.devices__id {
  display: block;
  color: var(--p-text-muted-color);
  font-family: var(--font-mono);
  font-size: 0.75rem;
}
</style>
