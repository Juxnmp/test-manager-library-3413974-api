<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class ItemAlreadyReturnedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'ITEM_ALREADY_RETURNED',
            'Esta línea de préstamo ya fue devuelta anteriormente.',
            [['field' => 'item_id', 'issue' => 'ya devuelto']],
            409
        );
    }
}
