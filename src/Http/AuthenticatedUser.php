<?php

declare(strict_types=1);

namespace Timer\Http;

final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public string $role,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
