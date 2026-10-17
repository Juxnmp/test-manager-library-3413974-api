<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class FakeClock implements Clock
{
    private DateTimeImmutable $current;

    public function __construct(?DateTimeImmutable $initial = null)
    {
        $utc = new DateTimeZone('UTC');
        $this->current = ($initial ?? new DateTimeImmutable('2026-10-14T14:15:00Z', $utc))->setTimezone($utc);
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function setNow(DateTimeImmutable $newNow): void
    {
        $this->current = $newNow->setTimezone(new DateTimeZone('UTC'));
    }

    public function advanceDays(int $days): void
    {
        $modifier = ($days >= 0 ? '+' : '') . $days . ' days';
        $this->current = $this->current->modify($modifier);
    }
}
