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

    /**
     * Paginated command history, newest first, optionally filtered by device
     * and status.
     *
     * @return array{items: list<Command>, total: int, page: int, limit: int}
     */
    public function search(?Device $device, ?CommandStatus $status, int $page, int $limit): array
    {
        $base = $this->createQueryBuilder('c');

        if (null !== $device) {
            $base->andWhere('c.device = :device')->setParameter('device', $device);
        }
        if (null !== $status) {
            $base->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $total = (int) (clone $base)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->addSelect('d')
            ->leftJoin('c.device', 'd')
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
