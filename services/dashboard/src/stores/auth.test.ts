import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { clearSession } from '@/api/client'
import { useAuthStore } from './auth'

vi.mock('@/api/auth', () => ({
  login: vi.fn(async () => ({
    access_token: 'access-1',
    refresh_token: 'refresh-1',
    user: { id: 'u1', email: 'a@b.c', role: 'admin' },
  })),
  logout: vi.fn(async () => undefined),
  me: vi.fn(async () => ({ id: 'u1', email: 'a@b.c', role: 'admin' })),
}))

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    clearSession()
  })

  it('logs in, stores the user and persists the refresh token', async () => {
    const auth = useAuthStore()
    await auth.login('a@b.c', 'secret')

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.email).toBe('a@b.c')
    expect(auth.user?.role).toBe('admin')
    expect(localStorage.getItem('iiot.refresh_token')).toBe('refresh-1')
  })

  it('restores the session from a stored refresh token', async () => {
    localStorage.setItem('iiot.refresh_token', 'stored-refresh')
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.initialized).toBe(true)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.email).toBe('a@b.c')
  })

  it('restore without a stored refresh token stays unauthenticated', async () => {
    const auth = useAuthStore()
    await auth.restore()

    expect(auth.initialized).toBe(true)
    expect(auth.isAuthenticated).toBe(false)
  })

  it('logs out and clears the persisted token', async () => {
    const auth = useAuthStore()
    await auth.login('a@b.c', 'secret')
    await auth.logout()

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('iiot.refresh_token')).toBeNull()
  })
})
