<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EventPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EventPublisherTest extends TestCase
{
    public function testPublishesToMercureWithPerTopicJwt(): void
    {
        $client = new RecordingMockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $publisher = new EventPublisher($client, new NullLogger(), 'http://caddy/.well-known/mercure', 'secret');

        $publisher->publish('/devices/abc/commands', ['command' => ['id' => 'cmd-1']]);

        self::assertCount(1, $client->requests);
        self::assertSame('POST', $client->requests[0]['method']);
        self::assertSame('http://caddy/.well-known/mercure', $client->requests[0]['url']);

        $headers = $client->requests[0]['options']['headers'];
        $authorization = is_array($headers['Authorization'] ?? null)
            ? $headers['Authorization'][0]
            : ($headers['Authorization'] ?? '');
        self::assertStringStartsWith('Bearer ', $authorization);
        [, $payloadB64] = explode('.', substr($authorization, 7));
        $payload = json_decode($this->b64Decode($payloadB64), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['publish' => ['/devices/abc/commands']], $payload['mercure']);
        self::assertGreaterThan(time(), $payload['exp']);

        self::assertStringContainsString('topic=%2Fdevices%2Fabc%2Fcommands', $client->requests[0]['options']['body']);
        self::assertStringContainsString('data=', $client->requests[0]['options']['body']);
    }

    public function testPublishSkipsWhenMercureDisabled(): void
    {
        $client = new RecordingMockHttpClient();
        $publisher = new EventPublisher($client, new NullLogger(), '', '');

        $publisher->publish('/devices/abc/commands', ['command' => ['id' => 'cmd-1']]);

        self::assertCount(0, $client->requests);
    }

    public function testPublishSwallowsHubErrors(): void
    {
        $client = new RecordingMockHttpClient([new MockResponse('unauthorized', ['http_code' => 401])]);
        $publisher = new EventPublisher($client, new NullLogger(), 'http://caddy/.well-known/mercure', 'secret');

        // Must not throw even though the hub rejects the publish.
        $publisher->publish('/devices/abc/commands', ['command' => ['id' => 'cmd-1']]);
        self::assertCount(1, $client->requests);
    }

    private function b64Decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

final class RecordingMockHttpClient extends MockHttpClient
{
    /** @var list<array{method: string, url: string, options: array}> */
    public array $requests = [];

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return parent::request($method, $url, $options);
    }
}
