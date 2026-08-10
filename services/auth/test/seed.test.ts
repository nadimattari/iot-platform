import { describe, expect, it, vi } from 'vitest';
import type { Pool } from 'pg';
import { seedAdmin } from '../src/seed.js';

describe('seedAdmin', () => {
  it('inserts an admin with a bcrypt-hashed password', async () => {
    const query = vi.fn().mockResolvedValue({ rows: [] });
    const pool = { query } as unknown as Pool;

    await seedAdmin(pool, { email: 'admin@example.com', password: 'password123' });

    expect(query).toHaveBeenCalledTimes(1);
    const [sql, values] = query.mock.calls[0];
    expect(sql).toContain('ON CONFLICT (email) DO NOTHING');
    expect(values[0]).toBe('admin@example.com');
    const hash = String(values[1]);
    expect(hash).not.toBe('password123');
    expect(hash.startsWith('$2')).toBe(true);
  });

  it('is idempotent by contract (upsert via ON CONFLICT)', async () => {
    const query = vi.fn().mockResolvedValue({ rows: [] });
    const pool = { query } as unknown as Pool;

    await seedAdmin(pool, { email: 'admin@example.com', password: 'password123' });
    await seedAdmin(pool, { email: 'admin@example.com', password: 'password123' });

    expect(query).toHaveBeenCalledTimes(2);
  });
});
