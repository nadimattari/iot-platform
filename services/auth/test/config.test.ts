import { describe, expect, it } from 'vitest';
import { loadConfig } from '../src/config.js';

const BASE_ENV = {
  PGPASSWORD: 'secret',
  AUTH_ADMIN_EMAIL: 'admin@example.com',
  AUTH_ADMIN_PASSWORD: 'password123',
};

describe('loadConfig', () => {
  it('applies defaults for optional fields', () => {
    const config = loadConfig({ ...BASE_ENV } as NodeJS.ProcessEnv);
    expect(config.PORT).toBe(3000);
    expect(config.PGHOST).toBe('db');
    expect(config.PGPORT).toBe(5432);
    expect(config.PGDATABASE).toBe('iiot');
    expect(config.AUTH_JWT_KEYS_DIR).toBe('/keys');
  });

  it('parses explicitly provided values', () => {
    const config = loadConfig({
      ...BASE_ENV,
      PORT: '4000',
      PGHOST: 'localhost',
      PGPORT: '15432',
      AUTH_JWT_KEYS_DIR: '/tmp/keys',
    } as NodeJS.ProcessEnv);
    expect(config.PORT).toBe(4000);
    expect(config.PGHOST).toBe('localhost');
    expect(config.PGPORT).toBe(15432);
    expect(config.AUTH_JWT_KEYS_DIR).toBe('/tmp/keys');
  });

  it('rejects missing required secrets', () => {
    expect(() => loadConfig({} as NodeJS.ProcessEnv)).toThrow();
    expect(() =>
      loadConfig({
        PGPASSWORD: 'secret',
        AUTH_ADMIN_EMAIL: 'admin@example.com',
      } as NodeJS.ProcessEnv),
    ).toThrow();
  });
});
