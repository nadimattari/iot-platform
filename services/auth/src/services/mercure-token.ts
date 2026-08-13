import { SignJWT } from 'jose';

export interface MercureToken {
  token: string;
  expiresInSeconds: number;
}

export interface MercureTokenService {
  issue(subject: string): Promise<MercureToken>;
}

/**
 * Builds an HS256 subscriber JWT for the Mercure hub, signed with the shared
 * subscriber key. The browser never sees the key; it only receives the minted
 * token. Null when the key is not configured.
 */
export function createMercureTokenService(
  subscriberKey: string,
  ttlSeconds = 3600,
  topics: string[] = ['/devices/{id}'],
): MercureTokenService | null {
  if (subscriberKey.length === 0) return null;

  const secret = new TextEncoder().encode(subscriberKey);

  return {
    async issue(subject) {
      const token = await new SignJWT({ mercure: { subscribe: topics } })
        .setProtectedHeader({ alg: 'HS256' })
        .setSubject(subject)
        .setIssuedAt()
        .setExpirationTime(`${ttlSeconds}s`)
        .sign(secret);

      return { token, expiresInSeconds: ttlSeconds };
    },
  };
}
