<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Return standard error JSON body per spec api-contract.md §1.
     */
    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ], $status);
    }

    /**
     * Return standard paginated collection per spec api-contract.md §1.
     */
    public static function paginated(iterable $items, int $page, int $size, int $total): JsonResponse
    {
        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }
}
