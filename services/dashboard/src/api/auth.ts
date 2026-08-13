import { api, getRefreshToken } from './client'
import type { LoginResponse, RefreshResponse, User } from './types'

export function login(email: string, password: string): Promise<LoginResponse> {
  return api<LoginResponse>('/auth/login', {
    method: 'POST',
    auth: false,
    body: JSON.stringify({ email, password }),
  })
}

export function refreshToken(): Promise<RefreshResponse> {
  return api<RefreshResponse>('/auth/refresh', {
    method: 'POST',
    auth: false,
    body: JSON.stringify({ refresh_token: getRefreshToken() }),
  })
}

export function logout(): Promise<void> {
  return api<void>('/auth/logout', {
    method: 'POST',
    auth: false,
    body: JSON.stringify({ refresh_token: getRefreshToken() }),
  })
}

export function me(): Promise<User> {
  return api<User>('/auth/me')
}
