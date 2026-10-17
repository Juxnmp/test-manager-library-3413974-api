<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValidationException;

final readonly class Quantity
{
    private int $value;

    public function __construct(int $value, string $field = 'total_copies')
    {
        if ($value < 1) {
            throw ValidationException::forField(
                $field,
                'debe ser mayor o igual a 1',
                'La cantidad de ejemplares debe ser al menos 1.'
            );
        }

        $this->value = $value;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
