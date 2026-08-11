<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceRepository;
use App\Service\TelemetryReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/devices/{id}')]
final class TelemetryController extends AbstractController
{
    private const DEFAULT_WINDOW = '-24 hours';

    public function __construct(
        private readonly TelemetryReader $reader,
        private readonly DeviceRepository $devices,
    ) {
    }

    #[Route('/telemetry', methods: ['GET'])]
    public function telemetry(string $id, Request $request): JsonResponse
    {
        $device = $this->find($id);

        $from = $this->date($request->query->get('from'), self::DEFAULT_WINDOW);
        $to = $this->date($request->query->get('to'), 'now');
        if ($from >= $to) {
            throw new \InvalidArgumentException('from must be before to.');
        }

        $resolution = (string) $request->query->get('resolution', '1m');
        if (!isset(TelemetryReader::RESOLUTIONS[$resolution])) {
            throw new \InvalidArgumentException('resolution must be one of: '.implode(', ', array_keys(TelemetryReader::RESOLUTIONS)).'.');
        }

        return new JsonResponse([
            'points' => $this->reader->series($device->getId(), $from, $to, $resolution),
            'meta' => [
                'device_id' => $device->getId(),
                'from' => $from->format('Y-m-d\TH:i:sP'),
                'to' => $to->format('Y-m-d\TH:i:sP'),
                'resolution' => $resolution,
            ],
        ]);
    }

    #[Route('/last', methods: ['GET'])]
    public function last(string $id): JsonResponse
    {
        return new JsonResponse(['last' => $this->reader->last($this->find($id)->getId())]);
    }

    #[Route('/status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $device = $this->find($id);

        $lastSeen = $this->reader->lastSeen($device->getId());
        $heartbeat = (int) ($device->getMetadata()['heartbeat_secs'] ?? 300);
        $online = null !== $lastSeen
            && (time() - $lastSeen->getTimestamp()) <= $heartbeat;

        return new JsonResponse([
            'device_id' => $device->getId(),
            'name' => $device->getName(),
            'protocol' => $device->getProtocol()->value,
            'enabled' => $device->isEnabled(),
            'last_seen' => $lastSeen?->format('Y-m-d\TH:i:sP'),
            'heartbeat_secs' => $heartbeat,
            'online' => $online,
        ]);
    }

    private function find(string $id): Device
    {
        $device = $this->devices->find($id);
        if (!$device instanceof Device) {
            throw new NotFoundHttpException('Device not found.');
        }

        return $device;
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
