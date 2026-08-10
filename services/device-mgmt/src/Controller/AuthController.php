<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\JwtUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/api/v1/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof JwtUser) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $user->getUserIdentifier(),
            'email' => $user->getEmail(),
            'role' => $user->getRole(),
        ]);
    }
}
