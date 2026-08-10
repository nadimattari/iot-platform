<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function health(): Response
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'device-mgmt']);
    }
}
