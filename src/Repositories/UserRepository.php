<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;
use Timer\Models\User;

final class UserRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');

        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    }

    public function find(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? User::fromRow($row) : null;
    }

    public function findActive(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? User::fromRow($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? User::fromRow($row) : null;
    }

    /** @return list<User> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users ORDER BY name ASC');

        return array_map(
            User::fromRow(...),
            $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [],
        );
    }

    public function create(string $email, string $password, string $name, string $role = 'user'): int
    {
        $email = strtolower(trim($email));
        $name = trim($name);

        if ($email === '' || $name === '') {
            throw new \InvalidArgumentException('Email and name are required.');
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            throw new \InvalidArgumentException('Invalid role.');
        }

        if ($this->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Email is already registered.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $name,
            $role,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function updatePassword(int $id, string $password): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function updateProfile(int $id, string $name, string $email): void
    {
        $email = strtolower(trim($email));
        $name = trim($name);

        if ($email === '' || $name === '') {
            throw new \InvalidArgumentException('Email and name are required.');
        }

        $existing = $this->findByEmail($email);
        if ($existing !== null && $existing->id !== $id) {
            throw new \InvalidArgumentException('Email is already registered.');
        }

        $stmt = $this->pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $id]);
    }

    public function verifyPassword(User $user, string $password): bool
    {
        return password_verify($password, $user->passwordHash);
    }
}
