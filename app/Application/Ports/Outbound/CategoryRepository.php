<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

interface CategoryRepository
{
    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function findAll(): array;

    /**
     * @return array{id: string, name: string}|null
     */
    public function findById(string $id): ?array;
}
