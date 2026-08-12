<?php

declare(strict_types=1);

namespace App\Tests\Consumer;

use App\Consumer\ConsumeMqttAckCommand;
use App\Consumer\MqttAckHandler;
use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConsumeMqttAckCommandTest extends TestCase
{
    /**
     * php-mqtt v2.x subscription callbacks receive the raw message content as
     * a string; the `devices/{id}/ack` payload is parsed into MqttAckEvent and
     * correlated via the command id (queued as the command's queue_item_id).
     */
    public function testCallbackParsesRawStringContent(): void
    {
        $command = new Command($this->createDevice(), CommandType::MqttMessage);
        $command->setStatus(CommandStatus::Sent);

        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with($command->getId())->willReturn($command);

        $handler = new MqttAckHandler($this->createStub(EntityManagerInterface::class), $commands);
        $logger = $this->createStub(LoggerInterface::class);

        $commandUnderTest = new ConsumeMqttAckCommand($handler, $logger, 'mqtt', 1883, 'user', 'pass');

        $this->messageCallback($commandUnderTest)(
            'devices/019fe9fe-c87d-7c6e-a0d9-b7197cd962ba/ack',
            json_encode(['id' => $command->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertSame(CommandStatus::Acked, $command->getStatus());
    }

    private function messageCallback(ConsumeMqttAckCommand $command): \Closure
    {
        $method = new \ReflectionMethod(ConsumeMqttAckCommand::class, 'messageCallback');

        return $method->invoke($command);
    }

    private function createDevice(): Device
    {
        return new Device('pump-1', DeviceProtocol::Mqtt);
    }
}
