<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Campos requeridos incompletos.',
                collect($validator->errors())->map(fn($msgs, $field) => ['field' => $field, 'issue' => $msgs[0]])->values()->all(),
                422
            );
        }

        $username = strtolower(trim($request->input('username')));
        $user = User::where('username', $username)->first();

        // CA-01.2: Con credenciales inválidas responde 401 con mensaje que no dice si falló usuario o clave
        if (!$user || !password_verify($request->input('password'), $user->password_hash)) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Credenciales de acceso inválidas.',
                [],
                401
            );
        }

        $tokenData = $this->jwtService->generateToken($user);

        return response()->json($tokenData, 200);
    }

    /**
     * GET /api/v1/health
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }
}
