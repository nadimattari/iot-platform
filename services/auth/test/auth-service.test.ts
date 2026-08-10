import { describe, expect, it } from 'vitest';
import { resolveSigningKeys } from '../src/keys.js';
import { AuthService } from '../src/services/auth-service.js';
import { hashRefreshToken } from '../src/services/tokens.js';
import { ADMIN_ID, createInMemoryRepo, makeAdminUser, makeConfig } from './helpers.js';

async function makeService() {
  const keys = await resolveSigningKeys({ keysDir: '/tmp/nonexistent-keys-xyz' });
  const repo = createInMemoryRepo([makeAdminUser()]);
  const service = new AuthService({ repo, keys, config: makeConfig() });
  return { service, repo };
}

describe('AuthService.login', () => {
  it('returns tokens + user for valid credentials', async () => {
    const { service, repo } = await makeService();
    const result = await service.login('admin@example.com', 'password123');
    expect(result).not.toBeNull();
    expect(result!.user).toEqual({ id: ADMIN_ID, email: 'admin@example.com', role: 'admin' });
    expect(result!.accessToken).toBeTruthy();
    expect(result!.refreshToken).toBeTruthy();

    const stored = repo.refreshRows.find(
      (r) => r.token_hash === hashRefreshToken(result!.refreshToken),
    );
    expect(stored).toBeDefined();
    expect(stored!.revoked_at).toBeNull();
    expect(repo.refreshRows).toHaveLength(1);
  });

  it('returns null for a wrong password', async () => {
    const { service } = await makeService();
    expect(await service.login('admin@example.com', 'wrong')).toBeNull();
  });

  it('returns null for an unknown email', async () => {
    const { service } = await makeService();
    expect(await service.login('ghost@example.com', 'password123')).toBeNull();
  });
});

describe('AuthService.refresh', () => {
  it('rotates the token and keeps the family', async () => {
    const { service, repo } = await makeService();
    const login = await service.login('admin@example.com', 'password123');

    const outcome = await service.refresh(login!.refreshToken);
    expect(outcome.kind).toBe('ok');
    if (outcome.kind !== 'ok') return;

    expect(outcome.result.refreshToken).not.toBe(login!.refreshToken);
    expect(repo.refreshRows).toHaveLength(2);

    const oldRow = repo.refreshRows.find(
      (r) => r.token_hash === hashRefreshToken(login!.refreshToken),
    );
    const newRow = repo.refreshRows.find(
      (r) => r.token_hash === hashRefreshToken(outcome.result.refreshToken),
    );
    expect(oldRow!.revoked_at).not.toBeNull();
    expect(newRow!.revoked_at).toBeNull();
    expect(newRow!.family_id).toBe(oldRow!.family_id);
  });

  it('revokes the whole family when a rotated token is reused', async () => {
    const { service, repo } = await makeService();
    const login = await service.login('admin@example.com', 'password123');
    await service.refresh(login!.refreshToken);

    const reuse = await service.refresh(login!.refreshToken);
    expect(reuse.kind).toBe('reused');
    expect(repo.refreshRows.every((r) => r.revoked_at !== null)).toBe(true);
  });

  it('rejects an unknown token', async () => {
    const { service } = await makeService();
    const outcome = await service.refresh('totally-fake-token');
    expect(outcome.kind).toBe('invalid');
  });
});

describe('AuthService.logout', () => {
  it('revokes the presented token', async () => {
    const { service, repo } = await makeService();
    const login = await service.login('admin@example.com', 'password123');

    await service.logout(login!.refreshToken);

    const row = repo.refreshRows.find(
      (r) => r.token_hash === hashRefreshToken(login!.refreshToken),
    );
    expect(row!.revoked_at).not.toBeNull();

    const outcome = await service.refresh(login!.refreshToken);
    expect(outcome.kind).toBe('reused');
  });

  it('is a no-op for unknown tokens', async () => {
    const { service, repo } = await makeService();
    await service.logout('nope');
    expect(repo.refreshRows).toHaveLength(0);
  });
});
