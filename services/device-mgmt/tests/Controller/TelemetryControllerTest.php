<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Task 13: telemetry read API.
 *
 * Seeds the TimescaleDB `telemetry.telemetry_points` table directly (the
 * ingestion service writes it in production) and exercises the read endpoints.
 */
final class TelemetryControllerTest extends WebTestCase
{
    use JwtTestTrait;

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $conn;

    private const DEVICE = '019feb7d-042f-7b39-a521-506bddd3e243';

    protected function setUp(): void
    {
        $this->setUpJwt();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('App\Security\JwksProvider', $this->fakeProvider($this->publicKey));
        self::getContainer()->set('App\Service\BrokerCredentialProvisioner', new FakeBrokerCredentialProvisioner());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM telemetry.telemetry_points');
        $conn->executeStatement('DELETE FROM telemetry.telemetry_raw');
        $conn->executeStatement('DELETE FROM modbus_register_config');
        $conn->executeStatement('DELETE FROM devices');
        $conn->executeStatement('DELETE FROM device_groups');
        $this->conn = $conn;
    }

    public function testTelemetrySeriesBucketsWithStats(): void
    {
        $id = $this->createDevice();
        // Two fields, three samples each, one hour apart.
        $this->insertPoint($id, 'temperature', 20.0, '2026-08-01T00:00:00Z', 'float');
        $this->insertPoint($id, 'temperature', 22.0, '2026-08-01T01:00:00Z', 'float');
        $this->insertPoint($id, 'temperature', 24.0, '2026-08-01T02:00:00Z', 'float');
        $this->insertPoint($id, 'humidity', 50.0, '2026-08-01T00:30:00Z', 'int');
        $this->insertPoint($id, 'humidity', 60.0, '2026-08-01T01:30:00Z', 'int');
        $this->insertPoint($id, 'humidity', 70.0, '2026-08-01T02:30:00Z', 'int');

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/telemetry?from=2026-08-01T00:00:00Z&to=2026-08-01T03:00:00Z&resolution=1h', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        $points = $this->jsonBody()['points'];
        self::assertCount(6, $points);

        $tempByBucket = [];
        foreach ($points as $p) {
            if ('temperature' === $p['field']) {
                $tempByBucket[$p['bucket']] = $p;
            }
        }
        self::assertSame(1, $tempByBucket['2026-08-01T00:00:00+00:00']['count']);
        self::assertEquals(20.0, $tempByBucket['2026-08-01T00:00:00+00:00']['min']);
        self::assertEquals(20.0, $tempByBucket['2026-08-01T00:00:00+00:00']['max']);
        self::assertEquals(24.0, $tempByBucket['2026-08-01T02:00:00+00:00']['max']);
    }

    public function testTelemetryCountsMultipleSamplesPerBucket(): void
    {
        $id = $this->createDevice();
        // 6 samples within one 1h bucket, but only 1 when bucketed at 1d.
        for ($i = 0; $i < 6; ++$i) {
            $this->insertPoint($id, 'voltage', 220.0 + $i, sprintf('2026-08-01T0%d:00:00Z', $i + 1), 'int');
        }

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/telemetry?from=2026-08-01T00:00:00Z&to=2026-08-02T00:00:00Z&resolution=1d', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        $points = $this->jsonBody()['points'];
        self::assertCount(1, $points);
        self::assertSame(6, $points[0]['count']);
        self::assertEquals(220.0, $points[0]['min']);
        self::assertEquals(225.0, $points[0]['max']);
        self::assertEquals(222.5, $points[0]['avg']);
    }

    public function testTelemetryDefaultsToLast24h(): void
    {
        $id = $this->createDevice();
        $this->insertPoint($id, 'temperature', 20.0, '2026-08-01T00:00:00Z', 'float');

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/telemetry', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonBody()['points']);
    }

    public function testTelemetryRejectsInvalidResolutionAndDates(): void
    {
        $id = $this->createDevice();

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/telemetry?resolution=fortnight', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(422);

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/telemetry?from=not-a-date', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(422);

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/telemetry?from=2026-08-02T00:00:00Z&to=2026-08-01T00:00:00Z', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testLastReturnsLatestValuePerField(): void
    {
        $id = $this->createDevice();
        $this->insertPoint($id, 'temperature', 20.0, '2026-08-01T00:00:00Z', 'float');
        $this->insertPoint($id, 'temperature', 22.0, '2026-08-01T01:00:00Z', 'float');
        $this->insertPoint($id, 'humidity', 50.0, '2026-08-01T00:30:00Z', 'int');

        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/last', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        $last = $this->jsonBody()['last'];
        self::assertCount(2, $last);
        self::assertEquals(22.0, $last['temperature']['value']);
        self::assertSame('2026-08-01T01:00:00+00:00', $last['temperature']['time']);
        self::assertEquals(50.0, $last['humidity']['value']);
    }

    public function testLastIsEmptyForUnknownDeviceWithNoTelemetry(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/devices/%s/last', self::DEVICE),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testStatusReflectsOnlineWindowFromMetadata(): void
    {
        $id = $this->createDevice(['heartbeat_secs' => 60]);

        // Fresh sample -> online.
        $this->insertPoint($id, 'temperature', 21.0, gmdate('Y-m-d\TH:i:s', time() - 30).'Z', 'float');
        $this->client->request('GET', sprintf('/api/v1/devices/%s/status', $id), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseStatusCodeSame(200);
        $status = $this->jsonBody();
        self::assertTrue($status['online']);
        self::assertSame(60, $status['heartbeat_secs']);

        // Old sample -> offline.
        $this->conn->executeStatement('DELETE FROM telemetry.telemetry_points');
        $this->insertPoint($id, 'temperature', 21.0, gmdate('Y-m-d\TH:i:s', time() - 600).'Z', 'float');
        $this->client->request('GET', sprintf('/api/v1/devices/%s/status', $id), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertFalse($this->jsonBody()['online']);
    }

    public function testStatusOfflineWithoutTelemetry(): void
    {
        $id = $this->createDevice();
        $this->client->request('GET', sprintf('/api/v1/devices/%s/status', $id), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->jsonBody()['online']);
        self::assertNull($this->jsonBody()['last_seen']);
    }

    public function testEndpointsRequireAuth(): void
    {
        $id = $this->createDevice();
        $this->client->request('GET', sprintf('/api/v1/devices/%s/telemetry', $id));
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', sprintf('/api/v1/devices/%s/last', $id));
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', sprintf('/api/v1/devices/%s/status', $id));
        self::assertResponseStatusCodeSame(401);
    }

    private function insertPoint(string $deviceId, string $field, float $value, string $time, string $type): void
    {
        $this->conn->executeStatement(
            'INSERT INTO telemetry.telemetry_points (time, device_id, field, value, type, quality)
             VALUES (:time, :device_id, :field, :value, :type, 0)',
            ['time' => $time, 'device_id' => $deviceId, 'field' => $field, 'value' => $value, 'type' => $type],
        );
    }

    private function createDevice(array $metadata = []): string
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'telemetry-1',
            'protocol' => 'mqtt',
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody()['device']['id'];
    }

    private function jsonBody(): array
    {
        $json = $this->client->getResponse()->getContent();
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
