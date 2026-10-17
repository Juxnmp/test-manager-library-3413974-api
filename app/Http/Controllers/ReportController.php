<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\MostBorrowedReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly MostBorrowedReportService $mostBorrowedReportService,
    ) {
    }

    /**
     * GET /api/v1/reports/most-borrowed (Admin only)
     * HU-10 · CA-10.1..CA-10.5, ADR-004
     */
    public function mostBorrowed(Request $request): JsonResponse
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $report = $this->mostBorrowedReportService->execute($from, $to);

        return response()->json($report, 200);
    }
}
