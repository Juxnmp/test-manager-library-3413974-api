<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Exceptions\ValidationException;

final class User
{
    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_LIBRARIAN = 'librarian';

    private string $id;
    private string $username;
    private string $passwordHash;
    private string $role;

    public function __construct(
        string $id,
        string $username,
        string $passwordHash,
        string $role,
    ) {
        $trimmedId = trim($id);
        if ($trimmedId === '') {
            throw ValidationException::forField('id', 'obligatorio', 'El identificador del usuario es obligatorio.');
        }

        $normalizedUsername = self::normalizeUsername($username);
        if ($normalizedUsername === '' || mb_strlen($normalizedUsername, 'UTF-8') > 120) {
            throw ValidationException::forField(
                'username',
                'obligatorio y máximo 120 caracteres',
                'El nombre de usuario es obligatorio y no puede superar 120 caracteres.'
            );
        }

        if (trim($passwordHash) === '') {
            throw ValidationException::forField('password', 'hash obligatorio', 'El hash de contraseña no puede estar vacío.');
        }

        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_LIBRARIAN], true)) {
            throw ValidationException::forField(
                'role',
                'debe ser admin o librarian',
                'El rol del usuario no es válido.'
            );
        }

        $this->id = $trimmedId;
        $this->username = $normalizedUsername;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
    }

    /**
     * Normalize username: lowercase and trimmed (CA-02.2).
     */
    public static function normalizeUsername(string $rawUsername): string
    {
        return mb_strtolower(trim($rawUsername), 'UTF-8');
    }

    /**
     * Create a librarian via API enforcing CA-02.1..CA-02.3:
     * requesting role 'admin' via API throws 422 VALIDATION_ERROR.
     */
    public static function createFromApiRequest(
        string $id,
        string $rawUsername,
        string $passwordHash,
        string $requestedRole,
    ): self {
        if ($requestedRole === self::ROLE_ADMIN) {
            throw ValidationException::forField(
                'role',
                'el rol admin no puede otorgarse por la API',
                'No se permite crear usuarios con rol administrador a través de la API.'
            );
        }

        if ($requestedRole !== self::ROLE_LIBRARIAN) {
            throw ValidationException::forField(
                'role',
                'debe ser librarian',
                'El rol solicitado no es válido; solo se admite «librarian».'
            );
        }

        return new self(
            id: $id,
            username: $rawUsername,
            passwordHash: $passwordHash,
            role: self::ROLE_LIBRARIAN,
        );
    }

    /**
     * Provision initial admin from environment variables (CA-01, D-10).
     */
    public static function provisionAdmin(
        string $id,
        string $rawUsername,
        string $passwordHash,
    ): self {
        return new self(
            id: $id,
            username: $rawUsername,
            passwordHash: $passwordHash,
            role: self::ROLE_ADMIN,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Safe public representation: NEVER includes password or password_hash (CA-02.3, RNF-3, Art. IX).
     *
     * @return array{id: string, username: string, role: string}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'role' => $this->role,
        ];
    }
}
