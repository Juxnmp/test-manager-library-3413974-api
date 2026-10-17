<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports\Outbound\BookRepository;
use App\Domain\Entities\Book;
use App\Domain\Exceptions\BookNotAvailableException;
use App\Domain\Exceptions\IsbnTakenException;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PostgresBookRepository implements BookRepository
{
    /**
     * Q2: Libro por id con el nombre de su categoría.
     */
    public function findById(string $id): ?Book
    {
        $row = DB::selectOne(
            'SELECT b.id, b.title, b.author, b.isbn, b.category_id, c.name AS category_name,
                    b.total_copies, b.available_copies, b.cover_key, b.deleted_at
             FROM library.book b
             JOIN library.category c ON c.id = b.category_id
             WHERE b.id = :id
             LIMIT 1',
            ['id' => $id]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Q3: Libros por lote de ids (antes de prestar).
     *
     * @param array<int, string> $ids
     * @return array<string, Book>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique($ids));
        $placeholders = implode(', ', array_fill(0, count($uniqueIds), '?'));

        $rows = DB::select(
            "SELECT b.id, b.title, b.author, b.isbn, b.category_id, c.name AS category_name,
                    b.total_copies, b.available_copies, b.cover_key, b.deleted_at
             FROM library.book b
             JOIN library.category c ON c.id = b.category_id
             WHERE b.id IN ({$placeholders})",
            $uniqueIds
        );

        $booksById = [];
        foreach ($rows as $row) {
            $book = $this->hydrate($row);
            $booksById[$book->id()] = $book;
        }

        return $booksById;
    }

    public function existsActiveByIsbn(string $isbn, ?string $excludeBookId = null): bool
    {
        if ($excludeBookId !== null) {
            $row = DB::selectOne(
                'SELECT 1 AS found FROM library.book
                 WHERE isbn = :isbn AND deleted_at IS NULL AND id <> :exclude_id
                 LIMIT 1',
                ['isbn' => $isbn, 'exclude_id' => $excludeBookId]
            );
            return $row !== null;
        }

        $row = DB::selectOne(
            'SELECT 1 AS found FROM library.book
             WHERE isbn = :isbn AND deleted_at IS NULL
             LIMIT 1',
            ['isbn' => $isbn]
        );

        return $row !== null;
    }

    public function hasOpenLoans(string $bookId): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS found FROM library.loan_item
             WHERE book_id = :book_id AND returned_at IS NULL
             LIMIT 1',
            ['book_id' => $bookId]
        );

        return $row !== null;
    }

    /**
     * Q1: Buscar libros (texto en título o autor, categoría, solo activos, orden por título, paginado).
     *
     * @return array{items: array<int, Book>, total: int}
     */
    public function search(?string $query, ?string $categoryId, int $page, int $size): array
    {
        $conditions = ['b.deleted_at IS NULL'];
        $params = [];

        if ($categoryId !== null && trim($categoryId) !== '') {
            $conditions[] = 'b.category_id = :category_id';
            $params['category_id'] = trim($categoryId);
        }

        if ($query !== null && trim($query) !== '') {
            $conditions[] = '(b.title ILIKE :q OR b.author ILIKE :q)';
            $params['q'] = '%' . trim($query) . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        $countRow = DB::selectOne(
            "SELECT COUNT(*)::int AS total FROM library.book b {$whereClause}",
            $params
        );
        $total = $countRow !== null ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $size;
        $rows = DB::select(
            "SELECT b.id, b.title, b.author, b.isbn, b.category_id, c.name AS category_name,
                    b.total_copies, b.available_copies, b.cover_key, b.deleted_at
             FROM library.book b
             JOIN library.category c ON c.id = b.category_id
             {$whereClause}
             ORDER BY b.title ASC, b.id ASC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [
                'limit' => $size,
                'offset' => $offset,
            ])
        );

        return [
            'items' => array_map(fn (object $row): Book => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function save(Book $book, ?int $expectedAvailableCopies = null): void
    {
        $deletedAtFormatted = $book->deletedAt() !== null
            ? $book->deletedAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
            : null;

        try {
            $exists = DB::selectOne(
                'SELECT 1 AS found FROM library.book WHERE id = :id LIMIT 1',
                ['id' => $book->id()]
            );

            if ($exists === null) {
                DB::statement(
                    'INSERT INTO library.book (
                        id, title, author, isbn, category_id, total_copies, available_copies, cover_key, deleted_at
                    ) VALUES (
                        :id, :title, :author, :isbn, :category_id, :total_copies, :available_copies, :cover_key, :deleted_at
                    )',
                    [
                        'id' => $book->id(),
                        'title' => $book->title(),
                        'author' => $book->author(),
                        'isbn' => $book->isbn()->value(),
                        'category_id' => $book->categoryId(),
                        'total_copies' => $book->totalCopies()->value(),
                        'available_copies' => $book->availableCopies(),
                        'cover_key' => $book->coverKey(),
                        'deleted_at' => $deletedAtFormatted,
                    ]
                );
                return;
            }

            if ($expectedAvailableCopies !== null) {
                $affected = DB::affectingStatement(
                    'UPDATE library.book
                     SET title = :title,
                         author = :author,
                         isbn = :isbn,
                         category_id = :category_id,
                         total_copies = :total_copies,
                         available_copies = :available_copies,
                         cover_key = :cover_key,
                         deleted_at = :deleted_at
                     WHERE id = :id AND available_copies = :expected_available',
                    [
                        'id' => $book->id(),
                        'title' => $book->title(),
                        'author' => $book->author(),
                        'isbn' => $book->isbn()->value(),
                        'category_id' => $book->categoryId(),
                        'total_copies' => $book->totalCopies()->value(),
                        'available_copies' => $book->availableCopies(),
                        'cover_key' => $book->coverKey(),
                        'deleted_at' => $deletedAtFormatted,
                        'expected_available' => $expectedAvailableCopies,
                    ]
                );

                if ($affected !== 1) {
                    throw new BookNotAvailableException($book->title());
                }
                return;
            }

            DB::statement(
                'UPDATE library.book
                 SET title = :title,
                     author = :author,
                     isbn = :isbn,
                     category_id = :category_id,
                     total_copies = :total_copies,
                     available_copies = :available_copies,
                     cover_key = :cover_key,
                     deleted_at = :deleted_at
                 WHERE id = :id',
                [
                    'id' => $book->id(),
                    'title' => $book->title(),
                    'author' => $book->author(),
                    'isbn' => $book->isbn()->value(),
                    'category_id' => $book->categoryId(),
                    'total_copies' => $book->totalCopies()->value(),
                    'available_copies' => $book->availableCopies(),
                    'cover_key' => $book->coverKey(),
                    'deleted_at' => $deletedAtFormatted,
                ]
            );
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($sqlState === '23505') {
                throw new IsbnTakenException($book->isbn()->value());
            }
            if ($sqlState === '23514') {
                throw new BookNotAvailableException($book->title());
            }

            throw $e;
        }
    }

    private function hydrate(object $row): Book
    {
        $utc = new DateTimeZone('UTC');
        $deletedAt = $row->deleted_at !== null
            ? (new DateTimeImmutable((string) $row->deleted_at, $utc))->setTimezone($utc)
            : null;

        return new Book(
            id: (string) $row->id,
            title: (string) $row->title,
            author: (string) $row->author,
            isbn: new Isbn((string) $row->isbn),
            categoryId: (string) $row->category_id,
            totalCopies: new Quantity((int) $row->total_copies, 'total_copies'),
            availableCopies: (int) $row->available_copies,
            coverKey: $row->cover_key !== null ? (string) $row->cover_key : null,
            deletedAt: $deletedAt,
            categoryName: isset($row->category_name) ? (string) $row->category_name : null,
        );
    }
}
