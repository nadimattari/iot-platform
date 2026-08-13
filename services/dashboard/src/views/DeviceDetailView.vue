<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Card from 'primevue/card'
import Checkbox from 'primevue/checkbox'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import { listCommands, sendCommand } from '@/api/devices'
import { mercure } from '@/mercure/client'
import { useDevicesStore } from '@/stores/devices'
import DeviceStatusTag from '@/components/DeviceStatusTag.vue'
import LiveValueList from '@/components/LiveValueList.vue'
import type { Command, CommandStatus, LiveEvent } from '@/api/types'

const store = useDevicesStore()
const route = useRoute()
const router = useRouter()

const deviceId = computed(() => String(route.params.id ?? ''))

const commandHistory = ref<Command[]>([])
const historyLoading = ref(false)

const sending = ref(false)
const commandError = ref<string | null>(null)
const commandSuccess = ref<string | null>(null)
const payloadJson = ref('{\n  "ack": 1\n}')
const downlinkData = ref('')
const fPort = ref(10)
const confirmed = ref(true)

const devEui = ref('')
const claiming = ref(false)
const claimError = ref<string | null>(null)
const claimSuccess = ref<string | null>(null)

const statusSeverity: Record<CommandStatus, 'warn' | 'info' | 'success' | 'danger'> = {
  pending: 'warn',
  sent: 'info',
  acked: 'success',
  failed: 'danger',
}

async function loadHistory(): Promise<void> {
  if (!deviceId.value) return
  historyLoading.value = true
  try {
    const result = await listCommands({ device_id: deviceId.value, limit: 25 })
    commandHistory.value = result.items
  } finally {
    historyLoading.value = false
  }
}

function upsertCommand(command: Command): void {
  const index = commandHistory.value.findIndex((c) => c.id === command.id)
  if (index >= 0) commandHistory.value[index] = command
  else commandHistory.value.unshift(command)
}

function onCommandEvent(event: LiveEvent): void {
  if ('command' in event) upsertCommand(event.command)
}

function applyLive(event: LiveEvent): void {
  if ('points' in event) store.applyLive(event)
}

async function send(): Promise<void> {
  commandError.value = null
  commandSuccess.value = null
  const detail = store.detail
  if (!detail) return

  let input: {
    type: string
    payload?: unknown
    data?: string
    object?: unknown[]
    confirmed?: boolean
    f_port?: number
  }
  if (detail.protocol === 'mqtt') {
    let parsed: unknown
    try {
      parsed = JSON.parse(payloadJson.value)
    } catch {
      commandError.value = 'Payload must be valid JSON.'
      return
    }
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      commandError.value = 'Payload must be a JSON object of field values.'
      return
    }
    input = { type: 'mqtt_message', payload: parsed }
  } else {
    if (!downlinkData.value.trim() && !payloadJson.value.trim()) {
      commandError.value = 'Provide downlink data (hex) or a JSON object payload.'
      return
    }
    input = {
      type: 'lorawan_downlink',
      f_port: fPort.value ?? undefined,
      confirmed: confirmed.value,
    }
    if (downlinkData.value.trim()) input.data = downlinkData.value.trim()
    else {
      try {
        input.object = JSON.parse(payloadJson.value) as unknown[]
      } catch {
        commandError.value = 'Object payload must be valid JSON.'
        return
      }
    }
  }

  sending.value = true
  try {
    const { command } = await sendCommand(deviceId.value, input)
    upsertCommand(command)
    commandSuccess.value = `Command ${command.id.slice(0, 8)} sent (${command.status}).`
  } catch (error) {
    commandError.value = error instanceof Error ? error.message : 'Failed to send command.'
  } finally {
    sending.value = false
  }
}

function toggling(): void {
  void store.setEnabled(deviceId.value, !store.detail?.enabled)
}

async function claim(): Promise<void> {
  claimError.value = null
  claimSuccess.value = null
  if (!devEui.value.trim()) {
    claimError.value = 'Dev EUI is required.'
    return
  }
  claiming.value = true
  try {
    await store.claim(deviceId.value, { dev_eui: devEui.value.trim() })
    claimSuccess.value = 'Dev EUI saved.'
  } catch (error) {
    claimError.value = error instanceof Error ? error.message : 'Failed to save Dev EUI.'
  } finally {
    claiming.value = false
  }
}

