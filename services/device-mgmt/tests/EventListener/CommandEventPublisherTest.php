<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Command;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\EventListener\CommandEventPublisher;
use App\Service\MercurePublisherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommandEventPublisherTest extends TestCase
{
    /** @var MercurePublisherInterface&MockObject */
    private $publisher;
    private CommandEventPublisher $subscriber;

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(MercurePublisherInterface::class);
        $this->subscriber = new CommandEventPublisher($this->publisher);
    }

    public function testPublishesInsertedCommand(): void
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);
        $command = new Command($device, CommandType::MqttMessage);

        $this->subscriber->onFlush(new OnFlushEventArgs($this->entityManager($command, insertions: true)));
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with(
                sprintf('/devices/%s/commands', $device->getId()),
                self::callback(fn (array $data) => $command->getId() === $data['command']['id']),
            );
        $this->subscriber->postFlush(new PostFlushEventArgs($this->entityManager()));
    }

    public function testPublishesUpdatedCommandStatus(): void
    {
        $device = new Device('pump-1', DeviceProtocol::Mqtt);
        $command = new Command($device, CommandType::MqttMessage);
        $command->setStatus(CommandStatus::Acked);

        $this->subscriber->onFlush(new OnFlushEventArgs($this->entityManager($command, updates: true)));
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with(
                sprintf('/devices/%s/commands', $device->getId()),
                self::callback(fn (array $data) => 'acked' === $data['command']['status']),
            );
        $this->subscriber->postFlush(new PostFlushEventArgs($this->entityManager()));
    }

    public function testIgnoresNonCommandEntities(): void
    {
        $device = new Device('modbus-1', DeviceProtocol::Modbus);
        $this->subscriber->onFlush(new OnFlushEventArgs($this->entityManager($device, insertions: true)));

        $this->publisher->expects(self::never())->method('publish');
        $this->subscriber->postFlush(new PostFlushEventArgs($this->entityManager()));
    }

    private function entityManager(mixed $entity = null, bool $insertions = false, bool $updates = false): EntityManagerInterface
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions ? [$entity] : []);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates ? [$entity] : []);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return $em;
    }
}
