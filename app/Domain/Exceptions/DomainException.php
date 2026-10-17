<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

abstract class DomainException extends RuntimeException
{
    /**
     * @param array<int, array{field: string, issue: string}> $details
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
        private readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<int, array{field: string, issue: string}>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