const canCommand = computed(() => {
  const protocol = store.detail?.protocol
  return protocol === 'mqtt' || protocol === 'lorawan'
})

const metadataLines = computed(() => {
  const metadata = store.detail?.metadata ?? {}
  return Object.entries(metadata)
})

onMounted(() => {
  void store.loadDetail(deviceId.value)
  void loadHistory()
  mercure.subscribe('/devices/*', applyLive)
  mercure.subscribe(`/devices/${deviceId.value}/commands`, onCommandEvent)
})

onUnmounted(() => {
  mercure.unsubscribe('/devices/*', applyLive)
  mercure.unsubscribe(`/devices/${deviceId.value}/commands`, onCommandEvent)
})

watch(deviceId, (id) => {
  void store.loadDetail(id)
  void loadHistory()
})
</script>

<template>
  <section v-if="store.detail" class="device-detail">
    <header class="device-detail__header">
      <div class="device-detail__heading">
        <Button
          icon="pi pi-arrow-left"
          text
          rounded
          aria-label="Back to devices"
          @click="router.push({ name: 'devices' })"
        />
        <div>
          <h1 class="device-detail__title">{{ store.detail.name }}</h1>
          <small class="device-detail__id">{{ store.detail.id }}</small>
        </div>
        <Tag :value="store.detail.protocol" :severity="store.detail.protocol === 'mqtt' ? 'info' : 'warn'" />
      </div>
      <div class="device-detail__controls">
        <DeviceStatusTag :status="store.detail.status" />
        <div class="device-detail__toggle">
          <ToggleSwitch
            :model-value="store.detail.enabled"
            :input-id="'enabled'"
            @update:model-value="toggling"
          />
          <label for="enabled">Enabled</label>
        </div>
      </div>
    </header>

    <Message v-if="store.detailError" severity="error" :closable="false">
      {{ store.detailError }}
    </Message>

    <div class="device-detail__grid">
      <Card class="device-detail__card">
        <template #title>Live values</template>
        <template #content>
          <LiveValueList :live="store.detail.live" />
        </template>
      </Card>

      <Card class="device-detail__card">
        <template #title>Device</template>
        <template #content>
          <dl class="device-detail__dl">
            <dt>Group</dt>
            <dd>{{ store.detail.group_id ?? '—' }}</dd>
            <dt>Created</dt>
            <dd>{{ new Date(store.detail.created_at).toLocaleString() }}</dd>
            <dt>Last seen</dt>
            <dd>{{ store.detail.last_seen_at ?? '—' }}</dd>
            <dt>Dev EUI</dt>
            <dd class="mono">{{ store.detail.dev_eui ?? '—' }}</dd>
          </dl>
          <h3 class="device-detail__subtitle">Metadata</h3>
          <ul v-if="metadataLines.length" class="device-detail__meta">
            <li v-for="[key, value] in metadataLines" :key="key">
              <code>{{ key }}</code>
              <span>{{ String(value) }}</span>
            </li>
          </ul>
          <p v-else class="device-detail__muted">No metadata.</p>

          <div v-if="store.detail.protocol === 'lorawan'" class="device-detail__claim">
            <h3 class="device-detail__subtitle">Join provisioning</h3>
            <form class="device-detail__form" @submit.prevent="claim">
              <div class="field">
                <label for="dev_eui">Dev EUI</label>
                <InputText
                  id="dev_eui"
                  v-model="devEui"
                  class="mono"
                  placeholder="AA-00-11-22-33-44-55-66"
                />
              </div>
              <Message v-if="claimError" severity="error" :closable="false">{{ claimError }}</Message>
              <Message v-if="claimSuccess" severity="success" :closable="false">{{ claimSuccess }}</Message>
              <Button type="submit" :loading="claiming" label="Save Dev EUI" icon="pi pi-save" />
            </form>
          </div>
        </template>
      </Card>

      <Card v-if="canCommand" class="device-detail__card">
        <template #title>Command console</template>
        <template #content>
          <form class="device-detail__form" @submit.prevent="send">
            <div v-if="store.detail.protocol === 'mqtt'" class="field">
              <label for="payload">Payload (JSON object)</label>
              <Textarea
                id="payload"
                v-model="payloadJson"
                :auto-resize="false"
                rows="5"
                class="mono"
              />
            </div>
            <div v-else>
              <div class="device-detail__lorawan-row">
                <div class="field">
                  <label for="data">Data (hex)</label>
                  <InputText id="data" v-model="downlinkData" class="mono" placeholder="e.g. 0102a1" />
                </div>
                <div class="field">
                  <label for="fport">FPort</label>
                  <InputNumber id="fport" v-model="fPort" :min="1" :max="223" />
                </div>
              </div>
              <div class="field">
                <label for="confirmed">Confirmed</label>
                <Checkbox id="confirmed" v-model="confirmed" :binary="true" />
              </div>
            </div>

            <Message v-if="commandError" severity="error" :closable="false">{{ commandError }}</Message>
            <Message v-if="commandSuccess" severity="success" :closable="false">{{ commandSuccess }}</Message>

            <Button type="submit" :loading="sending" label="Send command" icon="pi pi-send" />
          </form>

          <h3 class="device-detail__subtitle">Recent commands</h3>
          <div v-if="historyLoading" class="device-detail__muted">Loading…</div>
          <ul v-else-if="commandHistory.length" class="device-detail__history">
            <li v-for="command in commandHistory" :key="command.id" class="device-detail__history-item">
              <Tag
                :value="command.status"
                :severity="statusSeverity[command.status]"
                class="device-detail__history-status"
              />
              <code class="device-detail__history-payload">
                {{ command.type === 'mqtt_message' ? JSON.stringify(command.payload) : command.f_port ? `fPort ${command.f_port}` : command.id }}
              </code>
              <small class="device-detail__history-time">
                {{ new Date(command.updated_at).toLocaleString() }}
              </small>
            </li>
          </ul>
          <p v-else class="device-detail__muted">No commands yet.</p>
        </template>
      </Card>
    </div>
  </section>
  <section v-else class="device-detail device-detail--loading">
    <p>Loading device…</p>
  </section>
