import bcrypt from 'bcryptjs';
import type { Config } from '../src/config.js';
import type { AuthRepo, PublicUser, RefreshTokenRow, UserRow } from '../src/services/auth-repo.js';

export function makeConfig(overrides: Partial<Config> = {}): Config {
  return {
    PORT: 3000,
    PGHOST: 'db',
    PGPORT: 5432,
    PGUSER: 'iiot',
    PGPASSWORD: 'secret',
    PGDATABASE: 'iiot',
    AUTH_ADMIN_EMAIL: 'admin@example.com',
    AUTH_ADMIN_PASSWORD: 'password123',
    AUTH_JWT_KEYS_DIR: '/keys',
    AUTH_ACCESS_TTL_MINUTES: 15,
    AUTH_REFRESH_DAYS: 30,
    ...overrides,
  };
}

export const ADMIN_ID = '11111111-1111-4111-8111-111111111111';

export function makeAdminUser(password: string = 'password123'): UserRow {
  return {
    id: ADMIN_ID,
    email: 'admin@example.com',
    password_hash: bcrypt.hashSync(password, 4),
    role: 'admin',
  };
}

interface FakeRefreshRow extends RefreshTokenRow {
  token_hash: string;
}

export interface InMemoryRepo extends AuthRepo {
  refreshRows: FakeRefreshRow[];
}

export function createInMemoryRepo(users: UserRow[]): InMemoryRepo {
  const refreshRows: FakeRefreshRow[] = [];
  let nextId = 0;

  const toPublic = (u: UserRow): PublicUser => ({ id: u.id, email: u.email, role: u.role });

  return {
    refreshRows,
    async findUserByEmail(email) {
      return users.find((u) => u.email === email) ?? null;
    },
    async findUserById(id) {
      const u = users.find((x) => x.id === id);
      return u ? toPublic(u) : null;
    },
    async insertRefreshToken(userId, familyId, tokenHash, expiresAt) {
      refreshRows.push({
        id: `rt-${++nextId}`,
        user_id: userId,
        family_id: familyId,
        token_hash: tokenHash,
        expires_at: expiresAt,
        revoked_at: null,
      });
    },
    async findRefreshByHash(tokenHash) {
      return refreshRows.find((r) => r.token_hash === tokenHash) ?? null;
    },
    async revokeRefreshToken(id) {
      const row = refreshRows.find((r) => r.id === id);
      if (row) row.revoked_at = new Date();
    },
    async revokeFamily(familyId) {
      for (const row of refreshRows) {
        if (row.family_id === familyId) row.revoked_at = new Date();
      }
    },
  };
}
