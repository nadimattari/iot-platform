<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Command>
 *
 * @method Command|null find($id, $lockMode = null, $lockVersion = null)
 */
class CommandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Command::class);
    }

    /**
     * Finds a command by the ChirpStack downlink queue item ID (the command
     * UUID we generate and pass as `id` in the DownlinkCommand).
     */
    public function findByQueueItemId(string $queueItemId): ?Command
    {
        return $this->findOneBy(['queueItemId' => $queueItemId]);
    }
}
