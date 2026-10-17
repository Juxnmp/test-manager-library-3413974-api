<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\BookRepository;
use App\Domain\Entities\Book;

final class FakeBookRepository implements BookRepository
{
    /** @var array<string, Book> */
    private array $byId = [];

    /** @var array<string, bool> */
    private array $booksWithOpenLoans = [];

    private ?FakeLoanRepository $loanRepository = null;

    public function attachLoanRepository(FakeLoanRepository $loanRepository): void
    {
        $this->loanRepository = $loanRepository;
    }

    public function markBookHasOpenLoans(string $bookId, bool $hasOpenLoans = true): void
    {
        $this->booksWithOpenLoans[$bookId] = $hasOpenLoans;
    }

    public function findById(string $id): ?Book
    {
        return $this->byId[$id] ?? null;
    }

    public function findByIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $book = $this->byId[$id] ?? null;
            if ($book !== null && !$book->isDeleted()) {
                $result[$id] = $book;
            }
        }

        return $result;
    }

    public function existsActiveByIsbn(string $isbn, ?string $excludeBookId = null): bool
    {
        foreach ($this->byId as $book) {
            if ($book->isDeleted()) {
                continue;
            }
            if ($excludeBookId !== null && $book->id() === $excludeBookId) {
                continue;
            }
            if ($book->isbn()->value() === $isbn) {
                return true;
            }
        }

        return false;
    }

    public function hasOpenLoans(string $bookId): bool
    {
        if (!empty($this->booksWithOpenLoans[$bookId])) {
            return true;
        }

        if ($this->loanRepository !== null) {
            return $this->loanRepository->hasUnreturnedItemsForBook($bookId);
        }

        return false;
    }

    public function search(?string $query, ?string $categoryId, int $page, int $size): array
    {
        $filtered = [];
        foreach ($this->byId as $book) {
            if ($book->isDeleted()) {
                continue;
            }
            if ($categoryId !== null && $book->categoryId() !== $categoryId) {
                continue;
            }
            if ($query !== null && $query !== '') {
                $qLower = mb_strtolower($query, 'UTF-8');
                $inTitle = str_contains(mb_strtolower($book->title(), 'UTF-8'), $qLower);
                $inAuthor = str_contains(mb_strtolower($book->author(), 'UTF-8'), $qLower);
                if (!$inTitle && !$inAuthor) {
                    continue;
                }
            }
            $filtered[] = $book;
        }

        // Order by title ascending (CA-04.1)
        usort($filtered, static fn (Book $a, Book $b): int => strcmp($a->title(), $b->title()));

        $total = count($filtered);
        $offset = ($page - 1) * $size;
        $items = array_slice($filtered, $offset, $size);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function save(Book $book, ?int $expectedAvailableCopies = null): void
    {
        $this->byId[$book->id()] = $book;
    }
}
