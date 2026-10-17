<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Ports\Outbound\TokenIssuer;
use App\Application\Ports\Outbound\UserRepository;
use App\Domain\Exceptions\ForbiddenRoleException;
use App\Domain\Exceptions\InvalidCredentialsException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function __construct(
        private readonly TokenIssuer $tokenIssuer,
        private readonly UserRepository $users,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $header = $request->header('Authorization');
        $token = null;

        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
        }

        if ($token === null || $token === '') {
            throw new InvalidCredentialsException('Token de autenticación ausente o con formato incorrecto.');
        }

        $claims = $this->tokenIssuer->validate($token);
        if ($claims === null || ($claims['sub'] ?? '') === '') {
            throw new InvalidCredentialsException('Token inválido, expirado o manipulado.');
        }

        $user = $this->users->findById($claims['sub']);
        if ($user === null) {
            throw new InvalidCredentialsException('El usuario asociado al token no existe.');
        }

        // Authorization evaluated by role before hitting the controller so 403 never leaks resource existence (api-contract.md §4)
        if ($roles !== [] && !in_array($user->role(), $roles, true)) {
            throw new ForbiddenRoleException();
        }

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_user_id', $user->id());

        return $next($request);
    }
}
