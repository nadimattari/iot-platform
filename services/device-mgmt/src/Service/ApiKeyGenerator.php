<?php

declare(strict_types=1);

namespace App\Service;

final class ApiKeyGenerator
{
    public function __construct(private readonly string $prefix = 'dk_')
    {
    }

    /**
     * @return array{plaintext: string, hash: string} The plaintext is only ever
     *                                               returned once, at creation time.
     */
    public function generate(): array
    {
        $plaintext = $this->prefix.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['plaintext' => $plaintext, 'hash' => hash('sha256', $plaintext)];
    }

    public function hash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }
}
