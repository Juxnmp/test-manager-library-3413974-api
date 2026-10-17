<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class JwtService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = (string) config('app.jwt_secret', env('JWT_SECRET', ''));
        $this->ttl = 28800; // 8 hours in seconds
    }

    /**
     * Generate an 8-hour JWT token containing only `sub` and `role`.
     */
    public function generateToken(User $user): array
    {
        $now = time();
        $payload = [
            'iss' => config('app.url', 'http://localhost:8000'),
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
            'role' => $user->role,
        ];
    }

    /**
     * Decode and validate token.
     */
    public function validateToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }
    }
}
