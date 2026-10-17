<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\LoginService;
use App\Application\Services\ProvisionAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly ProvisionAdminService $provisionAdminService,
    ) {
    }

    /**
     * POST /api/v1/auth/login (Public)
     * HU-01 · CA-01.1, CA-01.2, T-17
     */
    public function login(Request $request): JsonResponse
    {
        $adminUser = (string) config('app.admin_username', env('ADMIN_USERNAME', ''));
        $adminPass = (string) config('app.admin_password', env('ADMIN_PASSWORD', ''));
        if ($adminUser !== '' && $adminPass !== '') {
            $this->provisionAdminService->ensureProvisioned($adminUser, $adminPass);
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        $tokenData = $this->loginService->execute($username, $password);

        return response()->json($tokenData, 200);
    }

    /**
     * GET /api/v1/health and GET /health (Public)
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }
}
