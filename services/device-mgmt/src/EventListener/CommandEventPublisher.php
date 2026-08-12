<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Command;
use App\Service\CommandSerializer;
use App\Service\MercurePublisherInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Publishes a Mercure event to `/devices/{deviceId}/commands` whenever a
 * command is created or its status changes (enqueue -> sent, ack -> acked,
 * timeout -> failed). A single hook catches every writer instead of sprinkling
 * publish calls through the services and consumers.
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
final class CommandEventPublisher
{
    /** @var list<Command> */
    private array $changed = [];

    public function __construct(
        private readonly MercurePublisherInterface $publisher,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Command) {
                $this->changed[] = $entity;
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Command) {
                $this->changed[] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->changed as $command) {
            $this->publisher->publish(
                sprintf('/devices/%s/commands', $command->getDevice()->getId()),
                ['command' => CommandSerializer::serialize($command)],
            );
        }
        $this->changed = [];
    }
}
