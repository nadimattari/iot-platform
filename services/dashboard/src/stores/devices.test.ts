import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useDevicesStore } from './devices'
import type { Device, LiveTelemetryEvent } from '@/api/types'

const device: Device = {
  id: 'dev-1',
  name: 'Temp Sensor',
  protocol: 'mqtt',
  group_id: null,
  dev_eui: null,
  metadata: {},
  enabled: true,
  last_seen_at: null,
  created_at: '2026-01-01T00:00:00Z',
}

const apiMocks = vi.hoisted(() => ({
  listDevices: vi.fn(async () => ({
    items: [device],
    total: 1,
    page: 1,
    limit: 25,
  })),
  getDevice: vi.fn(async () => ({ device })),
  deviceStatus: vi.fn(async () => ({
    device_id: 'dev-1',
    name: 'Temp Sensor',
    protocol: 'mqtt',
    enabled: true,
    last_seen: null,
    heartbeat_secs: 300,
    online: false,
  })),
  deviceLast: vi.fn(async () => ({
    last: { temp: { value: 21.4, time: '2026-01-01T00:00:00Z', type: 'numeric', quality: 1 } },
  })),
  setDeviceEnabled: vi.fn(async (id: string, enabled: boolean) => ({
    device: { ...device, enabled },
  })),
  claimDevice: vi.fn(async (id: string, input: { dev_eui?: string }) => ({
    device: { ...device, dev_eui: input.dev_eui ?? null },
  })),
}))

vi.mock('@/api/devices', () => apiMocks)

function liveEvent(): LiveTelemetryEvent {
  return {
    device_id: 'dev-1',
    time: '2026-01-02T00:00:00Z',
    points: [{ field: 'temp', value: 22.0, type: 'numeric', quality: 0.98 }],
  }
}

describe('devices store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads the device list and resolves online status', async () => {
    const store = useDevicesStore()
    await store.loadList()

    expect(store.rows).toHaveLength(1)
    expect(store.rows[0].id).toBe('dev-1')
    expect(store.total).toBe(1)
    expect(apiMocks.deviceStatus).toHaveBeenCalledWith('dev-1')
  })

  it('applies live telemetry to matching list rows', async () => {
    const store = useDevicesStore()
    await store.loadList()

    store.applyLive(liveEvent())

    expect(store.rows[0].live.temp).toEqual({
      field: 'temp',
      value: 22.0,
      type: 'numeric',
      quality: 0.98,
    })
    expect(store.rows[0].status?.online).toBe(true)
  })

  it('loads a device detail seeded from the last readings', async () => {
    const store = useDevicesStore()
    await store.loadDetail('dev-1')

    expect(store.detail?.id).toBe('dev-1')
    expect(store.detail?.live.temp.value).toBe(21.4)
    expect(store.detail?.status?.online).toBe(false)
  })

  it('keeps the detail live values current via applyLive', async () => {
    const store = useDevicesStore()
    await store.loadDetail('dev-1')

    store.applyLive(liveEvent())

    expect(store.detail?.live.temp.value).toBe(22.0)
  })

  it('toggles the enabled flag for list and detail', async () => {
    const store = useDevicesStore()
    await store.loadList()
    await store.loadDetail('dev-1')

    await store.setEnabled('dev-1', false)

    expect(store.rows[0].enabled).toBe(false)
    expect(store.detail?.enabled).toBe(false)
  })

  it('updates the dev_eui after claiming', async () => {
    const store = useDevicesStore()
    await store.loadDetail('dev-1')

    await store.claim('dev-1', { dev_eui: 'AA-00-11' })

    expect(store.detail?.dev_eui).toBe('AA-00-11')
  })
})
