<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\DownlinkPublisherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DownlinkControllerTest extends WebTestCase
{
    use JwtTestTrait;

    private const APP_ID = '30248d5b-b17c-4a6c-8069-927c17486608';

    private KernelBrowser $client;
    private FakeDownlinkPublisher $publisher;

    protected function setUp(): void
    {
        $this->setUpJwt();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('App\Security\JwksProvider', $this->fakeProvider($this->publicKey));
        $this->publisher = new FakeDownlinkPublisher();
        self::getContainer()->set('App\Message\MqttPublisher', $this->publisher);
        self::getContainer()->set('App\Service\BrokerCredentialProvisioner', new FakeBrokerCredentialProvisioner());

        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('DELETE FROM commands');
        $conn->executeStatement('DELETE FROM devices');
        $conn->executeStatement('DELETE FROM device_groups');
    }

    public function testDownlinkOnClaimedLoraWanDeviceReturns201AndPublishes(): void
    {
        $id = $this->createClaimedDevice('lorawan-a');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'f_port' => 10,
            'confirmed' => true,
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $command = $this->jsonBody()['command'];
        self::assertSame($id, $command['device_id']);
        self::assertSame('lorawan_downlink', $command['type']);
        self::assertSame('sent', $command['status']);
        self::assertSame(['data' => 'aGVsbG8='], $command['payload']);
        self::assertSame(10, $command['f_port']);
        self::assertTrue($command['confirmed']);
        self::assertSame($command['id'], $command['queue_item_id']);

        self::assertCount(1, $this->publisher->published);
        [$topic, $payload] = $this->publisher->published[0];
        self::assertSame('application/'.self::APP_ID.'/device/70B3D5499E320001/command/down', $topic);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('70B3D5499E320001', $decoded['devEui']);
        self::assertSame('aGVsbG8=', $decoded['data']);
    }

    public function testDownlinkWithObjectPayload(): void
    {
        $id = $this->createClaimedDevice('lorawan-a');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'f_port' => 5,
            'object' => ['relay' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['object' => ['relay' => true]], $this->jsonBody()['command']['payload']);
        $decoded = json_decode($this->publisher->published[0][1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['relay' => true], $decoded['object']);
    }

    public function testDownlinkRequiresExactlyOneOfDataOrObject(): void
    {
        $id = $this->createClaimedDevice('lorawan-a');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'data' => 'aGVsbG8=',
            'object' => ['relay' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Provide exactly one of data (base64) or object.', $this->jsonBody()['error']);
        self::assertSame([], $this->publisher->published);
    }

    public function testDownlinkRejectsNonLoraWanDevice(): void
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'pump-1',
            'protocol' => 'mqtt',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody()['device']['id'];

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Downlinks are only supported for LoRaWAN devices.', $this->jsonBody()['error']);
    }

    public function testDownlinkRejectsUnclaimedDevice(): void
    {
        $id = $this->createDevice('lorawan-b');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Device has no dev_eui; claim it first.', $this->jsonBody()['error']);
    }

    public function testDownlinkRejectsDisabledDevice(): void
    {
        $id = $this->createClaimedDevice('lorawan-a');
        $this->client->request('PATCH', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'enabled' => false,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Device is disabled.', $this->jsonBody()['error']);
    }

    public function testDownlinkRejectsOutOfRangeFPort(): void
    {
        $id = $this->createClaimedDevice('lorawan-a');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'f_port' => 0,
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('f_port must be between 1 and 255.', $this->jsonBody()['error']);
    }

    public function testDownlinkRequiresAuthentication(): void
    {
        $id = $this->createClaimedDevice('lorawan-a');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/downlink', content: json_encode([
            'data' => 'aGVsbG8=',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->publisher->published);
    }

    private function createClaimedDevice(string $name): string
    {
        $id = $this->createDevice($name);

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'dev_eui' => '70B3D5499E320001',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        return $id;
    }

    private function createDevice(string $name): string
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => $name,
            'protocol' => 'lorawan',
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

final class FakeDownlinkPublisher implements DownlinkPublisherInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $published = [];

    public function publish(string $topic, string $payload): void
    {
        $this->published[] = [$topic, $payload];
    }
}
