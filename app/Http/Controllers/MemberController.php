<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\MemberService;
use App\Domain\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __construct(
        private readonly MemberService $memberService,
    ) {
    }

    /**
     * GET /api/v1/members (admin, librarian)
     * HU-05 · CA-05.2
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->query('q');
        $page = $this->parseIntParam($request->query('page', 1), 'page');
        $size = $this->parseIntParam($request->query('size', 20), 'size');

        $result = $this->memberService->search(
            query: is_string($query) ? $query : null,
            page: $page,
            size: $size,
        );

        return response()->json($result, 200);
    }

    /**
     * GET /api/v1/members/{id} (admin, librarian)
     * api-contract.md §3.5: Detalle con active_loans (conteo sin devolver)
     */
    public function show(string $id): JsonResponse
    {
        $memberData = $this->memberService->getById($id);

        return response()->json($memberData, 200);
    }

    /**
     * POST /api/v1/members (admin, librarian)
     * HU-05 · CA-05.1
     */
    public function store(Request $request): JsonResponse
    {
        $fullName = (string) $request->input('full_name', '');
        $documentNumber = (string) $request->input('document_number', '');

        $member = $this->memberService->create($fullName, $documentNumber);

        return response()->json($member->toArray(), 201);
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
