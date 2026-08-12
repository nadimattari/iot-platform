<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\DownlinkPublisherInterface;
use App\Message\MqttCommandPublisherInterface;
use App\Service\CommandService;
use App\Service\DownlinkService;
use App\Service\MqttCommandService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CommandServiceTest extends TestCase
{
    private const APP_ID = '30248d5b-b17c-4a6c-8069-927c17486608';

    private FakeCommandPublishers $publishers;

    protected function setUp(): void
    {
        $this->publishers = new FakeCommandPublishers();
    }

    public function testSendRoutesLoraWanToChirpStackDownlink(): void
    {
        $device = $this->loraWanDevice();
        $command = $this->service()->send($device, [
            'f_port' => 10,
            'confirmed' => true,
            'data' => 'aGVsbG8=',
        ]);

        self::assertSame(CommandType::LoraWanDownlink, $command->getType());
        self::assertSame(CommandStatus::Sent, $command->getStatus());
        self::assertCount(1, $this->publishers->downlinks);
        self::assertSame(
            'application/'.self::APP_ID.'/device/70B3D5499E320001/command/down',
            $this->publishers->downlinks[0][0],
        );
        self::assertSame([], $this->publishers->mqttCommands);
    }

    public function testSendDefaultsFPortAndConfirmedForLoraWan(): void
    {
        $device = $this->loraWanDevice();
        $command = $this->service()->send($device, ['data' => 'aGVsbG8=']);

        self::assertSame(10, $command->getFPort());
        self::assertFalse($command->isConfirmed());
    }

    public function testSendRoutesMqttToDeviceDownTopic(): void
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);
        $command = $this->service()->send($device, ['payload' => ['relay' => true]]);

        self::assertSame(CommandType::MqttMessage, $command->getType());
        self::assertSame(CommandStatus::Sent, $command->getStatus());
        self::assertSame($command->getId(), $command->getQueueItemId());
        self::assertSame(['payload' => ['relay' => true]], $command->getPayload());
        self::assertCount(1, $this->publishers->mqttCommands);
        [$topic, $payload] = $this->publishers->mqttCommands[0];
        self::assertSame('devices/'.$device->getId().'/down', $topic);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($command->getId(), $decoded['id']);
        self::assertSame(['relay' => true], $decoded['payload']);
        self::assertSame([], $this->publishers->downlinks);
    }

    public function testSendRejectsMqttWithoutPayload(): void
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload is required for MQTT commands.');
        $this->service()->send($device, []);
    }

    public function testSendRejectsUnsupportedProtocol(): void
    {
        $device = new Device('modbus-1', DeviceProtocol::Modbus);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Commands are not supported for modbus devices.');
        $this->service()->send($device, []);
    }

    public function testSendRejectsDisabledDevice(): void
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);
        $device->setEnabled(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Device is disabled.');
        $this->service()->send($device, ['payload' => ['relay' => true]]);
    }

    private function service(): CommandService
    {
        $em = $this->createStub(EntityManagerInterface::class);

        return new CommandService(
            new DownlinkService($em, $this->publishers, self::APP_ID),
            new MqttCommandService($em, $this->publishers),
            $this->createStub(CommandRepository::class),
        );
    }

    private function loraWanDevice(): Device
    {
        $device = new Device('lorawan-a', DeviceProtocol::LoRaWan);
        $device->setDevEui('70B3D5499E320001');

        return $device;
    }
}

final class FakeCommandPublishers implements DownlinkPublisherInterface, MqttCommandPublisherInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $downlinks = [];

    /** @var list<array{0: string, 1: string}> */
    public array $mqttCommands = [];

    public function publish(string $topic, string $payload): void
    {
        if (str_starts_with($topic, 'application/')) {
            $this->downlinks[] = [$topic, $payload];
        } else {
            $this->mqttCommands[] = [$topic, $payload];
        }
    }
}
