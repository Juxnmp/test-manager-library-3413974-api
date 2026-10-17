<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\CategoryRepository;

final class FakeCategoryRepository implements CategoryRepository
{
    public const string NOVELA_ID = '11111111-1111-4111-8111-111111111111';
    public const string CIENCIA_ID = '22222222-2222-4222-8222-222222222222';
    public const string TECNOLOGIA_ID = '33333333-3333-4333-8333-333333333333';
    public const string HISTORIA_ID = '44444444-4444-4444-8444-444444444444';
    public const string INFANTIL_ID = '55555555-5555-4555-8555-555555555555';

    /** @var array<string, array{id: string, name: string}> */
    private array $categories;

    public function __construct()
    {
        $this->categories = [
            self::NOVELA_ID => ['id' => self::NOVELA_ID, 'name' => 'Novela'],
            self::CIENCIA_ID => ['id' => self::CIENCIA_ID, 'name' => 'Ciencia'],
            self::TECNOLOGIA_ID => ['id' => self::TECNOLOGIA_ID, 'name' => 'Tecnología'],
            self::HISTORIA_ID => ['id' => self::HISTORIA_ID, 'name' => 'Historia'],
            self::INFANTIL_ID => ['id' => self::INFANTIL_ID, 'name' => 'Infantil'],
        ];
    }

    public function findAll(): array
    {
        $items = array_values($this->categories);
        usort($items, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
    }

    public function findById(string $id): ?array
    {
        return $this->categories[$id] ?? null;
    }

    public function rename(string $id, string $newName): void
    {
        if (isset($this->categories[$id])) {
            $this->categories[$id]['name'] = $newName;
        }
    }
}
