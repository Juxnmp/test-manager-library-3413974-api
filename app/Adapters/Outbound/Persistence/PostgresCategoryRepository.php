<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports\Outbound\CategoryRepository;
use Illuminate\Support\Facades\DB;

final class PostgresCategoryRepository implements CategoryRepository
{
    /**
     * Q4: Listar las cinco categorías ordenadas por nombre (api-contract.md §3.3).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function findAll(): array
    {
        $rows = DB::select('SELECT id, name FROM library.category ORDER BY name ASC');

        return array_map(
            static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ],
            $rows
        );
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findById(string $id): ?array
    {
        $row = DB::selectOne(
            'SELECT id, name FROM library.category WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
        ];
    }
}
