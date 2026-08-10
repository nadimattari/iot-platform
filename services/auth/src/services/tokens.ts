import { createHash, randomBytes } from 'node:crypto';
import { randomUUID } from 'node:crypto';
import { SignJWT, jwtVerify } from 'jose';
import type { SigningKeys } from '../keys.js';

export interface UserClaims {
  sub: string;
  email: string;
  role: string;
}

export function generateRefreshToken(): string {
  return randomBytes(32).toString('base64url');
}

export function hashRefreshToken(token: string): string {
  return createHash('sha256').update(token).digest('hex');
}

export function newFamilyId(): string {
  return randomUUID();
}

export async function signAccessToken(
  keys: SigningKeys,
  claims: UserClaims,
  ttlMinutes: number,
): Promise<string> {
  return new SignJWT({ email: claims.email, role: claims.role })
    .setProtectedHeader({ alg: 'EdDSA', kid: keys.kid })
    .setSubject(claims.sub)
    .setIssuedAt()
    .setExpirationTime(`${ttlMinutes}m`)
    .sign(keys.privateKey);
}

export async function verifyAccessToken(
  keys: SigningKeys,
  token: string,
): Promise<UserClaims | null> {
  try {
    const { payload } = await jwtVerify(token, keys.publicKey, {
      algorithms: ['EdDSA'],
    });
    if (typeof payload.sub !== 'string') return null;
    return {
      sub: payload.sub,
      email: typeof payload.email === 'string' ? payload.email : '',
      role: typeof payload.role === 'string' ? payload.role : '',
    };
  } catch {
    return null;
  }
}
