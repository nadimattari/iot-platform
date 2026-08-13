const STORAGE_KEY = 'iiot.refresh_token'

// The access token lives in memory only; the refresh token survives reloads in
// localStorage. The auth store feeds the session in through setSession().
let accessToken: string | null = null

function readStoredRefresh(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function writeStoredRefresh(token: string | null): void {
  try {
    if (token === null) {
      localStorage.removeItem(STORAGE_KEY)
    } else {
      localStorage.setItem(STORAGE_KEY, token)
    }
  } catch {
    // Storage unavailable (private mode): fall back to a memory-only session.
  }
}

export function setSession(access: string | null, refresh: string | null): void {
  accessToken = access
  writeStoredRefresh(refresh)
}

export function clearSession(): void {
  accessToken = null
  writeStoredRefresh(null)
}

export function hasStoredRefresh(): boolean {
  return readStoredRefresh() !== null
}

export function getRefreshToken(): string | null {
  return readStoredRefresh()
}

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, message: string, body?: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

export interface RequestOptions extends RequestInit {
  /** Attach the access token and run the refresh-retry flow. Default true. */
  auth?: boolean
  /** Allow the 401 refresh-and-retry cycle. Internal; set false on retries. */
  retry?: boolean
}

let refreshing: Promise<boolean> | null = null

async function performRefresh(): Promise<boolean> {
  const token = getRefreshToken()
  if (!token) return false

  const res = await fetch('/auth/refresh', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ refresh_token: token }),
  })
  if (!res.ok) {
    clearSession()
    return false
  }

  const data = (await res.json()) as { access_token: string; refresh_token: string }
  setSession(data.access_token, data.refresh_token)
  return true
}

/** Single in-flight refresh; concurrent 401s share one attempt. */
function refreshOnce(): Promise<boolean> {
  refreshing ??= performRefresh().finally(() => {
    refreshing = null
  })
  return refreshing
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { auth = true, retry = true, headers, ...rest } = options

  const requestHeaders = new Headers(headers)
  requestHeaders.set('Accept', 'application/json')
  if (rest.body !== undefined) requestHeaders.set('Content-Type', 'application/json')
  if (auth && accessToken !== null) requestHeaders.set('Authorization', `Bearer ${accessToken}`)

  const res = await fetch(path, { ...rest, headers: requestHeaders })

  if (res.status === 401 && auth && retry) {
    if (await refreshOnce()) {
      return api<T>(path, { ...options, retry: false })
    }
  }

  if (res.status === 204) {
    return undefined as T
  }

  const body: unknown = await res.json().catch(() => null)
  if (!res.ok) {
    const raw = body as { error?: unknown; message?: unknown }
    const message = typeof raw?.error === 'string' ? raw.error : typeof raw?.message === 'string' ? raw.message : `Request failed (${res.status})`
    throw new ApiError(res.status, message, body)
  }

  return body as T
}
