<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 *
 * @method Device|null find($id, $lockMode = null, $lockVersion = null)
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    public function findByDevEui(string $devEui): ?Device
    {
        return $this->findOneBy(['devEui' => $devEui]);
    }

    /**
     * @return array{items: Device[], total: int, page: int, limit: int}
     */
    public function search(?DeviceProtocol $protocol, int $page, int $limit): array
    {
        $base = $this->createQueryBuilder('d');

        if (null !== $protocol) {
            $base->andWhere('d.protocol = :protocol')->setParameter('protocol', $protocol);
        }

        $total = (int) (clone $base)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->leftJoin('d.group', 'g')
            ->addSelect('g')
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
