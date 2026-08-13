export interface User {
  id: string
  email: string
  role: string
}

export interface LoginResponse {
  access_token: string
  refresh_token: string
  user: User
}

export interface RefreshResponse {
  access_token: string
  refresh_token: string
}

export type DeviceProtocol = 'mqtt' | 'modbus' | 'http' | 'lorawan'

export interface Device {
  id: string
  name: string
  protocol: DeviceProtocol
  group_id: string | null
  dev_eui: string | null
  metadata: Record<string, unknown>
  enabled: boolean
  last_seen_at: string | null
  created_at: string
}

export interface DeviceListResult {
  items: Device[]
  total: number
  page: number
  limit: number
}

export interface TelemetryPoint {
  bucket: string
  field: string
  min: number
  max: number
  avg: number
  count: number
}

export interface TelemetrySeries {
  points: TelemetryPoint[]
  meta: {
    device_id: string
    from: string
    to: string
    resolution: string
  }
}

export interface LastReading {
  value: number
  time: string
  type: string
  quality: number
}

export interface LastResponse {
  last: Record<string, LastReading>
}

export interface DeviceStatus {
  device_id: string
  name: string
  protocol: DeviceProtocol
  enabled: boolean
  last_seen: string | null
  heartbeat_secs: number
  online: boolean
}

export interface InsightsField {
  field: string
  min: number
  max: number
  avg: number
  count: number
}

export interface InsightsSummary {
  group_id: string
  bucket: string
  from: string
  to: string
  fields: InsightsField[]
}

export interface InsightsSeriesItem {
  bucket: string
  field: string
  min: number
  max: number
  avg: number
  count: number
}

export interface InsightsTimeseries {
  device_id: string
  bucket: string
  from: string
  to: string
  items: InsightsSeriesItem[]
}

export type CommandType = 'lorawan_downlink' | 'mqtt_message'
export type CommandStatus = 'pending' | 'sent' | 'acked' | 'failed'

export interface Command {
  id: string
  device_id: string
  type: CommandType
  status: CommandStatus
  payload: string | null
  confirmed: boolean
  f_port: number | null
  queue_item_id: string | null
  error: string | null
  created_at: string
  updated_at: string
}

export interface CommandListResult {
  items: Command[]
  total: number
  page: number
  limit: number
}

/** A single normalized telemetry point in a Mercure live event. */
export interface LivePoint {
  field: string
  value: number
  type: string
  quality: number
}

/** Data published by ingestion to the `/devices/{deviceId}` Mercure topic. */
export interface LiveTelemetryEvent {
  device_id: string
  time: string
  points: LivePoint[]
}

/** Data published by device-mgmt to the `/devices/{deviceId}/commands` topic. */
export interface LiveCommandEvent {
  command: Command
}

/** Discriminated union of all Mercure event payloads. */
export type LiveEvent = LiveTelemetryEvent | LiveCommandEvent

export interface MercureTokenResponse {
  mercure_token: string
  expires_in: number
}
