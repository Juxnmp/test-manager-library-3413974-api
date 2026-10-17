<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\CreateLibrarianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly CreateLibrarianService $createLibrarianService,
    ) {
    }

    /**
     * POST /api/v1/users (Admin only)
     * HU-02 · CA-02.1, CA-02.2, CA-02.3
     */
    public function store(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', '');

        $created = $this->createLibrarianService->execute($username, $password, $role);

        return response()->json($created, 201);
    }
}
