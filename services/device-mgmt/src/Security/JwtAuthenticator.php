<?php

declare(strict_types=1);

namespace App\Security;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\EdDSA;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class JwtAuthenticator extends AbstractAuthenticator
{
    private readonly JWSVerifier $verifier;
    private readonly CompactSerializer $serializer;

    public function __construct(
        private readonly JwksProviderInterface $jwksProvider,
    ) {
        $this->verifier = new JWSVerifier(new AlgorithmManager([new EdDSA()]));
        $this->serializer = new CompactSerializer();
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $token = substr((string) $request->headers->get('Authorization'), strlen('Bearer '));
        if ('' === $token) {
            throw new AuthenticationException('Missing bearer token.');
        }

        try {
            $jws = $this->serializer->unserialize($token);
        } catch (\Throwable) {
            throw new AuthenticationException('Malformed token.');
        }

        $keySet = $this->jwksProvider->getKeySet();
        if (!$this->verifier->verifyWithKeySet($jws, $keySet, 0)) {
            throw new AuthenticationException('Invalid token signature.');
        }

        $payload = json_decode((string) $jws->getPayload(), true, flags: JSON_THROW_ON_ERROR);
        $exp = $payload['exp'] ?? null;
        if (!\is_int($exp) || $exp < time()) {
            throw new AuthenticationException('Token expired.');
        }
        $sub = (string) ($payload['sub'] ?? '');
        if ('' === $sub) {
            throw new AuthenticationException('Token missing subject.');
        }

        $user = new JwtUser(
            $sub,
            (string) ($payload['email'] ?? ''),
            (string) ($payload['role'] ?? ''),
        );

        return new Passport(
            new UserBadge($sub, fn (string $identifier): JwtUser => $user),
            new CustomCredentials(
                static fn (): bool => true,
                $payload,
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
