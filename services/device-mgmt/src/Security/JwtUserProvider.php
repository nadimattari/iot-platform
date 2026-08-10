<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * A JWT subject is trusted as a valid user identifier; the real user row is
 * owned by the auth service. For Task 5 the guard only needs to accept the
 * token — device-mgmt never stores users itself.
 */
final class JwtUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return new JwtUser($identifier);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('JWT users are stateless and cannot be refreshed.');
    }

    public function supportsClass(string $class): bool
    {
        return JwtUser::class === $class;
    }
}