</template>

<style scoped>
.device-detail {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.device-detail--loading {
  color: var(--p-text-muted-color);
}
.device-detail__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.device-detail__heading {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.device-detail__title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}
.device-detail__id {
  color: var(--p-text-muted-color);
  font-family: var(--font-mono);
}
.device-detail__controls {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.device-detail__toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--p-text-color);
}
.device-detail__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
  gap: 1rem;
  align-items: start;
}
.device-detail__card :deep(.p-card-body) {
  padding: 1rem;
}
.device-detail__subtitle {
  margin: 1rem 0 0.5rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.device-detail__dl {
  display: grid;
  grid-template-columns: 8rem 1fr;
  gap: 0.35rem 0.75rem;
  margin: 0;
}
.device-detail__dl dt {
  color: var(--p-text-muted-color);
}
.device-detail__dl dd {
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}
.device-detail__meta {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.device-detail__meta code {
  background: var(--p-surface-100);
  padding: 0.1rem 0.35rem;
  border-radius: 0.25rem;
  margin-right: 0.5rem;
}
.device-detail__muted {
  color: var(--p-text-muted-color);
}
.mono {
  font-family: var(--font-mono);
}
.device-detail__form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.device-detail__claim {
  border-top: 1px solid var(--p-surface-200);
  margin-top: 1rem;
  padding-top: 0.25rem;
}
.device-detail__lorawan-row {
  display: grid;
  grid-template-columns: 1fr 8rem;
  gap: 0.75rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.field label {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}
.device-detail__history {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.device-detail__history-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.device-detail__history-status {
  flex: none;
}
.device-detail__history-payload {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.85rem;
}
.device-detail__history-time {
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
  flex: none;
}
</style>
