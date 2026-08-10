<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DeviceGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DeviceControllerTest extends WebTestCase
{
    use JwtTestTrait;

    private KernelBrowser $client;
    private FakeBrokerCredentialProvisioner $broker;

    protected function setUp(): void
    {
        $this->setUpJwt();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('App\Security\JwksProvider', $this->fakeProvider($this->publicKey));
        $this->broker = new FakeBrokerCredentialProvisioner();
        self::getContainer()->set('App\Service\BrokerCredentialProvisioner', $this->broker);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM devices');
        $conn->executeStatement('DELETE FROM device_groups');
    }

    public function testCreateMqttDeviceReturnsApiKeyOnce(): void
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'pump-1',
            'protocol' => 'mqtt',
            'metadata' => ['floor' => 2],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertArrayHasKey('api_key', $body);
        self::assertStringStartsWith('dk_', $body['api_key']);
        self::assertSame('mqtt', $body['device']['protocol']);
        self::assertSame('pump-1', $body['device']['name']);
        self::assertSame(['floor' => 2], $body['device']['metadata']);
        self::assertTrue($body['device']['enabled']);
        self::assertNull($body['device']['dev_eui']);

        $hash = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Entity\Device::class)
            ->findOneBy(['name' => 'pump-1'])?->getApiKeyHash();
        self::assertSame(hash('sha256', $body['api_key']), $hash);

        self::assertCount(1, $this->broker->provisioned);
        self::assertSame($body['device']['id'], $this->broker->provisioned[0]['device']->getId());
        self::assertSame($body['api_key'], $this->broker->provisioned[0]['password']);
    }

    public function testCreateLoraWanDoesNotProvisionBrokerCredential(): void
    {
        $this->createDevice('lora-1', 'lorawan');

        self::assertSame([], $this->broker->provisioned);
    }

    public function testCreateRejectsUnknownProtocol(): void
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'thing',
            'protocol' => 'wifi',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonBody();
        self::assertStringContainsString('protocol must be one of', $body['error']);
    }

    public function testCreateRequiresName(): void
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'protocol' => 'http',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('name is required.', $this->jsonBody()['error']);
    }

    public function testShowReturnsDevice(): void
    {
        $id = $this->createDevice('sensor-1', 'http');

        $this->client->request('GET', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        $device = $this->jsonBody()['device'];
        self::assertSame($id, $device['id']);
        self::assertSame('sensor-1', $device['name']);
        self::assertSame('http', $device['protocol']);
    }

    public function testShowUnknownDeviceReturns404(): void
    {
        $this->client->request('GET', '/api/v1/devices/00000000-0000-7000-8000-000000000000', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Device not found.', $this->jsonBody()['error']);
    }

    public function testUpdateRenamesDeviceAndAssignsGroup(): void
    {
        $id = $this->createDevice('temp-a', 'mqtt');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $group = new DeviceGroup('sensors');
        $em->persist($group);
        $em->flush();

        $this->client->request('PUT', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'temp-b',
            'group_id' => $group->getId(),
            'metadata' => ['room' => 'lab'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $device = $this->jsonBody()['device'];
        self::assertSame('temp-b', $device['name']);
        self::assertSame($group->getId(), $device['group_id']);
        self::assertSame(['room' => 'lab'], $device['metadata']);
    }

    public function testUpdateUnknownGroupReturns422(): void
    {
        $id = $this->createDevice('temp-a', 'mqtt');

        $this->client->request('PUT', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'group_id' => '00000000-0000-7000-8000-000000000000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('group_id does not exist.', $this->jsonBody()['error']);
    }

    public function testPatchEnabledRequiresBoolean(): void
    {
        $id = $this->createDevice('relay-1', 'modbus');

        $this->client->request('PATCH', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'enabled' => 'yes',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('enabled must be a boolean.', $this->jsonBody()['error']);
    }

    public function testPatchDisablesDevice(): void
    {
        $id = $this->createDevice('relay-1', 'modbus');

        $this->client->request('PATCH', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'enabled' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->jsonBody()['device']['enabled']);
    }

    public function testDeleteRemovesDevice(): void
    {
        $id = $this->createDevice('gone-1', 'mqtt');

        $this->client->request('DELETE', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        self::assertCount(1, $this->broker->revoked);
        self::assertSame($id, $this->broker->revoked[0]->getId());

        $this->client->request('GET', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteLoraWanDoesNotRevokeBrokerCredential(): void
    {
        $id = $this->createDevice('lora-1', 'lorawan');

        $this->client->request('DELETE', '/api/v1/devices/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->broker->revoked);
    }

    public function testClaimLoraWanRequiresDevEui(): void
    {
        $id = $this->createDevice('lorawan-a', 'lorawan');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: '{}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('dev_eui is required for LoRaWAN devices.', $this->jsonBody()['error']);
    }

    public function testClaimLoraWanSetsDevEuiWithoutApiKey(): void
    {
        $id = $this->createDevice('lorawan-a', 'lorawan');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'dev_eui' => 'AABBCCDDEEFF0011',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertArrayNotHasKey('api_key', $body);
        self::assertSame('AABBCCDDEEFF0011', $body['device']['dev_eui']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $device = $em->getRepository(\App\Entity\Device::class)->find($id);
        self::assertSame('AABBCCDDEEFF0011', $device?->getDevEui());
        self::assertNull($device?->getApiKeyHash());
    }

    public function testClaimLoraWanRejectsDuplicateDevEui(): void
    {
        $first = $this->createDevice('lorawan-a', 'lorawan');
        $this->client->request('POST', '/api/v1/devices/'.$first.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'dev_eui' => 'AABBCCDDEEFF0011',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $id = $this->createDevice('lorawan-b', 'lorawan');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'dev_eui' => 'AABBCCDDEEFF0011',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('dev_eui is already in use.', $this->jsonBody()['error']);
    }

    public function testClaimRejectsMalformedDevEui(): void
    {
        $id = $this->createDevice('lorawan-a', 'lorawan');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'dev_eui' => 'not-a-hex-value!',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('dev_eui must be 16 hex characters.', $this->jsonBody()['error']);
    }

    public function testClaimMqttReissuesApiKey(): void
    {
        $id = $this->createDevice('pump-9', 'mqtt');

        $this->client->request('POST', '/api/v1/devices/'.$id.'/claim', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: '{}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertArrayHasKey('api_key', $body);
        self::assertStringStartsWith('dk_', $body['api_key']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $device = $em->getRepository(\App\Entity\Device::class)->find($id);
        self::assertSame(hash('sha256', $body['api_key']), $device?->getApiKeyHash());

        self::assertCount(2, $this->broker->provisioned);
        $last = $this->broker->provisioned[1];
        self::assertSame($id, $last['device']->getId());
        self::assertSame($body['api_key'], $last['password']);
    }

    public function testListFiltersByProtocolAndPaginates(): void
    {
        $this->createDevice('m1', 'mqtt');
        $this->createDevice('m2', 'mqtt');
        $this->createDevice('m3', 'mqtt');
        $this->createDevice('l1', 'lorawan');

        $this->client->request('GET', '/api/v1/devices?protocol=mqtt&page=1&limit=2', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertCount(2, $body['items']);
        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(2, $body['limit']);
        foreach ($body['items'] as $item) {
            self::assertSame('mqtt', $item['protocol']);
        }

        $this->client->request('GET', '/api/v1/devices?protocol=mqtt&page=2&limit=2', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertCount(1, $this->jsonBody()['items']);

        $this->client->request('GET', '/api/v1/devices?protocol=lorawan', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertSame(1, $this->jsonBody()['total']);
    }

    public function testListRejectsUnknownProtocolFilter(): void
    {
        $this->client->request('GET', '/api/v1/devices?protocol=ble', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('protocol must be one of', $this->jsonBody()['error']);
    }

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/devices');

        self::assertResponseStatusCodeSame(401);
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
