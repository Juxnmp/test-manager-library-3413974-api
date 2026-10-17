<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class InvalidDateRangeException extends DomainException
{
    public function __construct(string $message = 'La fecha final no puede ser anterior a la fecha inicial.')
    {
        parent::__construct(
            'INVALID_DATE_RANGE',
            $message,
            [['field' => 'to', 'issue' => 'anterior a from']],
            422
        );
    }
}
