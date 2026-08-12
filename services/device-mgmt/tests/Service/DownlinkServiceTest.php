<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Command;
use App\Entity\CommandStatus;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\DownlinkPublisherInterface;
use App\Service\DownlinkService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DownlinkServiceTest extends TestCase
{
    private const APP_ID = '30248d5b-b17c-4a6c-8069-927c17486608';

    private FakeDownlinkPublisher $publisher;

    protected function setUp(): void
    {
        $this->publisher = new FakeDownlinkPublisher();
    }

    public function testEnqueuePublishesToChirpStackTopicAndPersistsSentCommand(): void
    {
        $device = $this->loraWanDevice();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Command::class));
        $em->expects(self::once())->method('flush');

        $service = new DownlinkService($em, $this->publisher, self::APP_ID);
        $command = $service->enqueue($device, 10, true, 'aGVsbG8=');

        self::assertSame(CommandStatus::Sent, $command->getStatus());
        self::assertSame($command->getId(), $command->getQueueItemId());
        self::assertCount(1, $this->publisher->published);
        [$topic, $payload] = $this->publisher->published[0];
        self::assertSame(
            'application/'.self::APP_ID.'/device/70B3D5499E320001/command/down',
            $topic,
        );
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($command->getId(), $decoded['id']);
        self::assertSame('70B3D5499E320001', $decoded['devEui']);
        self::assertTrue($decoded['confirmed']);
        self::assertSame(10, $decoded['fPort']);
        self::assertSame('aGVsbG8=', $decoded['data']);
        self::assertArrayNotHasKey('object', $decoded);
    }

    public function testEnqueueWithObjectPayload(): void
    {
        $device = $this->loraWanDevice();
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new DownlinkService($em, $this->publisher, self::APP_ID);
        $command = $service->enqueue($device, 5, false, null, ['relay' => true]);

        self::assertSame(['object' => ['relay' => true]], $command->getPayload());
        $decoded = json_decode($this->publisher->published[0][1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['relay' => true], $decoded['object']);
        self::assertFalse($decoded['confirmed']);
        self::assertSame(5, $decoded['fPort']);
        self::assertArrayNotHasKey('data', $decoded);
    }

    public function testEnqueueRejectsNonLoraWanDevice(): void
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new DownlinkService($em, $this->publisher, self::APP_ID);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Downlinks are only supported for LoRaWAN devices.');
        $service->enqueue($device, 10, false, 'aGVsbG8=');
    }

    public function testEnqueueRejectsUnclaimedDevice(): void
    {
        $device = new Device('lora-1', DeviceProtocol::LoRaWan);
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new DownlinkService($em, $this->publisher, self::APP_ID);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Device has no dev_eui; claim it first.');
        $service->enqueue($device, 10, false, 'aGVsbG8=');
    }

    public function testEnqueueRejectsDisabledDevice(): void
    {
        $device = $this->loraWanDevice();
        $device->setEnabled(false);
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new DownlinkService($em, $this->publisher, self::APP_ID);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Device is disabled.');
        $service->enqueue($device, 10, false, 'aGVsbG8=');
    }

    public function testEnqueueMarksCommandFailedWhenPublishFails(): void
    {
        $device = $this->loraWanDevice();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Command::class));
        $em->expects(self::once())->method('flush');

        $publisher = new class implements DownlinkPublisherInterface {
            public function publish(string $topic, string $payload): void
            {
                throw new \RuntimeException('broker unreachable');
            }
        };

        $service = new DownlinkService($em, $publisher, self::APP_ID);
        $command = $service->enqueue($device, 10, false, 'aGVsbG8=');

        self::assertSame(CommandStatus::Failed, $command->getStatus());
        self::assertSame('broker unreachable', $command->getError());
    }

    private function loraWanDevice(): Device
    {
        $device = new Device('lorawan-a', DeviceProtocol::LoRaWan);
        $device->setDevEui('70B3D5499E320001');

        return $device;
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
