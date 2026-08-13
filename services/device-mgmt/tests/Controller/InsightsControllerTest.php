<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Task 19: insights API over continuous aggregates.
 *
 * Seeds `telemetry.telemetry_points` directly, materializes the continuous
 * aggregates with refresh_continuous_aggregate, and exercises summary and
 * timeseries endpoints.
 */
final class InsightsControllerTest extends WebTestCase
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

    public function testSummaryAggregatesAcrossDevicesInGroup(): void
    {
        $groupId = $this->createGroup('pumps');
        $deviceA = $this->createDevice();
        $deviceB = $this->createDevice();
        $this->attachToGroup($deviceA, $groupId);
        $this->attachToGroup($deviceB, $groupId);

        // A device outside the group must be excluded.
        $outsider = $this->createDevice();
        $this->insertPoint($outsider, 'temperature', 99.0, '2026-08-01T00:00:00Z', 'float');

        $this->insertPoint($deviceA, 'temperature', 20.0, '2026-08-01T00:00:00Z', 'float');
        $this->insertPoint($deviceA, 'temperature', 22.0, '2026-08-02T00:00:00Z', 'float');
        $this->insertPoint($deviceA, 'humidity', 50.0, '2026-08-01T00:00:00Z', 'int');
        $this->insertPoint($deviceB, 'temperature', 24.0, '2026-08-01T00:00:00Z', 'float');
        $this->insertPoint($deviceB, 'temperature', 26.0, '2026-08-02T00:00:00Z', 'float');
        $this->insertPoint($deviceB, 'humidity', 60.0, '2026-08-02T00:00:00Z', 'int');
        $this->refreshAggregates();

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/summary?group_id=%s&from=2026-08-01T00:00:00Z&to=2026-08-03T00:00:00Z', $groupId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertSame($groupId, $body['group_id']);
        self::assertSame('1d', $body['bucket']);

        $fields = [];
        foreach ($body['fields'] as $f) {
            $fields[$f['field']] = $f;
        }
        self::assertSame(['humidity', 'temperature'], array_keys($fields));
        self::assertCount(2, $fields);
        self::assertEquals(20.0, $fields['temperature']['min']);
        self::assertEquals(26.0, $fields['temperature']['max']);
        self::assertEquals(23.0, $fields['temperature']['avg']);
        self::assertSame(4, $fields['temperature']['count']);
        self::assertEquals(50.0, $fields['humidity']['min']);
        self::assertEquals(60.0, $fields['humidity']['max']);
        self::assertEquals(55.0, $fields['humidity']['avg']);
        self::assertSame(2, $fields['humidity']['count']);
    }

    public function testSummaryIsEmptyForGroupWithoutTelemetry(): void
    {
        $groupId = $this->createGroup('empty');
        $device = $this->createDevice();
        $this->attachToGroup($device, $groupId);

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/summary?group_id=%s', $groupId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonBody()['fields']);
    }

    public function testSummaryRequiresGroupId(): void
    {
        $this->client->request('GET', '/api/v1/insights/summary', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSummaryUnknownGroupReturns404(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/summary?group_id=%s', self::DEVICE),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testTimeseriesReturnsBucketedSeries(): void
    {
        $device = $this->createDevice();
        $this->insertPoint($device, 'temperature', 20.0, '2026-08-01T00:00:00Z', 'float');
        $this->insertPoint($device, 'temperature', 22.0, '2026-08-01T01:00:00Z', 'float');
        $this->insertPoint($device, 'temperature', 24.0, '2026-08-01T02:00:00Z', 'float');
        $this->insertPoint($device, 'humidity', 50.0, '2026-08-01T00:30:00Z', 'int');
        $this->insertPoint($device, 'humidity', 60.0, '2026-08-01T01:30:00Z', 'int');
        $this->refreshAggregates();

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/timeseries?device_id=%s&bucket=1h&from=2026-08-01T00:00:00Z&to=2026-08-01T03:00:00Z', $device),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertSame($device, $body['device_id']);
        self::assertSame('1h', $body['bucket']);

        $items = $body['items'];
        self::assertCount(5, $items);

        $temp = array_values(array_filter($items, static fn (array $i): bool => 'temperature' === $i['field']));
        self::assertCount(3, $temp);
        self::assertSame(1, $temp[0]['count']);
        self::assertEquals(20.0, $temp[0]['min']);
        self::assertEquals(22.0, $temp[1]['max']);
        self::assertEquals(24.0, $temp[2]['max']);
        self::assertSame('2026-08-01T00:00:00+00:00', $temp[0]['bucket']);
    }

    public function testTimeseriesOutOfRangeReturnsEmpty(): void
    {
        $device = $this->createDevice();
        $this->insertPoint($device, 'temperature', 20.0, '2026-08-01T00:00:00Z', 'float');
        $this->refreshAggregates();

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/timeseries?device_id=%s&bucket=1h&from=2030-01-01T00:00:00Z&to=2030-02-01T00:00:00Z', $device),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonBody()['items']);
    }

    public function testTimeseriesRejectsInvalidBucketAndDates(): void
    {
        $device = $this->createDevice();

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/timeseries?device_id=%s&bucket=fortnight', $device),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(422);

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/timeseries?device_id=%s&bucket=1h&from=not-a-date', $device),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(422);

        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/timeseries?device_id=%s&bucket=1h&from=2026-08-02T00:00:00Z&to=2026-08-01T00:00:00Z', $device),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testTimeseriesUnknownDeviceReturns404(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/insights/timeseries?device_id=%s&bucket=1h', self::DEVICE),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testTimeseriesRequiresDeviceId(): void
    {
        $this->client->request('GET', '/api/v1/insights/timeseries?bucket=1h', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEndpointsRequireAuth(): void
    {
        $this->client->request('GET', '/api/v1/insights/summary?group_id=abc');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/insights/timeseries?device_id=abc');
        self::assertResponseStatusCodeSame(401);
    }

    private function refreshAggregates(): void
    {
        foreach (['telemetry_1m', 'telemetry_1h', 'telemetry_1d'] as $view) {
            $this->conn->executeStatement(
                sprintf("CALL refresh_continuous_aggregate('telemetry.%s', '2000-01-01', '2100-01-01')", $view),
            );
        }
    }

    private function insertPoint(string $deviceId, string $field, float $value, string $time, string $type): void
    {
        $this->conn->executeStatement(
            'INSERT INTO telemetry.telemetry_points (time, device_id, field, value, type, quality)
             VALUES (:time, :device_id, :field, :value, :type, 0)',
            ['time' => $time, 'device_id' => $deviceId, 'field' => $field, 'value' => $value, 'type' => $type],
        );
    }

    private function createDevice(): string
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'insights-device-'.substr((string) Uuid::v4(), 0, 8),
            'protocol' => 'mqtt',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody()['device']['id'];
    }

    private function createGroup(string $name): string
    {
        $id = (string) Uuid::v4();
        $this->conn->executeStatement(
            'INSERT INTO device_groups (id, name, created_at) VALUES (:id, :name, NOW())',
            ['id' => $id, 'name' => $name],
        );

        return $id;
    }

    private function attachToGroup(string $deviceId, string $groupId): void
    {
        $this->conn->executeStatement(
            'UPDATE devices SET group_id = :group_id WHERE id = :device_id',
            ['group_id' => $groupId, 'device_id' => $deviceId],
        );
    }

    private function jsonBody(): array
    {
        $json = $this->client->getResponse()->getContent();
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
