import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MercureHub } from './client'
import { mercureToken } from '@/api/auth'
import type { LiveTelemetryEvent } from '@/api/types'

vi.mock('@/api/auth', () => ({
  mercureToken: vi.fn(),
}))

const tokenMock = vi.mocked(mercureToken)

class FakeEventSource {
  static instances: FakeEventSource[] = []
  onmessage: ((event: MessageEvent<string>) => void) | null = null
  onerror: (() => void) | null = null
  closed = false

  constructor(public url: string) {
    FakeEventSource.instances.push(this)
  }

  close(): void {
    this.closed = true
  }
}

function emit(es: FakeEventSource, event: LiveTelemetryEvent): void {
  es.onmessage?.({ data: JSON.stringify(event) } as MessageEvent<string>)
}

describe('MercureHub', () => {
  beforeEach(() => {
    FakeEventSource.instances = []
    document.cookie = 'mercureAuthorization=; Max-Age=0'
    tokenMock.mockReset()
    tokenMock.mockResolvedValue({ mercure_token: 'jwt-1', expires_in: 1200 })
    vi.stubGlobal('EventSource', FakeEventSource)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('opens a connection with topic params after minting a token', async () => {
    const hub = new MercureHub()
    hub.subscribe('/devices/*', vi.fn())
    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBe(1))

    const es = FakeEventSource.instances[0]
    expect(es.url).toContain('/.well-known/mercure')
    expect(es.url).toContain('topic=%2Fdevices%2F%7Bid%7D')
    expect(document.cookie).toContain('mercureAuthorization=jwt-1')

    hub.disconnect()
  })

  it('routes a telemetry event to wildcard and exact-topic handlers', async () => {
    const hub = new MercureHub()
    const wildcard = vi.fn()
    const exact = vi.fn()
    const other = vi.fn()
    hub.subscribe('/devices/*', wildcard)
    hub.subscribe('/devices/abc', exact)
    hub.subscribe('/devices/other', other)
    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBe(1))

    emit(FakeEventSource.instances[0], {
      device_id: 'abc',
      time: '2026-01-01T00:00:00Z',
      points: [{ field: 'temp', value: 21.5, type: 'numeric', quality: 1 }],
    })

    expect(wildcard).toHaveBeenCalledTimes(1)
    expect(exact).toHaveBeenCalledTimes(1)
    expect(other).not.toHaveBeenCalled()

    hub.disconnect()
  })

  it('reopens with a fresh token when the current one nears expiry', async () => {
    tokenMock
      .mockResolvedValueOnce({ mercure_token: 'jwt-old', expires_in: 0 })
      .mockResolvedValueOnce({ mercure_token: 'jwt-new', expires_in: 1200 })

    const hub = new MercureHub()
    hub.subscribe('/devices/*', vi.fn())
    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBe(1))

    const oldEs = FakeEventSource.instances[0]
    oldEs.onerror?.()

    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBe(2))
    const newEs = FakeEventSource.instances[1]
    expect(newEs).not.toBe(oldEs)
    expect(oldEs.closed).toBe(true)
    expect(newEs.closed).toBe(false)
    expect(document.cookie).toContain('mercureAuthorization=jwt-new')

    hub.disconnect()
  })

  it('closes the connection when the last handler unsubscribes', async () => {
    const hub = new MercureHub()
    const unsub = hub.subscribe('/devices/*', vi.fn())
    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBe(1))

    unsub()
    await vi.waitFor(() => expect(FakeEventSource.instances[0].closed).toBe(true))

    hub.disconnect()
  })
})
