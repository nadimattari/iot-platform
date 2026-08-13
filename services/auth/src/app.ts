import Fastify, { type FastifyInstance } from 'fastify';
import type { Config } from './config.js';
import type { SigningKeys } from './keys.js';
import type { AuthRepo } from './services/auth-repo.js';
import { AuthService } from './services/auth-service.js';
import { createMercureTokenService } from './services/mercure-token.js';
import { registerAuthRoutes } from './routes/auth.js';

export interface BuildAppOptions {
  config: Config;
  keys: SigningKeys;
  repo: AuthRepo;
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

  registerAuthRoutes(
    app,
    new AuthService(opts),
    opts.keys,
    createMercureTokenService(opts.config.MERCURE_SUBSCRIBER_JWT_KEY),
  );

  return app;
}
