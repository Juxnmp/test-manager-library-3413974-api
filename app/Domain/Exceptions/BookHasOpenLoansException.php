<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class BookHasOpenLoansException extends DomainException
{
    public function __construct(string $bookTitle = '')
    {
        $msg = $bookTitle !== ''
            ? "El libro «{$bookTitle}» tiene préstamos abiertos y no puede darse de baja."
            : 'El libro tiene préstamos abiertos y no puede darse de baja.';

        parent::__construct(
            'BOOK_HAS_OPEN_LOANS',
            $msg,
            [['field' => 'book_id', 'issue' => 'préstamos abiertos']],
            409
        );
    }
}
