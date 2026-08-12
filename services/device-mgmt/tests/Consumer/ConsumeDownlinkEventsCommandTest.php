<?php

declare(strict_types=1);

namespace App\Tests\Consumer;

use App\Consumer\ConsumeDownlinkEventsCommand;
use App\Consumer\DownlinkAckHandler;
use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConsumeDownlinkEventsCommandTest extends TestCase
{
    /**
     * php-mqtt v2.x subscription callbacks receive the raw message content as
     * a string (not a Message object); passing that string straight into the
     * handler must update the command status (regression: the callback used to
     * type its second parameter as `Message` and fatally failed at runtime).
     */
    public function testCallbackParsesRawStringContent(): void
    {
        $command = new Command($this->createDevice(), CommandType::LoraWanDownlink);
        $command->setStatus(CommandStatus::Pending);

        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with($command->getId())->willReturn($command);

        $em = $this->createStub(EntityManagerInterface::class);

        $handler = new DownlinkAckHandler($em, $commands);
        $logger = $this->createStub(LoggerInterface::class);

        $commandUnderTest = new ConsumeDownlinkEventsCommand($handler, $logger, 'mqtt', 1883, 'user', 'pass', 'app-id');

        $callback = $this->messageCallback($commandUnderTest);

        // The exact shape ChirpStack v4 emits on `event/ack`.
        $callback(
            'application/app-id/device/70b3d5499e320001/event/ack',
            json_encode([
                'queueItemId' => $command->getId(),
                'acknowledged' => true,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(CommandStatus::Acked, $command->getStatus());
    }

    public function testTxAckPromotesPendingToSent(): void
    {
        $command = new Command($this->createDevice(), CommandType::LoraWanDownlink);
        $command->setStatus(CommandStatus::Pending);

        $commands = $this->createMock(CommandRepository::class);
        $commands->expects(self::once())->method('findByQueueItemId')->with($command->getId())->willReturn($command);

        $handler = new DownlinkAckHandler($this->createStub(EntityManagerInterface::class), $commands);
        $logger = $this->createStub(LoggerInterface::class);

        $commandUnderTest = new ConsumeDownlinkEventsCommand($handler, $logger, 'mqtt', 1883, 'user', 'pass', 'app-id');

        $this->messageCallback($commandUnderTest)(
            'application/app-id/device/70b3d5499e320001/event/txack',
            json_encode([
                'queueItemId' => $command->getId(),
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(CommandStatus::Sent, $command->getStatus());
    }

    private function messageCallback(ConsumeDownlinkEventsCommand $command): \Closure
    {
        $method = new \ReflectionMethod(ConsumeDownlinkEventsCommand::class, 'messageCallback');

        return $method->invoke($command);
    }

    private function createDevice(): Device
    {
        return new Device('lorawan-a', DeviceProtocol::LoRaWan);
    }
}
