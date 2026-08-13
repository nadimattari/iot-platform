import { describe, expect, it } from 'vitest';
import type { FastifyInstance } from 'fastify';
import { buildApp } from '../src/app.js';
import { resolveSigningKeys } from '../src/keys.js';
import { createInMemoryRepo, makeAdminUser, makeConfig } from './helpers.js';

async function makeApp(): Promise<FastifyInstance> {
  const keys = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
  return buildApp({
    config: makeConfig(),
    keys,
    repo: createInMemoryRepo([makeAdminUser()]),
  });
}

describe('POST /auth/login', () => {
  it('returns access + refresh tokens for valid credentials', async () => {
    const app = await makeApp();
    const res = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'password123' },
    });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.access_token).toBeTruthy();
    expect(body.refresh_token).toBeTruthy();
    expect(body.user).toEqual({
      id: expect.any(String),
      email: 'admin@example.com',
      role: 'admin',
    });
    await app.close();
  });

  it('rejects bad credentials with 401', async () => {
    const app = await makeApp();
    const res = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'wrong' },
    });
    expect(res.statusCode).toBe(401);
    await app.close();
  });

  it('rejects a malformed body with 400', async () => {
    const app = await makeApp();
    const res = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'not-an-email', password: '' },
    });
    expect(res.statusCode).toBe(400);
    await app.close();
  });
});

describe('GET /auth/me', () => {
  it('returns the user for a valid bearer token', async () => {
    const app = await makeApp();
    const login = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'password123' },
    });
    const { access_token } = login.json();

    const res = await app.inject({
      method: 'GET',
      url: '/auth/me',
      headers: { authorization: `Bearer ${access_token}` },
    });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toEqual({
      id: expect.any(String),
      email: 'admin@example.com',
      role: 'admin',
    });
    await app.close();
  });

  it('returns 401 without a token', async () => {
    const app = await makeApp();
    const res = await app.inject({ method: 'GET', url: '/auth/me' });
    expect(res.statusCode).toBe(401);
    await app.close();
  });

  it('returns 401 for a garbage token', async () => {
    const app = await makeApp();
    const res = await app.inject({
      method: 'GET',
      url: '/auth/me',
      headers: { authorization: 'Bearer garbage' },
    });
    expect(res.statusCode).toBe(401);
    await app.close();
  });
});

describe('POST /auth/refresh', () => {
  it('rotates and returns a new access token', async () => {
    const app = await makeApp();
    const login = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'password123' },
    });
    const { refresh_token } = login.json();

    const res = await app.inject({
      method: 'POST',
      url: '/auth/refresh',
      payload: { refresh_token },
    });
    expect(res.statusCode).toBe(200);
    expect(res.json().access_token).toBeTruthy();
    expect(res.json().refresh_token).not.toBe(refresh_token);
    await app.close();
  });

  it('rejects a reused (already rotated) token with 401', async () => {
    const app = await makeApp();
    const login = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'password123' },
    });
    const { refresh_token } = login.json();

    await app.inject({ method: 'POST', url: '/auth/refresh', payload: { refresh_token } });

    const reuse = await app.inject({
      method: 'POST',
      url: '/auth/refresh',
      payload: { refresh_token },
    });
    expect(reuse.statusCode).toBe(401);
    await app.close();
  });
});

describe('POST /auth/logout', () => {
  it('revokes the refresh token so refresh fails afterwards', async () => {
    const app = await makeApp();
    const login = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'password123' },
    });
    const { refresh_token } = login.json();

    const logout = await app.inject({
      method: 'POST',
      url: '/auth/logout',
      payload: { refresh_token },
    });
    expect(logout.statusCode).toBe(204);

    const refresh = await app.inject({
      method: 'POST',
      url: '/auth/refresh',
      payload: { refresh_token },
    });
    expect(refresh.statusCode).toBe(401);
    await app.close();
  });
});

describe('GET /auth/mercure-token', () => {
  it('mints an HS256 subscriber token with the /devices/{id} topic selector', async () => {
    const app = await makeApp();
    const login = await app.inject({
      method: 'POST',
      url: '/auth/login',
      payload: { email: 'admin@example.com', password: 'password123' },
    });
    const { access_token } = login.json();

    const res = await app.inject({
      method: 'GET',
      url: '/auth/mercure-token',
      headers: { authorization: `Bearer ${access_token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = res.json() as { mercure_token: string; expires_in: number };
    expect(body.expires_in).toBeGreaterThan(0);

    const parts = body.mercure_token.split('.');
    expect(parts).toHaveLength(3);
    const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8')) as {
      mercure?: { subscribe?: string[] };
      exp?: number;
    };
    expect(payload.mercure?.subscribe).toContain('/devices/{id}');
    expect(payload.exp).toBeGreaterThan(Math.floor(Date.now() / 1000));
    await app.close();
  });

  it('returns 401 without a bearer token', async () => {
    const app = await makeApp();
    const res = await app.inject({ method: 'GET', url: '/auth/mercure-token' });
    expect(res.statusCode).toBe(401);
    await app.close();
  });
});
