import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, clearSession, getRefreshToken, hasStoredRefresh, setSession } from './client'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('api client', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    clearSession()
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('attaches the access token and parses JSON', async () => {
    setSession('access-1', 'refresh-1')
    fetchMock.mockResolvedValueOnce(jsonResponse({ ok: true }))

    await expect(api('/api/v1/devices')).resolves.toEqual({ ok: true })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/v1/devices')
    expect(init.headers.get('Authorization')).toBe('Bearer access-1')
  })

  it('does not attach the token to public requests', async () => {
    setSession('access-1', 'refresh-1')
    fetchMock.mockResolvedValueOnce(jsonResponse({ ok: true }))

    await api('/auth/login', { method: 'POST', auth: false, body: '{}' })

    const [, init] = fetchMock.mock.calls[0]
    expect(init.headers.get('Authorization')).toBeNull()
  })

  it('refreshes once on 401 and retries the request with the new token', async () => {
    setSession('access-1', 'refresh-1')
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ error: 'unauthorized' }, 401))
      .mockResolvedValueOnce(jsonResponse({ access_token: 'access-2', refresh_token: 'refresh-2' }))
      .mockResolvedValueOnce(jsonResponse({ ok: true }))

    await expect(api('/api/v1/devices')).resolves.toEqual({ ok: true })

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[1][0]).toBe('/auth/refresh')
    expect(getRefreshToken()).toBe('refresh-2')
    expect(localStorage.getItem('iiot.refresh_token')).toBe('refresh-2')
    expect(fetchMock.mock.calls[2][1].headers.get('Authorization')).toBe('Bearer access-2')
  })

  it('clears the session when the refresh attempt fails', async () => {
    setSession('access-1', 'refresh-1')
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ error: 'unauthorized' }, 401))
      .mockResolvedValueOnce(jsonResponse({ error: 'invalid_token' }, 401))

    await expect(api('/api/v1/devices')).rejects.toBeInstanceOf(ApiError)
    expect(hasStoredRefresh()).toBe(false)
    expect(localStorage.getItem('iiot.refresh_token')).toBeNull()
  })

  it('throws ApiError with the server error message', async () => {
    setSession('access-1', 'refresh-1')
    fetchMock.mockResolvedValueOnce(jsonResponse({ error: 'invalid_request' }, 422))

    const promise = api('/api/v1/devices', { method: 'POST', body: '{}' })
    await expect(promise).rejects.toBeInstanceOf(ApiError)
    await expect(promise).rejects.toMatchObject({ status: 422, message: 'invalid_request' })
  })
})
