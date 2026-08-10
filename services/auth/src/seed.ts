import bcrypt from 'bcryptjs';
import type { Pool } from 'pg';

export interface SeedAdminOptions {
  email: string;
  password: string;
}

export async function seedAdmin(
  pool: Pool,
  opts: SeedAdminOptions,
): Promise<void> {
  const passwordHash = await bcrypt.hash(opts.password, 12);
  await pool.query(
    `INSERT INTO auth.users (email, password_hash, role)
     VALUES ($1, $2, 'admin')
     ON CONFLICT (email) DO NOTHING`,
    [opts.email, passwordHash],
  );
}
