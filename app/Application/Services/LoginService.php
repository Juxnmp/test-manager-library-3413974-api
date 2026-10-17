<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\PasswordHasher;
use App\Application\Ports\Outbound\TokenIssuer;
use App\Application\Ports\Outbound\UserRepository;
use App\Domain\Entities\User;
use App\Domain\Exceptions\InvalidCredentialsException;
use App\Domain\Exceptions\ValidationException;

final readonly class LoginService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private TokenIssuer $tokenIssuer,
    ) {
    }

    /**
     * HU-01 · Iniciar sesión (CA-01.1, CA-01.2).
     *
     * @return array{access_token: string, token_type: string, expires_in: int, role: string}
     */
    public function execute(string $username, string $password): array
    {
        $details = [];
        if (trim($username) === '') {
            $details[] = ['field' => 'username', 'issue' => 'obligatorio'];
        }
        if ($password === '') {
            $details[] = ['field' => 'password', 'issue' => 'obligatorio'];
        }
        if ($details !== []) {
            throw new ValidationException('Credenciales incompletas.', $details);
        }

        $normalizedUsername = User::normalizeUsername($username);
        $user = $this->users->findByUsername($normalizedUsername);

        // CA-01.2: Con credenciales inválidas responde 401 con un mensaje que NO dice si falló el usuario o la clave.
        if ($user === null || !$this->passwordHasher->verify($password, $user->passwordHash())) {
            throw new InvalidCredentialsException('Credenciales de acceso inválidas.');
        }

        return $this->tokenIssuer->issue($user);
    }
}
