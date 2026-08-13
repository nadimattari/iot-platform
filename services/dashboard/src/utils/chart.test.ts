import { describe, expect, it } from 'vitest'
import {
  buildFieldSeries,
  buildMultiFieldSeries,
  formatBucket,
  pickFields,
  rangeWindows,
  resolutionForRange,
} from './chart'
import type { TelemetryPoint } from '@/api/types'

function point(bucket: string, field: string, avg: number): TelemetryPoint {
  return { bucket, field, min: avg - 1, max: avg + 1, avg, count: 5 }
}

describe('rangeWindows', () => {
  it('produces ISO windows ending now, ascending in width', () => {
    const windows = rangeWindows(new Date('2026-08-13T12:00:00Z'))
    expect(windows).toHaveLength(6)
    expect(windows[0].label).toBe('Last 1 hour')
    expect(windows[0].from).toBe('2026-08-13T11:00:00.000Z')
    expect(windows[0].to).toBe('2026-08-13T12:00:00.000Z')
    expect(windows[5].hours).toBe(2160)
  })
})

describe('resolutionForRange', () => {
  it('picks a sensible downsampling bucket', () => {
    expect(resolutionForRange(1)).toBe('1m')
    expect(resolutionForRange(6)).toBe('1m')
    expect(resolutionForRange(24)).toBe('1h')
    expect(resolutionForRange(168)).toBe('1h')
    expect(resolutionForRange(720)).toBe('1d')
  })
})

describe('pickFields', () => {
  it('returns sorted distinct fields', () => {
    expect(pickFields([point('a', 'temp', 1), point('b', 'pressure', 2), point('c', 'temp', 3)])).toEqual([
      'pressure',
      'temp',
    ])
  })
})

describe('formatBucket', () => {
  it('formats by resolution', () => {
    expect(formatBucket('2026-08-13T04:00:00+00:00', '1m')).toMatch(/\d{2}:\d{2}/)
    expect(formatBucket('2026-08-13T00:00:00+00:00', '1h')).toContain('Aug')
    expect(formatBucket('2026-08-13T00:00:00+00:00', '1d')).toContain('Aug')
  })
})

describe('buildFieldSeries', () => {
  it('builds avg/min/max lines for one field, sorted by bucket', () => {
    const points = [
      point('2026-08-13T04:00:00+00:00', 'temp', 22),
      point('2026-08-13T03:00:00+00:00', 'temp', 21),
      point('2026-08-13T03:00:00+00:00', 'pressure', 100),
    ]
    const { labels, series } = buildFieldSeries(points, 'temp', '1h')

    expect(labels).toHaveLength(2)
    expect(series.map((s) => s.label)).toEqual(['avg', 'min', 'max'])
    expect(series[0].data).toEqual([21, 22])
    expect(series[1].data).toEqual([20, 21])
    expect(series[2].data).toEqual([22, 23])
  })
})

describe('buildMultiFieldSeries', () => {
  it('builds one avg line per field with null gaps for missing buckets', () => {
    const points = [
      point('2026-08-13T03:00:00+00:00', 'temp', 21),
      point('2026-08-13T04:00:00+00:00', 'temp', 22),
      point('2026-08-13T03:00:00+00:00', 'pressure', 100),
    ]
    const { series } = buildMultiFieldSeries(points, '1h')

    expect(series.map((s) => s.label)).toEqual(['pressure', 'temp'])
    expect(series[1].data).toEqual([21, 22])
    expect(series[0].data).toEqual([100, null])
  })
})
