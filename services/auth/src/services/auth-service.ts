import bcrypt from 'bcryptjs';
import type { Config } from '../config.js';
import type { SigningKeys } from '../keys.js';
import type { PublicUser } from './auth-repo.js';
import type { AuthRepo } from './auth-repo.js';
import {
  generateRefreshToken,
  hashRefreshToken,
  newFamilyId,
  signAccessToken,
} from './tokens.js';

export interface LoginResult {
  user: PublicUser;
  accessToken: string;
  refreshToken: string;
}

export interface RefreshResult {
  accessToken: string;
  refreshToken: string;
}

export type RefreshOutcome =
  | { kind: 'ok'; result: RefreshResult }
  | { kind: 'reused' }
  | { kind: 'invalid' };

export interface AuthServiceOptions {
  repo: AuthRepo;
  keys: SigningKeys;
  config: Config;
}

export class AuthService {
  constructor(private readonly opts: AuthServiceOptions) {}

  async login(email: string, password: string): Promise<LoginResult | null> {
    const user = await this.opts.repo.findUserByEmail(email);
    if (!user) return null;

    const passwordOk = await bcrypt.compare(password, user.password_hash);
    if (!passwordOk) return null;

    const { accessToken, refreshToken } = await this.issueTokens(user.id, user.email, user.role);
    return {
      user: { id: user.id, email: user.email, role: user.role },
      accessToken,
      refreshToken,
    };
  }

  async refresh(refreshToken: string): Promise<RefreshOutcome> {
    const record = await this.opts.repo.findRefreshByHash(hashRefreshToken(refreshToken));
    if (!record) return { kind: 'invalid' };

    if (record.revoked_at !== null) {
      await this.opts.repo.revokeFamily(record.family_id);
      return { kind: 'reused' };
    }

    if (new Date(record.expires_at).getTime() < Date.now()) {
      await this.opts.repo.revokeFamily(record.family_id);
      return { kind: 'reused' };
    }

    const user = await this.opts.repo.findUserById(record.user_id);
    if (!user) return { kind: 'invalid' };

    await this.opts.repo.revokeRefreshToken(record.id);
    const { accessToken, refreshToken: next } = await this.issueTokens(
      user.id,
      user.email,
      user.role,
      record.family_id,
    );
    return { kind: 'ok', result: { accessToken, refreshToken: next } };
  }

  async logout(refreshToken: string): Promise<void> {
    const record = await this.opts.repo.findRefreshByHash(hashRefreshToken(refreshToken));
    if (!record || record.revoked_at !== null) return;
    await this.opts.repo.revokeRefreshToken(record.id);
  }

  private async issueTokens(
    userId: string,
    email: string,
    role: string,
    familyId: string = newFamilyId(),
  ): Promise<{ accessToken: string; refreshToken: string }> {
    const refreshToken = generateRefreshToken();
    const expiresAt = new Date(
      Date.now() + this.opts.config.AUTH_REFRESH_DAYS * 24 * 60 * 60 * 1000,
    );
    await this.opts.repo.insertRefreshToken(
      userId,
      familyId,
      hashRefreshToken(refreshToken),
      expiresAt,
    );
    const accessToken = await signAccessToken(
      this.opts.keys,
      { sub: userId, email, role },
      this.opts.config.AUTH_ACCESS_TTL_MINUTES,
    );
    return { accessToken, refreshToken };
  }
}
