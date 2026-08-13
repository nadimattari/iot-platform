import { api } from './client'
import type {
  Command,
  CommandListResult,
  Device,
  DeviceListResult,
  DeviceStatus,
  LastResponse,
  TelemetrySeries,
} from './types'

export function listDevices(params: { protocol?: string; page?: number; limit?: number } = {}): Promise<DeviceListResult> {
  const search = new URLSearchParams()
  if (params.protocol) search.set('protocol', params.protocol)
  search.set('page', String(params.page ?? 1))
  search.set('limit', String(params.limit ?? 25))
  return api<DeviceListResult>(`/api/v1/devices?${search.toString()}`)
}

export function getDevice(id: string): Promise<{ device: Device }> {
  return api<{ device: Device }>(`/api/v1/devices/${id}`)
}

export function createDevice(input: {
  name: string
  protocol: string
  group_id?: string
  metadata?: Record<string, unknown>
}): Promise<{ device: Device; api_key?: string }> {
  return api<{ device: Device; api_key?: string }>('/api/v1/devices', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updateDevice(id: string, input: { name?: string; group_id?: string | null; metadata?: Record<string, unknown> }): Promise<{ device: Device }> {
  return api<{ device: Device }>(`/api/v1/devices/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export function setDeviceEnabled(id: string, enabled: boolean): Promise<{ device: Device }> {
  return api<{ device: Device }>(`/api/v1/devices/${id}/enabled`, {
    method: 'PUT',
    body: JSON.stringify({ enabled }),
  })
}

export function deleteDevice(id: string): Promise<void> {
  return api<void>(`/api/v1/devices/${id}`, { method: 'DELETE' })
}

export function claimDevice(
  id: string,
  input: { dev_eui?: string; api_key?: string; metadata?: Record<string, unknown> },
): Promise<{ device: Device }> {
  return api<{ device: Device }>(`/api/v1/devices/${id}/claim`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function deviceStatus(id: string): Promise<DeviceStatus> {
  return api<DeviceStatus>(`/api/v1/devices/${id}/status`)
}

export function deviceTelemetry(
  id: string,
  params: { from?: string; to?: string; resolution?: string },
): Promise<TelemetrySeries> {
  const search = new URLSearchParams()
  if (params.from) search.set('from', params.from)
  if (params.to) search.set('to', params.to)
  search.set('resolution', params.resolution ?? '1m')
  return api<TelemetrySeries>(`/api/v1/devices/${id}/telemetry?${search.toString()}`)
}

export function deviceLast(id: string): Promise<LastResponse> {
  return api<LastResponse>(`/api/v1/devices/${id}/last`)
}

export function sendCommand(
  id: string,
  input: { type: string; payload?: string; confirmed?: boolean; f_port?: number },
): Promise<{ command: Command }> {
  return api<{ command: Command }>(`/api/v1/devices/${id}/commands`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function listCommands(params: { page?: number; limit?: number } = {}): Promise<CommandListResult> {
  const search = new URLSearchParams()
  search.set('page', String(params.page ?? 1))
  search.set('limit', String(params.limit ?? 25))
  return api<CommandListResult>(`/api/v1/commands?${search.toString()}`)
}
