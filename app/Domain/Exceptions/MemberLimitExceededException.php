<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class MemberLimitExceededException extends DomainException
{
    public function __construct(int $currentActiveCount, int $requestedCount = 1)
    {
        parent::__construct(
            'MEMBER_LIMIT_EXCEEDED',
            "El socio supera el límite máximo de 3 libros sin devolver (actualmente tiene {$currentActiveCount}).",
            [
                [
                    'field' => 'member_id',
                    'issue' => "active_loans={$currentActiveCount}, requested={$requestedCount}, max=3",
                ],
            ],
            422
        );
    }
}
