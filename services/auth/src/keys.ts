import { promises as fs } from 'node:fs';
import { join } from 'node:path';
import {
  calculateJwkThumbprint,
  exportJWK,
  exportPKCS8,
  exportSPKI,
  generateKeyPair,
  importPKCS8,
  importSPKI,
  type JWK,
} from 'jose';

export interface SigningKeys {
  privateKey: CryptoKey;
  publicKey: CryptoKey;
  publicJwk: JWK;
  kid: string;
}

export interface ResolveOptions {
  keysDir: string;
  privateKeyPem?: string;
  publicKeyPem?: string;
}

async function buildKeys(privateKey: CryptoKey, publicKey: CryptoKey): Promise<SigningKeys> {
  const publicJwk = await exportJWK(publicKey);
  const kid = await calculateJwkThumbprint(publicJwk, 'sha256');
  return { privateKey, publicKey, publicJwk, kid };
}

async function generateAndPersist(keysDir: string): Promise<SigningKeys> {
  await fs.mkdir(keysDir, { recursive: true });
  const { publicKey, privateKey } = await generateKeyPair('EdDSA', { extractable: true });
  await fs.writeFile(join(keysDir, 'private.pem'), await exportPKCS8(privateKey), {
    mode: 0o600,
  });
  await fs.writeFile(join(keysDir, 'public.pem'), await exportSPKI(publicKey), {
    mode: 0o600,
  });
  return buildKeys(privateKey, publicKey);
}

async function loadFromDir(keysDir: string): Promise<SigningKeys> {
  const privatePem = await fs.readFile(join(keysDir, 'private.pem'), 'utf8');
  const publicPem = await fs.readFile(join(keysDir, 'public.pem'), 'utf8');
  return buildKeys(
    await importPKCS8(privatePem, 'EdDSA'),
    await importSPKI(publicPem, 'EdDSA'),
  );
}

export async function resolveSigningKeys(opts: ResolveOptions): Promise<SigningKeys> {
  if (opts.privateKeyPem && opts.publicKeyPem) {
    return buildKeys(
      await importPKCS8(opts.privateKeyPem, 'EdDSA'),
      await importSPKI(opts.publicKeyPem, 'EdDSA'),
    );
  }
  try {
    await fs.access(join(opts.keysDir, 'private.pem'));
    await fs.access(join(opts.keysDir, 'public.pem'));
    return loadFromDir(opts.keysDir);
  } catch {
    return generateAndPersist(opts.keysDir);
  }
}
