<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class InvalidCredentialsException extends DomainException
{
    public function __construct(string $message = 'Credenciales de acceso inválidas.')
    {
        parent::__construct('INVALID_CREDENTIALS', $message, [], 401);
    }
}
