<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegisterControllerTest extends WebTestCase
{
    use JwtTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->setUpJwt();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('App\Security\JwksProvider', $this->fakeProvider($this->publicKey));
        self::getContainer()->set('App\Service\BrokerCredentialProvisioner', new FakeBrokerCredentialProvisioner());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM modbus_register_config');
        $conn->executeStatement('DELETE FROM devices');
        $conn->executeStatement('DELETE FROM device_groups');
    }

    public function testPutReplacesRegisterSetAndGetRoundTrips(): void
    {
        $id = $this->createDevice();

        $this->client->request('PUT', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'registers' => [
                ['name' => 'temperature', 'address' => 0, 'datatype' => 'float32', 'byteorder' => 'big', 'scale' => 0.1, 'interval_secs' => 5],
                ['name' => 'rpm', 'address' => 2, 'datatype' => 'uint32', 'byteorder' => 'little', 'scale' => 1, 'interval_secs' => 30],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $registers = $this->jsonBody()['registers'];
        self::assertCount(2, $registers);
        self::assertSame('temperature', $registers[0]['name']);
        self::assertSame(0, $registers[0]['address']);
        self::assertSame('float32', $registers[0]['datatype']);
        self::assertSame('big', $registers[0]['byteorder']);
        self::assertSame(0.1, $registers[0]['scale']);
        self::assertSame(5, $registers[0]['interval_secs']);
        self::assertSame('rpm', $registers[1]['name']);

        $this->client->request('GET', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertSame('temperature', $this->jsonBody()['registers'][0]['name']);

        $persisted = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Entity\ModbusRegister::class)
            ->findAll();
        self::assertCount(2, $persisted);
    }

    public function testGetReturnsEmptyListForUnconfiguredDevice(): void
    {
        $id = $this->createDevice();

        $this->client->request('GET', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonBody()['registers']);
    }

    public function testPutReplacesExistingConfiguration(): void
    {
        $id = $this->createDevice();
        $this->putRegisters($id, [['name' => 'a', 'address' => 0, 'datatype' => 'uint16']]);
        $this->putRegisters($id, [['name' => 'b', 'address' => 4, 'datatype' => 'int16']]);

        $this->client->request('GET', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        $registers = $this->jsonBody()['registers'];
        self::assertCount(1, $registers);
        self::assertSame('b', $registers[0]['name']);

        $count = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Entity\ModbusRegister::class)
            ->findAll();
        self::assertCount(1, $count);
    }

    /**
     * @dataProvider invalidPayloadProvider
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testPutRejectsInvalidConfig(array $registers, string $needle): void
    {
        $id = $this->createDevice();

        $this->client->request('PUT', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode(['registers' => $registers], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($needle, $this->jsonBody()['error']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'missing name' => [[['address' => 0, 'datatype' => 'uint16']], 'name is required'];
        yield 'unsupported datatype' => [[['name' => 'x', 'address' => 0, 'datatype' => 'word']], 'datatype must be one of'];
        yield 'unsupported byteorder' => [[['name' => 'x', 'address' => 0, 'datatype' => 'uint16', 'byteorder' => 'sideways']], 'byteorder must be one of'];
        yield 'negative address' => [[['name' => 'x', 'address' => -1, 'datatype' => 'uint16']], 'address must be a non-negative integer'];
        yield 'non-positive scale' => [[['name' => 'x', 'address' => 0, 'datatype' => 'uint16', 'scale' => 0]], 'scale must be a positive number'];
        yield 'non-positive interval' => [[['name' => 'x', 'address' => 0, 'datatype' => 'uint16', 'interval_secs' => 0]], 'interval_secs must be a positive integer'];
        yield 'duplicate names' => [[
            ['name' => 'a', 'address' => 0, 'datatype' => 'uint16'],
            ['name' => 'a', 'address' => 1, 'datatype' => 'uint16'],
        ], 'duplicate register name'];
    }

    public function testPutRejectsNonArrayRegistersField(): void
    {
        $id = $this->createDevice();

        $this->client->request('PUT', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode(['registers' => 'nope'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('registers must be an array', $this->jsonBody()['error']);
    }

    public function testPutRejectsUnknownDevice(): void
    {
        $this->client->request('PUT', '/api/v1/devices/00000000-0000-0000-0000-000000000000/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: '{"registers": []}');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequiresAuthentication(): void
    {
        $id = $this->createDevice();

        $this->client->request('GET', '/api/v1/devices/'.$id.'/registers');

        self::assertResponseStatusCodeSame(401);
    }

    private function createDevice(): string
    {
        $this->client->request('POST', '/api/v1/devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode([
            'name' => 'modbus-dev',
            'protocol' => 'modbus',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody()['device']['id'];
    }

    /**
     * @param list<array<string, mixed>> $registers
     */
    private function putRegisters(string $id, array $registers): void
    {
        $this->client->request('PUT', '/api/v1/devices/'.$id.'/registers', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode(['registers' => $registers], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
