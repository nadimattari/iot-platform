import { z } from 'zod';

const envSchema = z.object({
  PORT: z.coerce.number().int().positive().default(3000),
  PGHOST: z.string().min(1).default('db'),
  PGPORT: z.coerce.number().int().positive().default(5432),
  PGUSER: z.string().min(1).default('iiot'),
  PGPASSWORD: z.string().min(1),
  PGDATABASE: z.string().min(1).default('iiot'),
  AUTH_ADMIN_EMAIL: z.string().email(),
  AUTH_ADMIN_PASSWORD: z.string().min(8),
  AUTH_JWT_KEYS_DIR: z.string().min(1).default('/keys'),
  AUTH_JWT_PRIVATE_KEY: z.string().optional(),
  AUTH_JWT_PUBLIC_KEY: z.string().optional(),
  AUTH_ACCESS_TTL_MINUTES: z.coerce.number().int().positive().default(15),
  AUTH_REFRESH_DAYS: z.coerce.number().int().positive().default(30),
  MERCURE_SUBSCRIBER_JWT_KEY: z.string().default(''),
});

export type Config = z.infer<typeof envSchema>;

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  return envSchema.parse(env);
}
