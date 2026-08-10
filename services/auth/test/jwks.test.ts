import { describe, expect, it } from 'vitest';
import { buildApp } from '../src/app.js';
import { resolveSigningKeys } from '../src/keys.js';
import { createInMemoryRepo, makeAdminUser, makeConfig } from './helpers.js';

describe('buildApp routes', () => {
  it('serves /health', async () => {
    const keys = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
    const app = buildApp({
      config: makeConfig(),
      keys,
      repo: createInMemoryRepo([makeAdminUser()]),
    });
    const res = await app.inject({ method: 'GET', url: '/health' });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toEqual({ status: 'ok' });
    await app.close();
  });

  it('serves an Ed25519 JWK at /auth/jwks', async () => {
    const keys = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
    const app = buildApp({
      config: makeConfig(),
      keys,
      repo: createInMemoryRepo([makeAdminUser()]),
    });
    const res = await app.inject({ method: 'GET', url: '/auth/jwks' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.keys).toHaveLength(1);
    expect(body.keys[0]).toMatchObject({
      kty: 'OKP',
      crv: 'Ed25519',
      use: 'sig',
      alg: 'EdDSA',
      kid: keys.kid,
    });
    await app.close();
  });
});
