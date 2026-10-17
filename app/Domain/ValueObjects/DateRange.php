<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidDateRangeException;
use App\Domain\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class DateRange
{
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;
    private string $fromDate;
    private string $toDate;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to, ?string $fromDate = null, ?string $toDate = null)
    {
        $utc = new DateTimeZone('UTC');
        $fromUtc = $from->setTimezone($utc);
        $toUtc = $to->setTimezone($utc);

        if ($toUtc < $fromUtc) {
            throw new InvalidDateRangeException();
        }

        $this->from = $fromUtc;
        $this->to = $toUtc;
        $this->fromDate = $fromDate ?? $fromUtc->format('Y-m-d');
        $this->toDate = $toDate ?? $toUtc->format('Y-m-d');
    }

    public static function fromStrings(string $fromRaw, string $toRaw): self
    {
        $fromTrimmed = trim($fromRaw);
        $toTrimmed = trim($toRaw);

        if ($fromTrimmed === '') {
            throw ValidationException::forField('from', 'obligatorio', 'La fecha inicial (from) es obligatoria.');
        }

        if ($toTrimmed === '') {
            throw ValidationException::forField('to', 'obligatorio', 'La fecha final (to) es obligatoria.');
        }

        $utc = new DateTimeZone('UTC');

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromTrimmed) === 1) {
                $from = new DateTimeImmutable($fromTrimmed . 'T00:00:00Z', $utc);
                $fromDate = $fromTrimmed;
            } else {
                $from = (new DateTimeImmutable($fromTrimmed, $utc))->setTimezone($utc);
                $fromDate = $from->format('Y-m-d');
            }
        } catch (Throwable) {
            throw ValidationException::forField('from', 'formato de fecha inválido', 'El parámetro «from» no tiene un formato de fecha válido.');
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toTrimmed) === 1) {
                $to = new DateTimeImmutable($toTrimmed . 'T23:59:59.999999Z', $utc);
                $toDate = $toTrimmed;
            } else {
                $to = (new DateTimeImmutable($toTrimmed, $utc))->setTimezone($utc);
                $toDate = $to->format('Y-m-d');
            }
        } catch (Throwable) {
            throw ValidationException::forField('to', 'formato de fecha inválido', 'El parámetro «to» no tiene un formato de fecha válido.');
        }

        return new self($from, $to, $fromDate, $toDate);
    }

    public function from(): DateTimeImmutable
    {
        return $this->from;
    }

    public function to(): DateTimeImmutable
    {
        return $this->to;
    }

    public function fromDate(): string
    {
        return $this->fromDate;
    }

    public function toDate(): string
    {
        return $this->toDate;
    }
}
