<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\PasswordHasher;
use App\Application\Ports\Outbound\UserRepository;
use App\Domain\Entities\User;
use App\Domain\Exceptions\UsernameTakenException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\Uuid;

final readonly class CreateLibrarianService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {
    }

    /**
     * HU-02 · Dar de alta bibliotecarios (CA-02.1, CA-02.2, CA-02.3).
     *
     * @return array{id: string, username: string, role: string}
     */
    public function execute(string $rawUsername, string $plainPassword, string $requestedRole): array
    {
        $details = [];
        if (trim($rawUsername) === '') {
            $details[] = ['field' => 'username', 'issue' => 'obligatorio'];
        }
        if (trim($plainPassword) === '' || mb_strlen($plainPassword, 'UTF-8') < 4) {
            $details[] = ['field' => 'password', 'issue' => 'obligatorio (mínimo 4 caracteres)'];
        }
        if (trim($requestedRole) === '') {
            $details[] = ['field' => 'role', 'issue' => 'obligatorio'];
        }
        if ($details !== []) {
            throw new ValidationException('Datos de usuario inválidos.', $details);
        }

        $passwordHash = $this->passwordHasher->hash($plainPassword);

        // Domain entity validates role (rejects 'admin' with 422 per CA-02.3) and normalizes username (CA-02.2)
        $user = User::createFromApiRequest(
            id: Uuid::v4(),
            rawUsername: $rawUsername,
            passwordHash: $passwordHash,
            requestedRole: trim($requestedRole),
        );

        if ($this->users->existsByUsername($user->username())) {
            throw new UsernameTakenException($user->username());
        }

        $this->users->save($user);

        return $user->toPublicArray();
    }
}
