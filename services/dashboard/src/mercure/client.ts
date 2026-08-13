import { mercureToken } from '@/api/auth'
import type { LiveEvent } from '@/api/types'

export type LiveHandler = (event: LiveEvent) => void

// Mercure reads the subscriber JWT from this cookie when the Authorization
// header is unavailable (EventSource cannot set request headers).
const COOKIE_NAME = 'mercureAuthorization'
const REFRESH_BEFORE_MS = 60_000

function setAuthorizationCookie(token: string): void {
  document.cookie = `${COOKIE_NAME}=${token}; Path=/; SameSite=Strict; Max-Age=7200`
}

/**
 * Multiplexes one Mercure EventSource per set of topics and routes live
 * telemetry events (`/devices/{deviceId}`) to registered handlers. Reconnects
 * with a fresh subscriber JWT when the token approaches expiry.
 */
export class MercureHub {
  private handlers = new Map<string, Set<LiveHandler>>()
  private token: string | null = null
  private tokenExpiresAtMs = 0
  private tokenPromise: Promise<void> | null = null
  private es: EventSource | null = null
  private reopenTimer: number | null = null

  /** Registers a handler; returns an unsubscribe function. */
  subscribe(topic: string, handler: LiveHandler): () => void {
    let set = this.handlers.get(topic)
    if (!set) {
      set = new Set()
      this.handlers.set(topic, set)
    }
    set.add(handler)
    void this.scheduleReopen()
    return () => this.unsubscribe(topic, handler)
  }

  unsubscribe(topic: string, handler: LiveHandler): void {
    const set = this.handlers.get(topic)
    if (!set) return
    set.delete(handler)
    if (set.size === 0) this.handlers.delete(topic)
    void this.scheduleReopen()
  }

  disconnect(): void {
    if (this.reopenTimer !== null) {
      window.clearTimeout(this.reopenTimer)
      this.reopenTimer = null
    }
    this.closeSource()
    this.handlers.clear()
  }

  private scheduleReopen(): void {
    if (this.reopenTimer !== null) return
    this.reopenTimer = window.setTimeout(() => {
      this.reopenTimer = null
      void this.reopen()
    }, 0)
  }

  private async reopen(): Promise<void> {
    this.closeSource()
    if (this.handlers.size === 0) return
    await this.ensureToken()
    this.open(this.topics())
  }

  private topics(): string[] {
    return [...this.handlers.keys()]
  }

  private async ensureToken(): Promise<void> {
    if (this.token && Date.now() < this.tokenExpiresAtMs - REFRESH_BEFORE_MS) return
    if (this.tokenPromise) return this.tokenPromise

    this.tokenPromise = mercureToken()
      .then(({ mercure_token, expires_in }) => {
        this.token = mercure_token
        this.tokenExpiresAtMs = Date.now() + expires_in * 1000
        setAuthorizationCookie(mercure_token)
      })
      .finally(() => {
        this.tokenPromise = null
      })
    return this.tokenPromise
  }

  private open(topics: string[]): void {
    const params = new URLSearchParams()
    for (const topic of topics) params.append('topic', topic)

    const source = new EventSource(`/.well-known/mercure?${params.toString()}`)
    this.es = source

    source.onmessage = (event: MessageEvent<string>) => this.dispatch(event.data)
    source.onerror = () => {
      if (Date.now() < this.tokenExpiresAtMs - REFRESH_BEFORE_MS) return
      // Token near expiry: refresh it and restart the connection, since
      // EventSource cannot retry with new request headers.
      source.close()
      this.es = null
      void this.ensureToken().then(() => this.open(topics))
    }
  }

  private dispatch(raw: string): void {
    let data: LiveEvent
    try {
      data = JSON.parse(raw) as LiveEvent
    } catch {
      return
    }
    const isCommand = 'command' in data
    const deviceId = 'command' in data ? data.command.device_id : data.device_id
    if (typeof deviceId !== 'string') return

    for (const topic of this.topics()) {
      const isCommandTopic = topic === `/devices/${deviceId}/commands`
      const isTelemetryTopic = topic === '/devices/*' || topic === `/devices/${deviceId}`
      if (!isCommandTopic && !isTelemetryTopic) continue
      if (isCommandTopic !== isCommand) continue
      const set = this.handlers.get(topic)
      if (!set) continue
      for (const handler of set) handler(data)
    }
  }

  private closeSource(): void {
    if (this.es !== null) {
      this.es.close()
      this.es = null
    }
  }
}

/** App-wide singleton so list and detail views share one SSE connection. */
export const mercure = new MercureHub()
