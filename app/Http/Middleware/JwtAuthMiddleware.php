<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $header = $request->header('Authorization');
        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Token de autenticación ausente o con formato incorrecto.',
                [],
                401
            );
        }

        $token = substr($header, 7);
        $payload = $this->jwtService->validateToken($token);

        if (!$payload || !isset($payload->sub)) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Token inválido, expirado o manipulado.',
                [],
                401
            );
        }

        $user = User::find($payload->sub);
        if (!$user) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Usuario asociado al token no existe.',
                [],
                401
            );
        }

        // Check roles if specified
        if (!empty($roles) && !in_array($user->role, $roles, true)) {
            return ApiResponse::error(
                'FORBIDDEN_ROLE',
                'No tiene permisos suficientes para ejecutar esta operación.',
                [],
                403
            );
        }

        $request->setUserResolver(fn() => $user);
        $request->attributes->set('auth_user', $user);

        return $next($request);
    }
}
