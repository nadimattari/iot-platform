<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CommandStatus;
use App\Entity\Device;
use App\Entity\DeviceRepository;
use App\Service\CommandService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function App\Service\serialize_command;

/**
 * Unified command API: one endpoint sends commands over the device's transport
 * (MQTT or LoRaWAN), another lists the command lifecycle history.
 */
#[Route('/api/v1')]
final class CommandController extends AbstractController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly CommandService $service,
        private readonly DeviceRepository $devices,
    ) {
    }

    #[Route('/devices/{id}/commands', methods: ['POST'])]
    public function send(string $id, Request $request): JsonResponse
    {
        $command = $this->service->send($this->find($id), $this->jsonBody($request));

        return new JsonResponse(['command' => serialize_command($command)], Response::HTTP_CREATED);
    }

    #[Route('/commands', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $deviceId = $request->query->get('device_id');
        $device = null;
        if (null !== $deviceId && '' !== $deviceId) {
            $device = $this->find((string) $deviceId);
        }

        $statusRaw = $request->query->get('status');
        $status = null;
        if (null !== $statusRaw && '' !== $statusRaw) {
            $status = CommandStatus::tryFrom((string) $statusRaw)
                ?? throw new \InvalidArgumentException('status must be one of: '.implode(', ', array_column(CommandStatus::cases(), 'value')));
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT);
        $limit = min(max(1, $limit), self::MAX_LIMIT);

        return new JsonResponse($this->service->history($device, $status, $page, $limit));
    }

    private function find(string $id): Device
    {
        $device = $this->devices->find($id);
        if (!$device instanceof Device) {
            throw new NotFoundHttpException('Device not found.');
        }

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
