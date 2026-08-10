import type { Pool } from 'pg';

export interface UserRow {
  id: string;
  email: string;
  password_hash: string;
  role: string;
}

export interface PublicUser {
  id: string;
  email: string;
  role: string;
}

export interface RefreshTokenRow {
  id: string;
  user_id: string;
  family_id: string;
  expires_at: Date;
  revoked_at: Date | null;
}

export interface AuthRepo {
  findUserByEmail(email: string): Promise<UserRow | null>;
  findUserById(id: string): Promise<PublicUser | null>;
  insertRefreshToken(
    userId: string,
    familyId: string,
    tokenHash: string,
    expiresAt: Date,
  ): Promise<void>;
  findRefreshByHash(tokenHash: string): Promise<RefreshTokenRow | null>;
  revokeRefreshToken(id: string): Promise<void>;
  revokeFamily(familyId: string): Promise<void>;
}

export class PgAuthRepo implements AuthRepo {
  constructor(private readonly pool: Pool) {}

  async findUserByEmail(email: string): Promise<UserRow | null> {
    const { rows } = await this.pool.query(
      `SELECT id, email, password_hash, role
       FROM auth.users
       WHERE email = $1`,
      [email],
    );
    return rows[0] ?? null;
  }

  async findUserById(id: string): Promise<PublicUser | null> {
    const { rows } = await this.pool.query(
      `SELECT id, email, role
       FROM auth.users
       WHERE id = $1`,
      [id],
    );
    return rows[0] ?? null;
  }

  async insertRefreshToken(
    userId: string,
    familyId: string,
    tokenHash: string,
    expiresAt: Date,
  ): Promise<void> {
    await this.pool.query(
      `INSERT INTO auth.refresh_tokens (user_id, family_id, token_hash, expires_at)
       VALUES ($1, $2, $3, $4)`,
      [userId, familyId, tokenHash, expiresAt],
    );
  }

  async findRefreshByHash(tokenHash: string): Promise<RefreshTokenRow | null> {
    const { rows } = await this.pool.query(
      `SELECT id, user_id, family_id, expires_at, revoked_at
       FROM auth.refresh_tokens
       WHERE token_hash = $1`,
      [tokenHash],
    );
    return rows[0] ?? null;
  }

  async revokeRefreshToken(id: string): Promise<void> {
    await this.pool.query(
      `UPDATE auth.refresh_tokens SET revoked_at = now() WHERE id = $1`,
      [id],
    );
  }

  async revokeFamily(familyId: string): Promise<void> {
    await this.pool.query(
      `UPDATE auth.refresh_tokens SET revoked_at = now() WHERE family_id = $1`,
      [familyId],
    );
  }
}
