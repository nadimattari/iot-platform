<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class JwtUser implements UserInterface
{
    public function __construct(
        private string $identifier,
        private string $email = '',
        private string $role = '',
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
