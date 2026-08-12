<?php

declare(strict_types=1);

namespace App\Consumer;

use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Message\MqttAckEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies `devices/{deviceId}/ack` messages to the correlated command row
 * (the device echoes the command id published on `devices/{deviceId}/down`).
 * Unknown command ids are ignored (e.g. stale acks or foreign messages).
 */
final class MqttAckHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommandRepository $commands,
    ) {
    }

    public function handleAck(MqttAckEvent $event): ?Command
    {
        $command = $this->commands->findByQueueItemId($event->commandId);
        if (null === $command) {
            return null;
        }

        $command->setStatus(CommandStatus::Acked);
        $command->setError(null);
        $command->touch();
        $this->em->flush();

        return $command;
    }
}
