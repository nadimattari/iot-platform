<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Command;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\MqttCommandPublisherInterface;
use App\Service\MqttCommandService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MqttCommandServiceTest extends TestCase
{
    private FakeMqttCommandPublisher $publisher;

    protected function setUp(): void
    {
        $this->publisher = new FakeMqttCommandPublisher();
    }

    public function testSendPublishesToDeviceDownTopicAndPersistsSentCommand(): void
    {
        $device = $this->mqttDevice();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Command::class));
        $em->expects(self::once())->method('flush');

        $service = new MqttCommandService($em, $this->publisher);
        $command = $service->send($device, ['relay' => true]);

        self::assertSame(CommandType::MqttMessage, $command->getType());
        self::assertSame(CommandStatus::Sent, $command->getStatus());
        self::assertSame($command->getId(), $command->getQueueItemId());
        self::assertSame(['payload' => ['relay' => true]], $command->getPayload());

        self::assertCount(1, $this->publisher->published);
        [$topic, $payload] = $this->publisher->published[0];
        self::assertSame('devices/'.$device->getId().'/down', $topic);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($command->getId(), $decoded['id']);
        self::assertSame(['relay' => true], $decoded['payload']);
    }

    public function testSendMarksCommandFailedWhenPublishFails(): void
    {
        $device = $this->mqttDevice();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Command::class));
        $em->expects(self::once())->method('flush');

        $publisher = new class implements MqttCommandPublisherInterface {
            public function publish(string $topic, string $payload): void
            {
                throw new \RuntimeException('broker unreachable');
            }
        };

        $service = new MqttCommandService($em, $publisher);
        $command = $service->send($device, ['relay' => true]);

        self::assertSame(CommandStatus::Failed, $command->getStatus());
        self::assertSame('broker unreachable', $command->getError());
    }

    public function testSendRejectsNonMqttDevice(): void
    {
        $device = new Device('lora-1', DeviceProtocol::LoRaWan);
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new MqttCommandService($em, $this->publisher);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MQTT commands are only supported for MQTT devices.');
        $service->send($device, ['relay' => true]);
    }

    public function testSendRejectsDisabledDevice(): void
    {
        $device = $this->mqttDevice();
        $device->setEnabled(false);
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new MqttCommandService($em, $this->publisher);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Device is disabled.');
        $service->send($device, ['relay' => true]);
    }

    private function mqttDevice(): Device
    {
        return new Device('pump-1', DeviceProtocol::Mqtt);
    }
}

final class FakeMqttCommandPublisher implements MqttCommandPublisherInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $published = [];

    public function publish(string $topic, string $payload): void
    {
        $this->published[] = [$topic, $payload];
    }
}
