<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class DocumentTakenException extends DomainException
{
    public function __construct(string $documentNumber)
    {
        parent::__construct(
            'DOCUMENT_TAKEN',
            "Ya existe un socio con el documento «{$documentNumber}».",
            [['field' => 'document_number', 'issue' => 'duplicado']],
            409
        );
    }
}
