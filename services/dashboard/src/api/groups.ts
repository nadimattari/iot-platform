import { api } from './client'
import type { DeviceGroup } from './types'

export function listGroups(): Promise<{ items: DeviceGroup[]; total: number }> {
  return api<{ items: DeviceGroup[]; total: number }>('/api/v1/groups')
}
