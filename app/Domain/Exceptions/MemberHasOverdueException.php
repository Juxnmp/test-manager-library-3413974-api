<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class MemberHasOverdueException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'MEMBER_HAS_OVERDUE',
            'El socio tiene libros vencidos sin devolver y no puede solicitar nuevos préstamos.',
            [['field' => 'member_id', 'issue' => 'libros vencidos']],
            409
        );
    }
}
