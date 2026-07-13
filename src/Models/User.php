<?php

declare(strict_types=1);

namespace Timer\Models;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $passwordHash,
        public string $name,
        public string $role,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['name'],
            (string) $row['role'],
            (bool) ($row['is_active'] ?? true),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
