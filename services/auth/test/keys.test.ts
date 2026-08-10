import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { resolveSigningKeys } from '../src/keys.js';

describe('resolveSigningKeys', () => {
  it('generates a persistent Ed25519 keypair on first use', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'auth-keys-'));
    try {
      const keys = await resolveSigningKeys({ keysDir: dir });
      expect(keys.publicJwk.kty).toBe('OKP');
      expect(keys.publicJwk.crv).toBe('Ed25519');
      expect(keys.kid).toMatch(/^[A-Za-z0-9_-]+$/);

      const privatePem = await readFile(join(dir, 'private.pem'), 'utf8');
      const publicPem = await readFile(join(dir, 'public.pem'), 'utf8');
      expect(privatePem).toContain('PRIVATE KEY');
      expect(publicPem).toContain('PUBLIC KEY');
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  });

  it('reloads the same keypair (and kid) from disk', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'auth-keys-'));
    try {
      const first = await resolveSigningKeys({ keysDir: dir });
      const second = await resolveSigningKeys({ keysDir: dir });
      expect(second.publicJwk).toEqual(first.publicJwk);
      expect(second.kid).toBe(first.kid);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  });

  it('accepts explicitly provided PEMs without touching disk', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'auth-keys-'));
    try {
      const fromDir = await resolveSigningKeys({ keysDir: dir });
      const privatePem = await readFile(join(dir, 'private.pem'), 'utf8');
      const publicPem = await readFile(join(dir, 'public.pem'), 'utf8');

      const fromPem = await resolveSigningKeys({
        keysDir: join(dir, 'unused'),
        privateKeyPem: privatePem,
        publicKeyPem: publicPem,
      });
      expect(fromPem.kid).toBe(fromDir.kid);
      expect(fromPem.publicJwk).toEqual(fromDir.publicJwk);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  });
});
