<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\PasswordHasher;

final class FakePasswordHasher implements PasswordHasher
{
    public function hash(string $plainPassword): string
    {
        return 'fake_hash::' . hash('sha256', $plainPassword);
    }

    public function verify(string $plainPassword, string $passwordHash): bool
    {
        return hash_equals($this->hash($plainPassword), $passwordHash);
    }
}
