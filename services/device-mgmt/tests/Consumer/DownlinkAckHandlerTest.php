<?php

declare(strict_types=1);

namespace App\Tests\Consumer;

use App\Consumer\DownlinkAckHandler;
use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\DownlinkAckEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DownlinkAckHandlerTest extends TestCase
{
    public function testAckSetsStatusAcked(): void
    {
        $command = $this->command();
        $commands = $this->repository($command);

        $handler = new DownlinkAckHandler($this->em(), $commands);
        $handler->handleAck(new DownlinkAckEvent($command->getId(), true));

        self::assertSame(CommandStatus::Acked, $command->getStatus());
        self::assertNull($command->getError());
    }

    public function testTimeoutSetsStatusFailed(): void
    {
        $command = $this->command();
        $commands = $this->repository($command);

        $handler = new DownlinkAckHandler($this->em(), $commands);
        $handler->handleAck(new DownlinkAckEvent($command->getId(), false));

        self::assertSame(CommandStatus::Failed, $command->getStatus());
        self::assertSame('Device did not acknowledge the downlink (timeout).', $command->getError());
    }

    public function testTxAckMarksPendingCommandAsSent(): void
    {
        $command = $this->command();
        $commands = $this->repository($command);

        $handler = new DownlinkAckHandler($this->em(), $commands);
        $handler->handleTxAck(new DownlinkAckEvent($command->getId(), false));

        self::assertSame(CommandStatus::Sent, $command->getStatus());
    }

    public function testTxAckDoesNotDowngradeAckedCommand(): void
    {
        $command = $this->command();
        $command->setStatus(CommandStatus::Acked);
        $commands = $this->repository($command);

        $handler = new DownlinkAckHandler($this->em(), $commands);
        $handler->handleTxAck(new DownlinkAckEvent($command->getId(), false));

        self::assertSame(CommandStatus::Acked, $command->getStatus());
    }

    public function testUnknownQueueItemIsIgnored(): void
    {
        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with('00000000-0000-7000-8000-000000000000')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $handler = new DownlinkAckHandler($em, $commands);
        $handler->handleAck(new DownlinkAckEvent('00000000-0000-7000-8000-000000000000', true));
    }

    private function em(): EntityManagerInterface
    {
        return $this->createStub(EntityManagerInterface::class);
    }

    private function repository(?Command $command): CommandRepository
    {
        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with($command?->getId())->willReturn($command);

        return $commands;
    }

    private function command(): Command
    {
        $device = new Device('lorawan-a', DeviceProtocol::LoRaWan);
        $command = new Command($device, CommandType::LoraWanDownlink);

        return $command;
    }
}
