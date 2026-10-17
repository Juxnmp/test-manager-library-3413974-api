<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories (Authenticated)
     */
    public function index(): JsonResponse
    {
        $categories = Category::orderBy('name', 'asc')->get(['id', 'name']);
        return response()->json($categories, 200);
    }
}
