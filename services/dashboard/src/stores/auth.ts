import { defineStore } from 'pinia'
import { clearSession, hasStoredRefresh, setSession } from '@/api/client'
import { login as apiLogin, logout as apiLogout, me } from '@/api/auth'
import type { User } from '@/api/types'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    initialized: false,
  }),

  getters: {
    isAuthenticated: (state): boolean => state.user !== null,
  },

  actions: {
    /**
     * Re-establishes the session from the persisted refresh token on app
     * start. A stored refresh token without a valid session results in a
     * fresh access token (the api client refreshes transparently on 401).
     */
    async restore(): Promise<void> {
      if (this.initialized) return
      if (!hasStoredRefresh()) {
        this.initialized = true
        return
      }
      try {
        this.user = await me()
      } catch {
        this.user = null
      } finally {
        this.initialized = true
      }
    },

    async login(email: string, password: string): Promise<void> {
      const response = await apiLogin(email, password)
      setSession(response.access_token, response.refresh_token)
      this.user = response.user
      this.initialized = true
    },

    async logout(): Promise<void> {
      try {
        await apiLogout()
      } catch {
        // Best effort: the remote session may already be gone.
      }
      clearSession()
      this.user = null
    },
  },
})
