<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Ports\Outbound\Clock;
use App\Application\Services\LendBooksService;
use App\Application\Services\OverdueService;
use App\Application\Services\ReturnItemService;
use App\Domain\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(
        private readonly LendBooksService $lendBooksService,
        private readonly ReturnItemService $returnItemService,
        private readonly OverdueService $overdueService,
        private readonly Clock $clock,
    ) {
    }

    /**
     * POST /api/v1/loans (admin, librarian)
     * HU-06 · CA-06.1..CA-06.7
     */
    public function store(Request $request): JsonResponse
    {
        $memberId = (string) $request->input('member_id', '');
        $loanedByUserId = (string) $request->attributes->get('auth_user_id', '');
        $rawItems = $request->input('items');

        if (!is_array($rawItems) || $rawItems === []) {
            throw ValidationException::forField('items', 'debe ser una lista de 1 a 3 elementos', 'El préstamo debe incluir entre 1 y 3 libros.');
        }

        $bookIds = [];
        foreach (array_values($rawItems) as $index => $entry) {
            if (is_array($entry) && isset($entry['book_id']) && is_string($entry['book_id'])) {
                $bookIds[] = $entry['book_id'];
            } elseif (is_string($entry)) {
                $bookIds[] = $entry;
            } else {
                throw ValidationException::forField(
                    "items[{$index}].book_id",
                    'obligatorio',
                    'Cada línea del préstamo debe especificar su «book_id».'
                );
            }
        }

        $loan = $this->lendBooksService->execute($memberId, $loanedByUserId, $bookIds);

        return response()->json($loan->toArray($this->clock->now()), 201);
    }

    /**
     * GET /api/v1/loans (admin, librarian)
     * HU-08 · CA-08.2
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $status = $request->query('status');
        $memberId = $request->query('member_id');
        $page = $this->parseIntParam($request->query('page', 1), 'page');
        $size = $this->parseIntParam($request->query('size', 20), 'size');

        $result = $this->overdueService->searchLoans(
            fromRaw: is_string($from) ? $from : null,
            toRaw: is_string($to) ? $to : null,
            status: is_string($status) ? $status : null,
            memberId: is_string($memberId) ? $memberId : null,
            page: $page,
            size: $size,
        );

        return response()->json($result, 200);
    }

    /**
     * GET /api/v1/loans/{id} (admin, librarian)
     * HU-08 · CA-08.1
     */
    public function show(string $id): JsonResponse
    {
        $loanData = $this->overdueService->getLoanById($id);

        return response()->json($loanData, 200);
    }

    /**
     * POST /api/v1/loans/{loan_id}/items/{item_id}/return (admin, librarian)
     * HU-07 · CA-07.1..CA-07.4
     */
    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        $returnedItem = $this->returnItemService->execute($loanId, $itemId);

        return response()->json($returnedItem->toArray(), 200);
    }

    /**
     * GET /api/v1/loans/overdue (admin, librarian)
     * HU-09 · CA-09.1, CA-09.2
     */
    public function overdue(): JsonResponse
    {
        return response()->json($this->overdueService->listOverdue(), 200);
    }

    private function parseIntParam(mixed $raw, string $field): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw ValidationException::forField($field, 'debe ser un número entero', "El campo «{$field}» debe ser un número entero.");
    }
}
