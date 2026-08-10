import type { FastifyInstance } from 'fastify';
import { z } from 'zod';
import type { AuthService } from '../services/auth-service.js';
import type { SigningKeys } from '../keys.js';
import { verifyAccessToken } from '../services/tokens.js';

const loginSchema = z.object({
  email: z.string().email(),
  password: z.string().min(1),
});

const refreshSchema = z.object({
  refresh_token: z.string().min(1),
});

const logoutSchema = z.object({
  refresh_token: z.string().min(1),
});

export function registerAuthRoutes(
  app: FastifyInstance,
  auth: AuthService,
  keys: SigningKeys,
): void {
  app.post('/auth/login', async (request, reply) => {
    const parsed = loginSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send({ error: 'invalid_request' });

    const result = await auth.login(parsed.data.email, parsed.data.password);
    if (!result) return reply.code(401).send({ error: 'invalid_credentials' });

    return reply.send({
      access_token: result.accessToken,
      refresh_token: result.refreshToken,
      user: result.user,
    });
  });

  app.post('/auth/refresh', async (request, reply) => {
    const parsed = refreshSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send({ error: 'invalid_request' });

    const outcome = await auth.refresh(parsed.data.refresh_token);
    if (outcome.kind === 'reused') return reply.code(401).send({ error: 'reused_token' });
    if (outcome.kind === 'invalid') return reply.code(401).send({ error: 'invalid_token' });

    return reply.send({
      access_token: outcome.result.accessToken,
      refresh_token: outcome.result.refreshToken,
    });
  });

  app.post('/auth/logout', async (request, reply) => {
    const parsed = logoutSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send({ error: 'invalid_request' });

    await auth.logout(parsed.data.refresh_token);
    return reply.code(204).send();
  });

  app.get('/auth/me', async (request, reply) => {
    const header = request.headers.authorization;
    if (!header?.startsWith('Bearer ')) return reply.code(401).send({ error: 'unauthorized' });

    const claims = await verifyAccessToken(keys, header.slice('Bearer '.length));
    if (!claims) return reply.code(401).send({ error: 'unauthorized' });

    return reply.send({
      id: claims.sub,
      email: claims.email,
      role: claims.role,
    });
  });
}
