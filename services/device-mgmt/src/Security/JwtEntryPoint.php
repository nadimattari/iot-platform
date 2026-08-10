<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class JwtEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
