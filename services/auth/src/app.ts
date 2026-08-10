import Fastify, { type FastifyInstance } from 'fastify';
import type { Config } from './config.js';
import type { SigningKeys } from './keys.js';

export interface BuildAppOptions {
  config: Config;
  keys: SigningKeys;
}

export function buildApp(opts: BuildAppOptions): FastifyInstance {
  const app = Fastify({ logger: true });

  app.get('/health', async () => ({ status: 'ok' }));

  app.get('/auth/jwks', async () => ({
    keys: [
      {
        ...opts.keys.publicJwk,
        kid: opts.keys.kid,
        use: 'sig',
        alg: 'EdDSA',
      },
    ],
  }));

  return app;
}
