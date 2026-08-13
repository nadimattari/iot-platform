import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TelemetryView from './TelemetryView.vue'
import type { Device, TelemetryPoint } from '@/api/types'

const device: Device = {
  id: 'dev-1',
  name: 'Temp Sensor',
  protocol: 'mqtt',
  group_id: 'plant-a',
  dev_eui: null,
  metadata: {},
  enabled: true,
  last_seen_at: null,
  created_at: '2026-01-01T00:00:00Z',
}

const points: TelemetryPoint[] = [
  { bucket: '2026-08-13T03:00:00+00:00', field: 'temp', min: 20, max: 22, avg: 21, count: 4 },
  { bucket: '2026-08-13T04:00:00+00:00', field: 'temp', min: 21, max: 23, avg: 22, count: 4 },
  { bucket: '2026-08-13T03:00:00+00:00', field: 'pressure', min: 100, max: 102, avg: 101, count: 4 },
]

const apiMocks = vi.hoisted(() => ({
  listDevices: vi.fn(async () => ({ items: [device], total: 1, page: 1, limit: 100 })),
  deviceTelemetry: vi.fn(
    async (id: string, params: { from: string; to: string; resolution: string }) => ({
      points,
      meta: { device_id: id, from: params.from, to: params.to, resolution: params.resolution },
    }),
  ),
}))

vi.mock('@/api/devices', () => apiMocks)

function mountView() {
  return mount(TelemetryView, {
    global: {
      stubs: {
        Select: { template: '<div class="select-stub" />' },
        Button: { template: '<button class="btn-stub" />' },
        Card: { template: '<div class="card-stub"><slot name="title" /><slot name="content" /><slot /></div>' },
        Message: { template: '<div class="msg-stub"><slot /></div>' },
        TimeSeriesChart: {
          props: ['labels', 'series'],
          template:
            '<div class="chart-stub">{{ series.map(function (s) { return s.label }).join(",") }}</div>',
        },
      },
    },
  })
}

describe('TelemetryView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads devices and queries telemetry with the default range/resolution', async () => {
    const wrapper = mountView()
    await vi.waitFor(() => expect(apiMocks.deviceTelemetry).toHaveBeenCalled())

    const [id, params] = apiMocks.deviceTelemetry.mock.calls[0]
    expect(id).toBe('dev-1')
    expect(params.resolution).toBe('1h')
    expect(params.from).toBeDefined()
    expect(params.to).toBeDefined()

    await vi.waitFor(() => expect(wrapper.text()).toContain('avg,min,max'))
    wrapper.unmount()
  })

  it('renders the chart for the first available field', async () => {
    const wrapper = mountView()
    await vi.waitFor(() => expect(wrapper.find('.chart-stub').exists()).toBe(true))
    await flushPromises()

    expect(wrapper.text()).toContain('Temp Sensor')
    wrapper.unmount()
  })
})
