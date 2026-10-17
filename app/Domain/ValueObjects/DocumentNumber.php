<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValidationException;

final readonly class DocumentNumber
{
    private string $value;

    public function __construct(string $raw)
    {
        $normalized = trim($raw);
        if ($normalized === '') {
            throw ValidationException::forField(
                'document_number',
                'obligatorio',
                'El número de documento del socio es obligatorio.'
            );
        }

        if (mb_strlen($normalized, 'UTF-8') > 30) {
            throw ValidationException::forField(
                'document_number',
                'máximo 30 caracteres',
                'El número de documento no puede superar los 30 caracteres.'
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
