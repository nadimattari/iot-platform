<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceGroup;
use App\Entity\DeviceGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/groups')]
final class GroupController extends AbstractController
{
    public function __construct(
        private readonly DeviceGroupRepository $groups,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(
            fn (DeviceGroup $group): array => [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'device_count' => $this->countDevices($group),
                'created_at' => $group->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->groups->findAll(),
        );

        usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return new JsonResponse(['items' => $items, 'total' => \count($items)]);
    }

    private function countDevices(DeviceGroup $group): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Device::class, 'd')
            ->where('d.group = :group')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
