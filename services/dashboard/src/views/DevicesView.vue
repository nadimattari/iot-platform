<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import { createDevice } from '@/api/devices'
import { listGroups } from '@/api/groups'
import { mercure } from '@/mercure/client'
import { useDevicesStore } from '@/stores/devices'
import DeviceStatusTag from '@/components/DeviceStatusTag.vue'
import LiveValueList from '@/components/LiveValueList.vue'
import type { DeviceGroup, LiveEvent } from '@/api/types'

const store = useDevicesStore()
const router = useRouter()

const protocolOptions = [
  { label: 'All protocols', value: '' },
  { label: 'MQTT', value: 'mqtt' },
  { label: 'LoRaWAN', value: 'lorawan' },
  { label: 'Modbus TCP', value: 'modbus' },
  { label: 'HTTP', value: 'http' },
]

const createOptions = protocolOptions.slice(1)

const groups = ref<DeviceGroup[]>([])
const groupOptions = computed(() => groups.value.map((group) => ({ label: group.name, value: group.id })))

const showCreate = ref(false)
const createName = ref('')
const createProtocol = ref('mqtt')
const createGroup = ref('')
const createMetadata = ref('{}')
const creating = ref(false)
const createError = ref<string | null>(null)

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

function openCreate(): void {
  createName.value = ''
  createProtocol.value = 'mqtt'
  createGroup.value = ''
  createMetadata.value = '{}'
  createError.value = null
  showCreate.value = true
}

async function submitCreate(): Promise<void> {
  createError.value = null
  if (!createName.value.trim()) {
    createError.value = 'Name is required.'
    return
  }
  let metadata: Record<string, unknown> = {}
  if (createMetadata.value.trim()) {
    try {
      const parsed = JSON.parse(createMetadata.value) as unknown
      if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
        createError.value = 'Metadata must be a JSON object.'
        return
      }
      metadata = parsed as Record<string, unknown>
    } catch {
      createError.value = 'Metadata must be valid JSON.'
      return
    }
  }
  creating.value = true
  try {
    const { device, api_key } = await createDevice({
      name: createName.value.trim(),
      protocol: createProtocol.value,
      group_id: createGroup.value || undefined,
      metadata,
    })
    showCreate.value = false
    if (api_key && device.protocol !== 'lorawan') {
      alert(`Device created.\n\nAPI key (shown once): ${api_key}`)
    }
    await store.loadList({ page: 1 })
  } catch (error) {
    createError.value = error instanceof Error ? error.message : 'Failed to create device.'
  } finally {
    creating.value = false
  }
}

onMounted(() => {
  void store.loadList()
  void listGroups().then(({ items }) => (groups.value = items)).catch(() => undefined)
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
      <div class="devices__actions">
        <Select
          :model-value="store.protocol"
          :options="protocolOptions"
          option-label="label"
          option-value="value"
          placeholder="Filter by protocol"
          @update:model-value="changeProtocol"
        />
        <Button label="New device" icon="pi pi-plus" @click="openCreate" />
      </div>
    </header>

    <Dialog v-model:visible="showCreate" header="New device" modal :closable="false">
      <form class="devices__create" @submit.prevent="submitCreate">
        <div class="field">
          <label for="create-name">Name</label>
          <InputText id="create-name" v-model="createName" placeholder="e.g. cooling-pump-2" />
        </div>
        <div class="field">
          <label for="create-protocol">Protocol</label>
          <Select
            id="create-protocol"
            v-model="createProtocol"
            :options="createOptions"
            option-label="label"
            option-value="value"
          />
        </div>
        <div class="field">
          <label for="create-group">Group</label>
          <Select
            id="create-group"
            v-model="createGroup"
            :options="groupOptions"
            option-label="label"
            option-value="value"
            placeholder="No group"
            :show-clear="true"
          />
        </div>
        <div class="field">
          <label for="create-metadata">Metadata (optional JSON)</label>
          <Textarea id="create-metadata" v-model="createMetadata" rows="4" class="mono" />
        </div>
        <Message v-if="createError" severity="error" :closable="false">{{ createError }}</Message>
        <div class="devices__create-actions">
          <Button type="button" label="Cancel" severity="secondary" @click="showCreate = false" />
          <Button type="submit" :loading="creating" label="Create" icon="pi pi-check" />
        </div>
      </form>
    </Dialog>

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
.devices__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.devices__create {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.devices__create .field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.devices__create .field label {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}
.devices__create-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
.mono {
  font-family: var(--font-mono);
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
