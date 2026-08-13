import { api } from './client'
import type { InsightsSummary, InsightsTimeseries } from './types'

export function insightsSummary(
  groupId: string,
  params: { from?: string; to?: string } = {},
): Promise<InsightsSummary> {
  const search = new URLSearchParams({ group_id: groupId })
  if (params.from) search.set('from', params.from)
  if (params.to) search.set('to', params.to)
  return api<InsightsSummary>(`/api/v1/insights/summary?${search.toString()}`)
}

export function insightsTimeseries(
  deviceId: string,
  params: { bucket?: string; from?: string; to?: string } = {},
): Promise<InsightsTimeseries> {
  const search = new URLSearchParams({ device_id: deviceId })
  search.set('bucket', params.bucket ?? '1m')
  if (params.from) search.set('from', params.from)
  if (params.to) search.set('to', params.to)
  return api<InsightsTimeseries>(`/api/v1/insights/timeseries?${search.toString()}`)
}
