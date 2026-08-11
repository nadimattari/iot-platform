<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceRepository;
use App\Service\RegisterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/devices/{id}/registers')]
final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly RegisterService $service,
        private readonly DeviceRepository $devices,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $id): JsonResponse
    {
        return new JsonResponse(['registers' => $this->service->listForDevice($this->find($id))]);
    }

    #[Route('', methods: ['PUT'])]
    public function replace(string $id, Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $entries = $body['registers'] ?? null;
        if (!is_array($entries)) {
            throw new \InvalidArgumentException('registers must be an array.');
        }

        return new JsonResponse(['registers' => $this->service->replaceForDevice($this->find($id), $entries)]);
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
