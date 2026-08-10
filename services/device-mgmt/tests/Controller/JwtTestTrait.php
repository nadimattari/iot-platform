<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\JwksProviderInterface;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Core\Util\Base64UrlSafe;

/**
 * Signs Ed25519 JWTs with a fresh sodium keypair and lets tests override the
 * JwksProvider so the guard trusts that same keypair.
 */
trait JwtTestTrait
{
    /** @var non-empty-string 96-byte sodium keypair (secret || public) */
    private string $keypair;
    /** @var non-empty-string 64-byte secret key */
    private string $secretKey;
    /** @var non-empty-string 32-byte public key */
    private string $publicKey;
    private string $token;

    protected function setUpJwt(): void
    {
        $this->keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($this->keypair);
        $this->publicKey = sodium_crypto_sign_publickey($this->keypair);
        $this->token = $this->signToken(['sub' => 'user-1', 'email' => 'a@b.c', 'role' => 'admin']);
    }

    private function fakeProvider(string $publicKey): JwksProviderInterface
    {
        $jwk = new JWK([
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => Base64UrlSafe::encodeUnpadded($publicKey),
            'use' => 'sig',
            'alg' => 'EdDSA',
        ]);

        return new class(new JWKSet([$jwk])) implements JwksProviderInterface {
            public function __construct(private readonly JWKSet $keySet)
            {
            }

            public function getKeySet(): JWKSet
            {
                return $this->keySet;
            }
        };
    }

    /**
     * @param array<string, mixed> $claims
     * @param non-empty-string     $secretKey 64-byte Ed25519 secret key
     */
    private function signToken(array $claims, ?string $secretKey = null): string
    {
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT'];
        $payload = $claims + ['iat' => time(), 'exp' => time() + 900];
        $encodedHeader = Base64UrlSafe::encodeUnpadded(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = Base64UrlSafe::encodeUnpadded(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        $signature = sodium_crypto_sign_detached($signingInput, $secretKey ?? $this->secretKey);

        return $signingInput.'.'.Base64UrlSafe::encodeUnpadded($signature);
    }
}
