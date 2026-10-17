<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class DuplicateBookInLoanException extends DomainException
{
    public function __construct(string $bookId = '')
    {
        parent::__construct(
            'DUPLICATE_BOOK_IN_LOAN',
            'No se puede repetir el mismo libro dentro de un mismo préstamo.',
            [['field' => 'items', 'issue' => $bookId !== '' ? "libro duplicado: {$bookId}" : 'libro duplicado']],
            422
        );
    }
}
