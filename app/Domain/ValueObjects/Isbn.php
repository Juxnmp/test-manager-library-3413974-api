<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValidationException;

final readonly class Isbn
{
    private string $value;

    public function __construct(string $raw)
    {
        $normalized = trim($raw);
        if (preg_match('/^\d{13}$/', $normalized) !== 1) {
            throw ValidationException::forField(
                'isbn',
                'debe contener exactamente 13 dígitos numéricos',
                'El ISBN debe tener exactamente 13 dígitos numéricos.'
            );
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
