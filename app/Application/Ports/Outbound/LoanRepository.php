<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\Entities\LoanItem;
use DateTimeImmutable;

interface LoanRepository
{
    public function findById(string $id): ?Loan;

    /**
     * Persist a new loan along with its items and updated books in a single atomic transaction,
     * verifying optimistic concurrency on each book's available_copies (ADR-002).
     *
     * @param array<int, Book> $books
     * @param array<string, int> $expectedAvailableCopiesByBookId
     */
    public function saveLoanWithBooks(
        Loan $loan,
        array $books,
        array $expectedAvailableCopiesByBookId,
    ): void;

    /**
     * Persist a returned loan item and its restocked book in a single atomic transaction.
     */
    public function saveReturnedItemWithBook(
        LoanItem $item,
        Book $book,
        int $expectedAvailableCopies,
    ): void;

    /**
     * List loans filtered by date range, calculated status, and member_id, ordered by loaned_at DESC (CA-08.2).
     *
     * @return array{items: array<int, Loan>, total: int}
     */
    public function search(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?string $status,
        ?string $memberId,
        DateTimeImmutable $now,
        int $page,
        int $size,
    ): array;

    /**
     * List unreturned loan items with due_at < $now, ordered by days_late DESC (CA-09.1, CA-09.2).
     *
     * @return array<int, array{
     *     loan_id: string,
     *     item_id: string,
     *     book_title: string,
     *     member_name: string,
     *     due_at: string,
     *     days_late: int
     * }>
     */
    public function findOverdueItems(DateTimeImmutable $now): array;
}
