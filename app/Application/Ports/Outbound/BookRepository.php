<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

use App\Domain\Entities\Book;

interface BookRepository
{
    public function findById(string $id): ?Book;

    /**
     * Fetch active books by a batch of IDs (Q3 in data-model.md §6).
     *
     * @param array<int, string> $ids
     * @return array<string, Book> Indexed by book ID
     */
    public function findByIds(array $ids): array;

    public function existsActiveByIsbn(string $isbn, ?string $excludeBookId = null): bool;

    public function hasOpenLoans(string $bookId): bool;

    /**
     * Search active books ordered by title ascending (Q1 in data-model.md §6).
     *
     * @return array{items: array<int, Book>, total: int}
     */
    public function search(?string $query, ?string $categoryId, int $page, int $size): array;

    public function save(Book $book, ?int $expectedAvailableCopies = null): void;
}
