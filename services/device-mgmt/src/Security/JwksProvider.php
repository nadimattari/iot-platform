<?php

declare(strict_types=1);

namespace App\Security;

use Jose\Component\Core\JWKSet;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the auth service Ed25519 public key from its JWKS endpoint and
 * caches it. The key is cached in-process (worker mode keeps it warm) with a
 * bounded TTL so key rotation by the auth service is picked up eventually.
 */
final class JwksProvider implements JwksProviderInterface
{
    private const CACHE_KEY = 'auth.jwks';
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $jwksUrl,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    public function getKeySet(): JWKSet
    {
        $json = $this->cache->get(
            self::CACHE_KEY,
            fn (): string => $this->fetch(),
            $this->ttl,
        );

        return JWKSet::createFromJson($json);
    }

    private function fetch(): string
    {
        $response = $this->httpClient->request('GET', $this->jwksUrl, ['timeout' => 5]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('JWKS fetch failed with HTTP %d', $response->getStatusCode()));
        }

        return $response->getContent();
    }
}
