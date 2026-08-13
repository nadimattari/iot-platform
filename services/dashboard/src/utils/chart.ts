import type { TelemetryPoint } from '@/api/types'

export interface RangeWindow {
  label: string
  from: string
  to: string
  hours: number
}

export interface ChartSeries {
  label: string
  data: (number | null)[]
}

/** Preset time-range windows relative to `now` (ISO `from`/`to`). */
export function rangeWindows(now: Date = new Date()): RangeWindow[] {
  const to = now.toISOString()
  const presets: Array<[string, number]> = [
    ['Last 1 hour', 1],
    ['Last 6 hours', 6],
    ['Last 24 hours', 24],
    ['Last 7 days', 168],
    ['Last 30 days', 720],
    ['Last 90 days', 2160],
  ]
  return presets.map(([label, hours]) => ({
    label,
    from: new Date(now.getTime() - hours * 3_600_000).toISOString(),
    to,
    hours,
  }))
}

/** Reasonable downsampling bucket for a range, matching the cagg materialization. */
export function resolutionForRange(hours: number): string {
  if (hours <= 6) return '1m'
  if (hours <= 168) return '1h'
  return '1d'
}

/** Distinct field names present in the point set, sorted. */
export function pickFields(points: TelemetryPoint[]): string[] {
  return [...new Set(points.map((p) => p.field))].sort()
}

/** Human-readable axis label for a cagg bucket at the given resolution. */
export function formatBucket(bucket: string, resolution: string): string {
  const date = new Date(bucket)
  if (resolution === '1m') {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }
  if (resolution === '1h') {
    return date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
  }
  return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

/** avg/min/max lines for one field across its buckets. */
export function buildFieldSeries(
  points: TelemetryPoint[],
  field: string,
  resolution: string,
): { labels: string[]; series: ChartSeries[] } {
  const byBucket = new Map<string, TelemetryPoint>()
  for (const point of points) {
    if (point.field !== field) continue
    byBucket.set(point.bucket, point)
  }
  const buckets = [...byBucket.keys()].sort()
  return {
    labels: buckets.map((bucket) => formatBucket(bucket, resolution)),
    series: [
      { label: 'avg', data: buckets.map((bucket) => byBucket.get(bucket)!.avg) },
      { label: 'min', data: buckets.map((bucket) => byBucket.get(bucket)!.min) },
      { label: 'max', data: buckets.map((bucket) => byBucket.get(bucket)!.max) },
    ],
  }
}

/** One avg line per field, for multi-device/multi-field timeseries. */
export function buildMultiFieldSeries(
  points: TelemetryPoint[],
  resolution: string,
): { labels: string[]; series: ChartSeries[] } {
  const buckets = [...new Set(points.map((p) => p.bucket))].sort()
  const fields = pickFields(points)
  const byBucket = new Map<string, Map<string, number>>()
  for (const point of points) {
    let fieldValues = byBucket.get(point.bucket)
    if (!fieldValues) {
      fieldValues = new Map()
      byBucket.set(point.bucket, fieldValues)
    }
    fieldValues.set(point.field, point.avg)
  }
  return {
    labels: buckets.map((bucket) => formatBucket(bucket, resolution)),
    series: fields.map((field) => ({
      label: field,
      data: buckets.map((bucket) => byBucket.get(bucket)?.get(field) ?? null),
    })),
  }
}
