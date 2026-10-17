<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\PasswordHasher;
use App\Application\Ports\Outbound\UserRepository;
use App\Domain\Entities\User;
use App\Domain\ValueObjects\Uuid;

final readonly class ProvisionAdminService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {
    }

    /**
     * T-17, D-10, data-model.md §9: Provision initial admin user idempotently
     * using the PasswordHasher and UserRepository outbound ports.
     */
    public function ensureProvisioned(string $rawUsername, string $plainPassword): ?User
    {
        $normalized = User::normalizeUsername($rawUsername);
        if ($normalized === '' || $plainPassword === '') {
            return null;
        }

        $existing = $this->users->findByUsername($normalized);
        if ($existing !== null) {
            return $existing;
        }

        $hash = $this->passwordHasher->hash($plainPassword);
        $admin = User::provisionAdmin(
            id: Uuid::v4(),
            rawUsername: $normalized,
            passwordHash: $hash,
        );

        $this->users->save($admin);

        return $admin;
    }
}
