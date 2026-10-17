<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * POST /api/v1/users (Admin only)
     * CA-02.1 / CA-02.2 / CA-02.3
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:4',
            'role' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Datos del formulario incompletos o inválidos.',
                collect($validator->errors())->map(fn($msgs, $field) => ['field' => $field, 'issue' => $msgs[0]])->values()->all(),
                422
            );
        }

        $role = $request->input('role');
        // CA-02.3: Pedir el rol admin por la API responde 422
        if ($role === 'admin') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'No se permite crear administradores a través de la API.',
                [['field' => 'role', 'issue' => 'rol no permitido']],
                422
            );
        }

        if ($role !== 'librarian') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El rol solicitado no es válido.',
                [['field' => 'role', 'issue' => 'debe ser librarian']],
                422
            );
        }

        // CA-02.2: El nombre se guarda en minúsculas y recortado; es único (409)
        $username = strtolower(trim($request->input('username')));
        if (User::where('username', $username)->exists()) {
            return ApiResponse::error(
                'USERNAME_TAKEN',
                "El nombre de usuario '{$username}' ya se encuentra en uso.",
                [['field' => 'username', 'issue' => 'duplicado']],
                409
            );
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => password_hash($request->input('password'), PASSWORD_BCRYPT),
            'role' => 'librarian',
        ]);

        // Retorna 201 sin password ni password_hash
        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }
}
