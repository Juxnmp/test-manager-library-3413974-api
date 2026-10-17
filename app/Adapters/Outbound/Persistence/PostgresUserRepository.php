<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports\Outbound\UserRepository;
use App\Domain\Entities\User;
use App\Domain\Exceptions\UsernameTakenException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PostgresUserRepository implements UserRepository
{
    public function findById(string $id): ?User
    {
        $row = DB::selectOne(
            'SELECT id, username, password_hash, role FROM library."user" WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $normalized = User::normalizeUsername($username);
        $row = DB::selectOne(
            'SELECT id, username, password_hash, role FROM library."user" WHERE username = :username LIMIT 1',
            ['username' => $normalized]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function existsByUsername(string $username): bool
    {
        $normalized = User::normalizeUsername($username);
        $row = DB::selectOne(
            'SELECT 1 AS found FROM library."user" WHERE username = :username LIMIT 1',
            ['username' => $normalized]
        );

        return $row !== null;
    }

    public function save(User $user): void
    {
        try {
            DB::statement(
                'INSERT INTO library."user" (id, username, password_hash, role)
                 VALUES (:id, :username, :password_hash, :role)
                 ON CONFLICT (id) DO UPDATE SET
                     username = EXCLUDED.username,
                     password_hash = EXCLUDED.password_hash,
                     role = EXCLUDED.role',
                [
                    'id' => $user->id(),
                    'username' => $user->username(),
                    'password_hash' => $user->passwordHash(),
                    'role' => $user->role(),
                ]
            );
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new UsernameTakenException($user->username());
            }

            throw $e;
        }
    }

    private function hydrate(object $row): User
    {
        return new User(
            id: (string) $row->id,
            username: (string) $row->username,
            passwordHash: (string) $row->password_hash,
            role: (string) $row->role,
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();

        return (string) $sqlState === '23505';
    }
}
