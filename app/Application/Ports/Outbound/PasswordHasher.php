<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $passwordHash): bool;
}
