import { loadConfig } from './config.js';
import { createPool } from './db.js';
import { resolveSigningKeys } from './keys.js';
import { migrate } from './schema.js';
import { seedAdmin } from './seed.js';
import { buildApp } from './app.js';

async function main(): Promise<void> {
  const config = loadConfig();
  const pool = createPool();

  await migrate(pool);

  const keys = await resolveSigningKeys({
    keysDir: config.AUTH_JWT_KEYS_DIR,
    privateKeyPem: config.AUTH_JWT_PRIVATE_KEY || undefined,
    publicKeyPem: config.AUTH_JWT_PUBLIC_KEY || undefined,
  });

  await seedAdmin(pool, {
    email: config.AUTH_ADMIN_EMAIL,
    password: config.AUTH_ADMIN_PASSWORD,
  });

  const app = buildApp({ config, keys });
  await app.listen({ host: '0.0.0.0', port: config.PORT });
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
