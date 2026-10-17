<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\UserRepository;
use App\Domain\Entities\User;

final class FakeUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $byId = [];

    public function findById(string $id): ?User
    {
        return $this->byId[$id] ?? null;
    }

    public function findByUsername(string $username): ?User
    {
        $normalized = User::normalizeUsername($username);
        foreach ($this->byId as $user) {
            if ($user->username() === $normalized) {
                return $user;
            }
        }

        return null;
    }

    public function existsByUsername(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function save(User $user): void
    {
        $this->byId[$user->id()] = $user;
    }
}
