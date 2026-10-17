<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class ResourceNotFoundException extends DomainException
{
    public function __construct(string $message = 'El recurso solicitado no existe.')
    {
        parent::__construct('NOT_FOUND', $message, [], 404);
    }
}
