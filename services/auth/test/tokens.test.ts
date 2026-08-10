import { describe, expect, it } from 'vitest';
import { resolveSigningKeys } from '../src/keys.js';
import {
  generateRefreshToken,
  hashRefreshToken,
  signAccessToken,
  verifyAccessToken,
} from '../src/services/tokens.js';

describe('refresh token helpers', () => {
  it('generates unique opaque tokens', () => {
    const a = generateRefreshToken();
    const b = generateRefreshToken();
    expect(a).not.toBe(b);
    expect(a).toMatch(/^[A-Za-z0-9_-]{43}$/);
  });

  it('hashes deterministically and irreversibly', () => {
    const token = generateRefreshToken();
    expect(hashRefreshToken(token)).toBe(hashRefreshToken(token));
    expect(hashRefreshToken(token)).not.toBe(token);
  });
});

describe('access tokens', () => {
  it('signs and verifies round-trip with correct claims', async () => {
    const keys = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
    const token = await signAccessToken(
      keys,
      { sub: 'user-1', email: 'a@b.c', role: 'admin' },
      15,
    );
    const claims = await verifyAccessToken(keys, token);
    expect(claims).toEqual({ sub: 'user-1', email: 'a@b.c', role: 'admin' });
  });

  it('rejects tokens signed by a different key', async () => {
    const keysA = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
    const keysB = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz2' });
    const token = await signAccessToken(keysA, { sub: 'u', email: 'a@b.c', role: 'admin' }, 15);
    expect(await verifyAccessToken(keysB, token)).toBeNull();
  });

  it('rejects garbage input', async () => {
    const keys = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
    expect(await verifyAccessToken(keys, 'not-a-token')).toBeNull();
  });
});
