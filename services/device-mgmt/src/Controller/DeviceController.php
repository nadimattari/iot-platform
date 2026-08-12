<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Entity\DeviceRepository;
use App\Service\DeviceConflictException;
use App\Service\DeviceService;
use App\Service\DownlinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function App\Service\serialize_command;
use function App\Service\serialize_device;

#[Route('/api/v1/devices')]
final class DeviceController extends AbstractController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly DeviceService $service,
        private readonly DownlinkService $downlinks,
        private readonly DeviceRepository $devices,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $name = $this->requireString($body, 'name');
        $protocol = DeviceProtocol::tryFrom((string) ($body['protocol'] ?? ''))
            ?? throw new \InvalidArgumentException('protocol must be one of: '.implode(', ', DeviceProtocol::values()));
        $groupId = isset($body['group_id']) ? (string) $body['group_id'] : null;
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];

        [$device, $apiKey] = array_values($this->service->create($name, $protocol, $groupId, $metadata));

        $payload = ['device' => serialize_device($device)];
        if (null !== $apiKey) {
            $payload['api_key'] = $apiKey;
        }

        return new JsonResponse($payload, Response::HTTP_CREATED);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $protocolRaw = $request->query->get('protocol');
        $protocol = null;
        if (null !== $protocolRaw && '' !== $protocolRaw) {
            $protocol = DeviceProtocol::tryFrom((string) $protocolRaw)
                ?? throw new \InvalidArgumentException('protocol must be one of: '.implode(', ', DeviceProtocol::values()));
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT);
        $limit = min(max(1, $limit), self::MAX_LIMIT);

        return new JsonResponse($this->service->list($protocol, $page, $limit));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['device' => serialize_device($this->find($id))]);
    }

    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $device = $this->find($id);
        $body = $this->jsonBody($request);

        $device = $this->service->update(
            $device,
            isset($body['name']) ? (string) $body['name'] : null,
            isset($body['group_id']) ? (string) $body['group_id'] : null,
            isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : null,
        );

        return new JsonResponse(['device' => serialize_device($device)]);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function setEnabled(string $id, Request $request): JsonResponse
    {
        $device = $this->find($id);
        $body = $this->jsonBody($request);

        if (!isset($body['enabled']) || !is_bool($body['enabled'])) {
            throw new \InvalidArgumentException('enabled must be a boolean.');
        }

        $device = $this->service->setEnabled($device, $body['enabled']);

        return new JsonResponse(['device' => serialize_device($device)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->service->delete($this->find($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/downlink', methods: ['POST'])]
    public function downlink(string $id, Request $request): JsonResponse
    {
        $device = $this->find($id);
        $body = $this->jsonBody($request);

        $fPort = isset($body['f_port']) ? (int) $body['f_port'] : 10;
        $confirmed = $body['confirmed'] ?? false;
        if (!is_bool($confirmed)) {
            throw new \InvalidArgumentException('confirmed must be a boolean.');
        }
        $data = isset($body['data']) && is_string($body['data']) ? $body['data'] : null;
        $object = isset($body['object']) && is_array($body['object']) ? $body['object'] : null;

        $command = $this->downlinks->enqueue($device, $fPort, $confirmed, $data, $object);

        return new JsonResponse(['command' => serialize_command($command)], Response::HTTP_CREATED);
    }

    #[Route('/{id}/claim', methods: ['POST'])]
    public function claim(string $id, Request $request): JsonResponse
    {
        $device = $this->find($id);
        $body = $this->jsonBody($request);

        $devEui = isset($body['dev_eui']) ? (string) $body['dev_eui'] : null;
        if (null !== $devEui && 1 !== preg_match('/^[0-9A-Fa-f]{16}$/', $devEui)) {
            throw new \InvalidArgumentException('dev_eui must be 16 hex characters.');
        }

        [$device, $apiKey] = array_values($this->service->claim($device, $devEui));

        $payload = ['device' => serialize_device($device)];
        if (null !== $apiKey) {
            $payload['api_key'] = $apiKey;
        }

        return new JsonResponse($payload);
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

    /**
     * @param array<string, mixed> $body
     */
    private function requireString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return trim($value);
    }
}
