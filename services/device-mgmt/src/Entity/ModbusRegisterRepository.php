<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModbusRegister>
 *
 * @method ModbusRegister|null find($id, $lockMode = null, $lockVersion = null)
 */
class ModbusRegisterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModbusRegister::class);
    }

    /**
     * @return ModbusRegister[] ordered by register address
     */
    public function findByDevice(Device $device): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.device = :device')
            ->setParameter('device', $device)
            ->orderBy('r.address', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteForDevice(Device $device): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.device = :device')
            ->setParameter('device', $device)
            ->getQuery()
            ->execute();
    }
}
