<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceGroup>
 *
 * @method DeviceGroup|null find($id, $lockMode = null, $lockVersion = null)
 */
class DeviceGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceGroup::class);
    }
}
