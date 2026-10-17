<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\TokenIssuer;
use App\Domain\Entities\User;

final class FakeTokenIssuer implements TokenIssuer
{
    private const int TTL_SECONDS = 28800;

    /** @var array<string, array{sub: string, role: string, exp: int}> */
    private array $issued = [];

    public function issue(User $user): array
    {
        $token = 'fake-jwt.' . base64_encode(json_encode([
            'sub' => $user->id(),
            'role' => $user->role(),
        ], JSON_THROW_ON_ERROR));

        $this->issued[$token] = [
            'sub' => $user->id(),
            'role' => $user->role(),
            'exp' => time() + self::TTL_SECONDS,
        ];

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TTL_SECONDS,
            'role' => $user->role(),
        ];
    }

    public function validate(string $token): ?array
    {
        return $this->issued[$token] ?? null;
    }
}
