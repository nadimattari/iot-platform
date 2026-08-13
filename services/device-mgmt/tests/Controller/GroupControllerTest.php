<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DeviceGroup;
use App\Entity\DeviceProtocol;
use App\Entity\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GroupControllerTest extends WebTestCase
{
    use JwtTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->setUpJwt();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('App\Security\JwksProvider', $this->fakeProvider($this->publicKey));

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM commands');
        $conn->executeStatement('DELETE FROM devices');
        $conn->executeStatement('DELETE FROM device_groups');
    }

    public function testListGroupsReturnsNameAndDeviceCount(): void
    {
        $group = new DeviceGroup('plant-a');
        $this->em->persist($group);

        $devices = self::getContainer()->get(DeviceRepository::class);
        $device = new \App\Entity\Device('pump-1', DeviceProtocol::Mqtt);
        $device->setGroup($group);
        $this->em->persist($device);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/groups', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonBody();
        self::assertSame(1, $body['total']);
        self::assertSame('plant-a', $body['items'][0]['name']);
        self::assertSame($group->getId(), $body['items'][0]['id']);
        self::assertSame(1, $body['items'][0]['device_count']);
        self::assertArrayHasKey('created_at', $body['items'][0]);
    }

    public function testListGroupsSortsByName(): void
    {
        $this->em->persist(new DeviceGroup('zebra'));
        $this->em->persist(new DeviceGroup('alpha'));
        $this->em->flush();

        $this->client->request('GET', '/api/v1/groups', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseStatusCodeSame(200);
        $names = array_column($this->jsonBody()['items'], 'name');
        self::assertSame(['alpha', 'zebra'], $names);
    }

    public function testListGroupsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/groups');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
