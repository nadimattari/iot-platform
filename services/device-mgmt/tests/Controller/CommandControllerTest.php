<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\DownlinkPublisherInterface;
use App\Message\MqttCommandPublisherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommandControllerTest extends WebTestCase
{
    use JwtTestTrait;

    private const APP_ID = '30248d5b-b17c-4a6c-8069-927c17486608';

    private KernelBrowser $client;
    private FakeCommandPublisher $publisher;

    protected function setUp(): void
    {
        $this->setUpJwt();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('App\Security\JwksProvider', $this->fakeProvider($this->publicKey));
        $this->publisher = new FakeCommandPublisher();
        self::getContainer()->set('App\Message\MqttPublisher', $this->publisher);
        self::getContainer()->set('App\Service\BrokerCredentialProvisioner', new FakeBrokerCredentialProvisioner());

        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('DELETE FROM commands');
        $conn->executeStatement('DELETE FROM devices');
        $conn->executeStatement('DELETE FROM device_groups');
    }

    public function testSendMqttCommandReturns201AndPublishesToDeviceDown(): void
    {
        $id = $this->createDevice('pump-1', 'mqtt');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'payload' => ['relay' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $command = $this->jsonBody()['command'];
        self::assertSame($id, $command['device_id']);
        self::assertSame('mqtt_message', $command['type']);
        self::assertSame('sent', $command['status']);
        self::assertSame(['payload' => ['relay' => true]], $command['payload']);
        self::assertSame($command['id'], $command['queue_item_id']);

        self::assertCount(1, $this->publisher->published);
        [$topic, $payload] = $this->publisher->published[0];
        self::assertSame('devices/'.$id.'/down', $topic);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($command['id'], $decoded['id']);
        self::assertSame(['relay' => true], $decoded['payload']);
    }

    public function testSendLoraWanCommandRoutesToChirpStack(): void
    {
        $id = $this->createClaimedLoraWanDevice();

        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'f_port' => 10,
            'confirmed' => true,
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $command = $this->jsonBody()['command'];
        self::assertSame('lorawan_downlink', $command['type']);

        self::assertCount(1, $this->publisher->published);
        self::assertSame(
            'application/'.self::APP_ID.'/device/70B3D5499E320001/command/down',
            $this->publisher->published[0][0],
        );
    }

    public function testSendMqttCommandRequiresPayload(): void
    {
        $id = $this->createDevice('pump-1', 'mqtt');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('payload is required for MQTT commands.', $this->jsonBody()['error']);
        self::assertSame([], $this->publisher->published);
    }

    public function testSendRejectsDisabledDevice(): void
    {
        $id = $this->createDevice('pump-1', 'mqtt');
        $this->client->request('PATCH', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'enabled' => false,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'payload' => ['relay' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Device is disabled.', $this->jsonBody()['error']);
    }

    public function testSendRejectsUnknownDevice(): void
    {
        $this->client->request('POST', '/api/v1/devices/019fe9fe-0000-7000-8000-000000000000/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'payload' => ['relay' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Device not found.', $this->jsonBody()['error']);
    }

    public function testSendRejectsUnsupportedProtocol(): void
    {
        $id = $this->createDevice('modbus-1', 'modbus');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Commands are not supported for modbus devices.', $this->jsonBody()['error']);
    }

    public function testSendRequiresAuthentication(): void
    {
        $id = $this->createDevice('pump-1', 'mqtt');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', content: json_encode([
            'payload' => ['relay' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->publisher->published);
    }

    public function testListCommandsReturnsPaginatedHistory(): void
    {
        $id = $this->createDevice('pump-1', 'mqtt');
        $this->sendMqttCommand($id, ['relay' => true]);
        $this->sendMqttCommand($id, ['relay' => false]);

        $this->client->request('GET', '/api/v1/commands?device_id='.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertCount(2, $body['items']);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(20, $body['limit']);
        self::assertSame('mqtt_message', $body['items'][0]['type']);
        self::assertSame('sent', $body['items'][0]['status']);
    }

    public function testListCommandsFiltersByStatus(): void
    {
        $id = $this->createDevice('pump-1', 'mqtt');
        $this->sendMqttCommand($id, ['relay' => true]);

        $this->client->request('GET', '/api/v1/commands?status=failed', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonBody()['items']);
    }

    public function testListCommandsRejectsInvalidStatus(): void
    {
        $this->client->request('GET', '/api/v1/commands?status=bogus', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('status must be one of: pending, sent, acked, failed', $this->jsonBody()['error']);
    }

    public function testListCommandsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/commands');

        self::assertResponseStatusCodeSame(401);
    }

    private function sendMqttCommand(string $id, array $payload): void
    {
        $this->client->request('POST', '/api/v1/devices/'.$id.'/commands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    private function createClaimedLoraWanDevice(): string
    {
        $id = $this->createDevice('lorawan-a', 'lorawan');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'dev_eui' => '70B3D5499E320001',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        return $id;
    }

    private function createDevice(string $name, string $protocol): string
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => $name,
            'protocol' => $protocol,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody()['device']['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class FakeCommandPublisher implements DownlinkPublisherInterface, MqttCommandPublisherInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $published = [];

    public function publish(string $topic, string $payload): void
    {
        $this->published[] = [$topic, $payload];
    }
}
