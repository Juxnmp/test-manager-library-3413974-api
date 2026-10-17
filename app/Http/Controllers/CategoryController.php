<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Ports\Outbound\CategoryRepository;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * GET /api/v1/categories (Authenticated)
     * api-contract.md §3.3: Las cinco categorías, orden por nombre.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->categories->findAll(), 200);
    }
}
