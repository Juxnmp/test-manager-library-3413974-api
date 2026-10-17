<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class BookNotAvailableException extends DomainException
{
    public function __construct(string $bookTitle = '', int $index = 0)
    {
        $msg = $bookTitle !== ''
            ? "No quedan ejemplares disponibles de «{$bookTitle}»."
            : 'No quedan ejemplares disponibles del libro solicitado.';

        parent::__construct(
            'BOOK_NOT_AVAILABLE',
            $msg,
            [['field' => "items[{$index}].book_id", 'issue' => 'sin ejemplares']],
            409
        );
    }
}
