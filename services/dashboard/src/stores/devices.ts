import { defineStore } from 'pinia'
import {
  claimDevice,
  deviceLast,
  deviceStatus,
  getDevice,
  listDevices,
  setDeviceEnabled,
} from '@/api/devices'
import type { Device, DeviceStatus, LivePoint, LiveTelemetryEvent } from '@/api/types'

export interface DeviceRow extends Device {
  status: DeviceStatus | null
  live: Record<string, LivePoint>
}

function message(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback
}

export const useDevicesStore = defineStore('devices', {
  state: () => ({
    rows: [] as DeviceRow[],
    total: 0,
    page: 1,
    pageSize: 25,
    protocol: '' as string,
    loading: false,
    error: null as string | null,
    detail: null as DeviceRow | null,
    detailLoading: false,
    detailError: null as string | null,
  }),

  getters: {
    pageCount: (state): number => Math.max(1, Math.ceil(state.total / state.pageSize)),
  },

  actions: {
    async loadList(options: { page?: number; protocol?: string } = {}): Promise<void> {
      this.loading = true
      this.error = null
      try {
        const { items, total, page } = await listDevices({
          protocol: options.protocol === undefined ? this.protocol : options.protocol,
          page: options.page ?? this.page,
          limit: this.pageSize,
        })
        this.rows = items.map((device) => ({ ...device, status: null, live: {} }))
        this.total = total
        this.page = page
        if (options.protocol !== undefined) this.protocol = options.protocol
        await this.loadStatuses()
      } catch (error) {
        this.error = message(error, 'Failed to load devices')
      } finally {
        this.loading = false
      }
    },

    async loadStatuses(): Promise<void> {
      await Promise.allSettled(
        this.rows.map(async (row) => {
          try {
            row.status = await deviceStatus(row.id)
          } catch {
            // Offline status is unknown; leave null.
          }
        }),
      )
    },

    async loadDetail(id: string): Promise<void> {
      this.detailLoading = true
      this.detailError = null
      try {
        const { device } = await getDevice(id)
        const detail: DeviceRow = { ...device, status: null, live: {} }
        this.detail = detail

        const status = await deviceStatus(id).catch(() => null)
        const last = await deviceLast(id).catch(() => null)
        if (this.detail?.id === id) {
          this.detail.status = status
          if (last) {
            for (const [field, reading] of Object.entries(last.last)) {
              this.detail.live[field] = {
                field,
                value: reading.value,
                type: reading.type,
                quality: reading.quality,
              }
            }
          }
        }
      } catch (error) {
        this.detailError = message(error, 'Failed to load device')
      } finally {
        this.detailLoading = false
      }
    },

    applyLive(event: LiveTelemetryEvent): void {
      for (const point of event.points) {
        this.rows.forEach((row) => {
          if (row.id !== event.device_id) return
          row.live[point.field] = point
          if (row.status) {
            row.status.last_seen = event.time
            row.status.online = true
          }
        })
        if (this.detail?.id === event.device_id) {
          this.detail.live[point.field] = point
          if (this.detail.status) {
            this.detail.status.last_seen = event.time
            this.detail.status.online = true
          }
        }
      }
    },

    async setEnabled(id: string, enabled: boolean): Promise<void> {
      const { device } = await setDeviceEnabled(id, enabled)
      const row = this.rows.find((r) => r.id === id)
      if (row) row.enabled = device.enabled
      if (this.detail?.id === id) this.detail.enabled = device.enabled
    },

    async claim(
      id: string,
      input: { dev_eui?: string; api_key?: string; metadata?: Record<string, unknown> },
    ): Promise<void> {
      const { device } = await claimDevice(id, input)
      if (this.detail?.id === id) {
        this.detail.dev_eui = device.dev_eui
        this.detail.metadata = device.metadata
      }
    },
  },
})
