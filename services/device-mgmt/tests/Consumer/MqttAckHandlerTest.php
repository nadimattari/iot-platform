<?php

declare(strict_types=1);

namespace App\Tests\Consumer;

use App\Consumer\MqttAckHandler;
use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\MqttAckEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MqttAckHandlerTest extends TestCase
{
    public function testAckSetsStatusAckedAndClearsError(): void
    {
        $command = $this->command();
        $command->setStatus(CommandStatus::Sent);
        $command->setError('previous failure');

        $commands = $this->repository($command);
        $handler = new MqttAckHandler($this->em(), $commands);
        $handler->handleAck(new MqttAckEvent($command->getId()));

        self::assertSame(CommandStatus::Acked, $command->getStatus());
        self::assertNull($command->getError());
    }

    public function testUnknownCommandIdIsIgnored(): void
    {
        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with('unknown-id')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $handler = new MqttAckHandler($em, $commands);
        $handler->handleAck(new MqttAckEvent('unknown-id'));
    }

    private function em(): EntityManagerInterface
    {
        return $this->createStub(EntityManagerInterface::class);
    }

    private function repository(Command $command): CommandRepository
    {
        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with($command->getId())->willReturn($command);

        return $commands;
    }

    private function command(): Command
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);

        return new Command($device, CommandType::MqttMessage);
    }
}
