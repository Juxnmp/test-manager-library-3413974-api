<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class ValidationException extends DomainException
{
    /**
     * @param array<int, array{field: string, issue: string}> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct('VALIDATION_ERROR', $message, $details, 422);
    }

    public static function forField(string $field, string $issue, string $message = 'Datos de entrada inválidos.'): self
    {
        return new self($message, [['field' => $field, 'issue' => $issue]]);
    }
}
