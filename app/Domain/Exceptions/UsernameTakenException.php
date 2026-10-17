<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class UsernameTakenException extends DomainException
{
    public function __construct(string $username)
    {
        parent::__construct(
            'USERNAME_TAKEN',
            "El nombre de usuario «{$username}» ya está registrado.",
            [['field' => 'username', 'issue' => 'duplicado']],
            409
        );
    }
}
