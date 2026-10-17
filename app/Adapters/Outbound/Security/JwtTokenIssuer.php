<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Security;

use App\Application\Ports\Outbound\Clock;
use App\Application\Ports\Outbound\TokenIssuer;
use App\Domain\Entities\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

final readonly class JwtTokenIssuer implements TokenIssuer
{
    public const int TTL_SECONDS = 28800; // 8 hours (CA-01.1, api-contract.md §4)

    public function __construct(
        private string $secret,
        private string $issuerUrl,
        private Clock $clock,
    ) {
    }

    /**
     * Issue an 8-hour signed HS256 JWT containing only sub and role (no personal data, api-contract.md §4).
     *
     * @return array{access_token: string, token_type: string, expires_in: int, role: string}
     */
    public function issue(User $user): array
    {
        if (trim($this->secret) === '') {
            throw new RuntimeException('JWT_SECRET environment variable must be configured.');
        }

        $now = $this->clock->now()->getTimestamp();
        $payload = [
            'iss' => $this->issuerUrl,
            'sub' => $user->id(),
            'role' => $user->role(),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TTL_SECONDS,
            'role' => $user->role(),
        ];
    }

    /**
     * Validate a bearer token using the injected Clock port and return decoded claims or null if invalid/expired.
     *
     * @return array{sub: string, role: string, exp: int}|null
     */
    public function validate(string $token): ?array
    {
        if (trim($this->secret) === '' || trim($token) === '') {
            return null;
        }

        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            if (!isset($decoded->sub, $decoded->role, $decoded->exp)) {
                return null;
            }

            return [
                'sub' => (string) $decoded->sub,
                'role' => (string) $decoded->role,
                'exp' => (int) $decoded->exp,
            ];
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$timestamp = null;
        }
    }
}
