<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class IsbnTakenException extends DomainException
{
    public function __construct(string $isbn)
    {
        parent::__construct(
            'ISBN_TAKEN',
            "Ya existe un libro activo con el ISBN «{$isbn}».",
            [['field' => 'isbn', 'issue' => 'duplicado']],
            409
        );
    }
}
