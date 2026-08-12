<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes events to the Mercure hub for live dashboards. Publish
 * authorization uses a per-topic HS256 JWT (Mercure `publish` claim) so each
 * caller is scoped to exactly the topics it owns. Failures are logged and
 * swallowed: the hub must never break command lifecycle tracking.
 */
final class EventPublisher implements MercurePublisherInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly string $hubUrl,
        private readonly string $jwtKey,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publish(string $topic, array $data): void
    {
        if ('' === $this->hubUrl || '' === $this->jwtKey) {
            return;
        }

        try {
            $response = $this->client->request('POST', $this->hubUrl, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token($topic),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query(['topic' => $topic, 'data' => json_encode($data, JSON_THROW_ON_ERROR)]),
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Mercure publish to {topic} rejected with status {status}', [
                    'topic' => $topic,
                    'status' => $response->getStatusCode(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish to {topic} failed: {message}', [
                'topic' => $topic,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function token(string $topic): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode([
            'mercure' => ['publish' => [$topic]],
            'exp' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->b64(hash_hmac('sha256', $header.'.'.$payload, $this->jwtKey, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
