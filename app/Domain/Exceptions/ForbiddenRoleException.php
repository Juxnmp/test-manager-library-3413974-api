<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class ForbiddenRoleException extends DomainException
{
    public function __construct(string $message = 'No tiene permisos suficientes para ejecutar esta operación.')
    {
        parent::__construct('FORBIDDEN_ROLE', $message, [], 403);
    }
}
