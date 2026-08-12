<?php

declare(strict_types=1);

namespace App\Consumer;

use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Message\DownlinkAckEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies ChirpStack ACK / TxACK events to the correlated command row.
 * Unknown queue items are ignored (e.g. commands enqueued before this
 * consumer existed, or events from other applications).
 */
final class DownlinkAckHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommandRepository $commands,
    ) {
    }

    /**
     * `event/ack`: acknowledged=true → acked; acknowledged=false (timeout) → failed.
     */
    public function handleAck(DownlinkAckEvent $event): ?Command
    {
        $command = $this->commands->findByQueueItemId($event->queueItemId);
        if (null === $command) {
            return null;
        }

        $command->setStatus($event->acknowledged ? CommandStatus::Acked : CommandStatus::Failed);
        if (!$event->acknowledged) {
            $command->setError('Device did not acknowledge the downlink (timeout).');
        }
        $command->touch();
        $this->em->flush();

        return $command;
    }

    /**
     * `event/txack`: gateway accepted the frame for transmission. Only ever
     * promotes a still-pending command to sent; never downgrades an acked one.
     */
    public function handleTxAck(DownlinkAckEvent $event): ?Command
    {
        $command = $this->commands->findByQueueItemId($event->queueItemId);
        if (null === $command) {
            return null;
        }

        if (CommandStatus::Pending === $command->getStatus()) {
            $command->setStatus(CommandStatus::Sent);
            $command->touch();
            $this->em->flush();
        }

        return $command;
    }
}
