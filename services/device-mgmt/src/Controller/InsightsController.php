<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceGroup;
use App\Entity\DeviceGroupRepository;
use App\Entity\DeviceRepository;
use App\Service\InsightsReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/insights')]
final class InsightsController extends AbstractController
{
    private const DEFAULT_SUMMARY_WINDOW = '-30 days';
    private const DEFAULT_SERIES_WINDOW = '-24 hours';

    public function __construct(
        private readonly InsightsReader $reader,
        private readonly DeviceRepository $devices,
        private readonly DeviceGroupRepository $groups,
    ) {
    }

    #[Route('/summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $group = $this->findGroup((string) $request->query->get('group_id', ''));
        [$from, $to] = $this->window($request, self::DEFAULT_SUMMARY_WINDOW);

        return new JsonResponse([
            'group_id' => $group->getId(),
            'bucket' => '1d',
            'from' => $from->format('Y-m-d\TH:i:sP'),
            'to' => $to->format('Y-m-d\TH:i:sP'),
            'fields' => $this->reader->groupSummary($group->getId(), $from, $to),
        ]);
    }

    #[Route('/timeseries', methods: ['GET'])]
    public function timeseries(Request $request): JsonResponse
    {
        $device = $this->findDevice((string) $request->query->get('device_id', ''));

        $bucket = (string) $request->query->get('bucket', '1m');
        if (!isset(InsightsReader::BUCKETS[$bucket])) {
            throw new \InvalidArgumentException('bucket must be one of: '.implode(', ', array_keys(InsightsReader::BUCKETS)).'.');
        }

        [$from, $to] = $this->window($request, self::DEFAULT_SERIES_WINDOW);

        return new JsonResponse([
            'device_id' => $device->getId(),
            'bucket' => $bucket,
            'from' => $from->format('Y-m-d\TH:i:sP'),
            'to' => $to->format('Y-m-d\TH:i:sP'),
            'items' => $this->reader->timeseries($device->getId(), $from, $to, $bucket),
        ]);
    }

    private function findGroup(string $id): DeviceGroup
    {
        if ('' === $id) {
            throw new \InvalidArgumentException('group_id is required.');
        }

        $group = $this->groups->find($id);
        if (!$group instanceof DeviceGroup) {
            throw new NotFoundHttpException('Group not found.');
        }

        return $group;
    }

    private function findDevice(string $id): Device
    {
        if ('' === $id) {
            throw new \InvalidArgumentException('device_id is required.');
        }

        $device = $this->devices->find($id);
        if (!$device instanceof Device) {
            throw new NotFoundHttpException('Device not found.');
        }

        return $device;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function window(Request $request, string $defaultFrom): array
    {
        $from = $this->date($request->query->get('from'), $defaultFrom);
        $to = $this->date($request->query->get('to'), 'now');
        if ($from >= $to) {
            throw new \InvalidArgumentException('from must be before to.');
        }

        return [$from, $to];
    }

    private function date(?string $raw, string $default): \DateTimeImmutable
    {
        $value = (null === $raw || '' === $raw) ? $default : $raw;
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('invalid date/time: %s', (string) $raw));
        }
    }
}
