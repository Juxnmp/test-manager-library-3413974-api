<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports\Outbound\ReportReader;
use App\Domain\ValueObjects\DateRange;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class PostgresReportReader implements ReportReader
{
    /**
     * Q9, HU-10, ADR-004: Una sola consulta agregada sobre valores congelados en library.loan_item
     * (book_id + book_title + category_name), sin unir al catálogo vivo ni exponer socios o bibliotecarios.
     *
     * @return array<int, array{
     *     book_id: string,
     *     book_title: string,
     *     category_name: string,
     *     times_borrowed: int
     * }>
     */
    public function mostBorrowed(DateRange $range): array
    {
        $utc = new DateTimeZone('UTC');
        $fromUtc = $range->from()->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
        $toUtc = $range->to()->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');

        $rows = DB::select(
            'SELECT
                li.book_id,
                li.book_title,
                li.category_name,
                COUNT(*)::int AS times_borrowed
             FROM library.loan_item li
             JOIN library.loan l ON l.id = li.loan_id
             WHERE l.loaned_at >= :from
               AND l.loaned_at <= :to
             GROUP BY li.book_id, li.book_title, li.category_name
             ORDER BY times_borrowed DESC, li.book_title ASC',
            [
                'from' => $fromUtc,
                'to' => $toUtc,
            ]
        );

        return array_map(
            static fn (object $row): array => [
                'book_id' => (string) $row->book_id,
                'book_title' => (string) $row->book_title,
                'category_name' => (string) $row->category_name,
                'times_borrowed' => (int) $row->times_borrowed,
            ],
            $rows
        );
    }
}
