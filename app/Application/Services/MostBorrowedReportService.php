<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\ReportReader;
use App\Domain\ValueObjects\DateRange;

final readonly class MostBorrowedReportService
{
    public function __construct(
        private ReportReader $reportReader,
    ) {
    }

    /**
     * HU-10 · Reporte de libros más prestados por rango de fechas (T-11, CA-10.1..CA-10.5, ADR-004).
     *
     * @return array{
     *     from: string,
     *     to: string,
     *     rows: array<int, array{
     *         book_id: string,
     *         book_title: string,
     *         category_name: string,
     *         times_borrowed: int
     *     }>
     * }
     */
    public function execute(string $fromRaw, string $toRaw): array
    {
        $range = DateRange::fromStrings($fromRaw, $toRaw);
        $rows = $this->reportReader->mostBorrowed($range);

        return [
            'from' => $range->fromDate(),
            'to' => $range->toDate(),
            'rows' => $rows,
        ];
    }
}
