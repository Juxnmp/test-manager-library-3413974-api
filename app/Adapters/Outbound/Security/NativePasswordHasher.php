<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Security;

use App\Application\Ports\Outbound\PasswordHasher;

final class NativePasswordHasher implements PasswordHasher
{
    public function hash(string $plainPassword): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        return password_hash($plainPassword, $algo);
    }

    public function verify(string $plainPassword, string $passwordHash): bool
    {
        if ($passwordHash === '') {
            return false;
        }

        return password_verify($plainPassword, $passwordHash);
    }
}
