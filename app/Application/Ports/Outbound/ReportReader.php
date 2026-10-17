<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

use App\Domain\ValueObjects\DateRange;

interface ReportReader
{
    /**
     * Return most borrowed rows grouped by frozen book_id + book_title + category_name
     * within the given DateRange, ordered by times_borrowed DESC, book_title ASC (HU-10, ADR-004).
     *
     * @return array<int, array{
     *     book_id: string,
     *     book_title: string,
     *     category_name: string,
     *     times_borrowed: int
     * }>
     */
    public function mostBorrowed(DateRange $range): array;
}
