<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports\Outbound\LoanRepository;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\Entities\LoanItem;
use App\Domain\Exceptions\BookNotAvailableException;
use App\Domain\Exceptions\ItemAlreadyReturnedException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PostgresLoanRepository implements LoanRepository
{
    /**
     * Q5: Préstamo con sus líneas.
     */
    public function findById(string $id): ?Loan
    {
        $loanRow = DB::selectOne(
            'SELECT id, member_id, loaned_by_user_id, loaned_at
             FROM library.loan
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );

        if ($loanRow === null) {
            return null;
        }

        $itemRows = DB::select(
            'SELECT id, loan_id, book_id, book_title, isbn, category_name, due_at, returned_at
             FROM library.loan_item
             WHERE loan_id = :loan_id
             ORDER BY id ASC',
            ['loan_id' => $id]
        );

        return $this->hydrateLoan($loanRow, $itemRows);
    }

    /**
     * T-13, ADR-002, RNF-2: Persist loan + items + updated books in one atomic transaction
     * with optimistic concurrency checking on available_copies.
     *
     * @param array<int, Book> $books
     * @param array<string, int> $expectedAvailableCopiesByBookId
     */
    public function saveLoanWithBooks(
        Loan $loan,
        array $books,
        array $expectedAvailableCopiesByBookId,
    ): void {
        $utc = new DateTimeZone('UTC');

        DB::transaction(function () use ($loan, $books, $expectedAvailableCopiesByBookId, $utc): void {
            foreach ($books as $index => $book) {
                $expectedAvailable = $expectedAvailableCopiesByBookId[$book->id()] ?? null;
                if ($expectedAvailable === null || $expectedAvailable <= 0) {
                    throw new BookNotAvailableException($book->title(), $index);
                }

                try {
                    $affected = DB::affectingStatement(
                        'UPDATE library.book
                         SET available_copies = :new_available
                         WHERE id = :id
                           AND deleted_at IS NULL
                           AND available_copies = :expected_available
                           AND available_copies >= 1',
                        [
                            'id' => $book->id(),
                            'new_available' => $book->availableCopies(),
                            'expected_available' => $expectedAvailable,
                        ]
                    );
                } catch (QueryException $e) {
                    $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
                    if ($sqlState === '23514') {
                        throw new BookNotAvailableException($book->title(), $index);
                    }
                    throw $e;
                }

                if ($affected !== 1) {
                    throw new BookNotAvailableException($book->title(), $index);
                }
            }

            DB::statement(
                'INSERT INTO library.loan (id, member_id, loaned_by_user_id, loaned_at)
                 VALUES (:id, :member_id, :loaned_by_user_id, :loaned_at)',
                [
                    'id' => $loan->id(),
                    'member_id' => $loan->memberId(),
                    'loaned_by_user_id' => $loan->loanedByUserId(),
                    'loaned_at' => $loan->loanedAt()->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                ]
            );

            foreach ($loan->items() as $item) {
                DB::statement(
                    'INSERT INTO library.loan_item (
                        id, loan_id, book_id, book_title, isbn, category_name, due_at, returned_at
                    ) VALUES (
                        :id, :loan_id, :book_id, :book_title, :isbn, :category_name, :due_at, :returned_at
                    )',
                    [
                        'id' => $item->id(),
                        'loan_id' => $item->loanId(),
                        'book_id' => $item->bookId(),
                        'book_title' => $item->bookTitle(),
                        'isbn' => $item->isbn(),
                        'category_name' => $item->categoryName(),
                        'due_at' => $item->dueAt()->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                        'returned_at' => $item->returnedAt()?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                    ]
                );
            }
        });
    }

    /**
     * HU-07, CA-07.1..CA-07.3: Return a single loan item and increment book available_copies atomically.
     * Never increments twice if returned_at was already set.
     */
    public function saveReturnedItemWithBook(
        LoanItem $item,
        Book $book,
        int $expectedAvailableCopies,
    ): void {
        $utc = new DateTimeZone('UTC');
        $returnedAtStr = $item->returnedAt()?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');

        DB::transaction(function () use ($item, $book, $returnedAtStr): void {
            $updatedItems = DB::affectingStatement(
                'UPDATE library.loan_item
                 SET returned_at = :returned_at
                 WHERE id = :id AND returned_at IS NULL',
                [
                    'id' => $item->id(),
                    'returned_at' => $returnedAtStr,
                ]
            );

            if ($updatedItems !== 1) {
                throw new ItemAlreadyReturnedException($item->id());
            }

            $updatedBooks = DB::affectingStatement(
                'UPDATE library.book
                 SET available_copies = available_copies + 1
                 WHERE id = :book_id AND available_copies < total_copies',
                [
                    'book_id' => $book->id(),
                ]
            );

            if ($updatedBooks !== 1) {
                throw new BookNotAvailableException($book->title());
            }
        });
    }

    /**
     * Q6, HU-08: Listar préstamos por rango de fechas, estado calculado y socio, orden descendente, paginado.
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
    ): array {
        $utc = new DateTimeZone('UTC');
        $conditions = [];
        $params = [];

        if ($memberId !== null && trim($memberId) !== '') {
            $conditions[] = 'l.member_id = :member_id';
            $params['member_id'] = trim($memberId);
        }

        if ($from !== null) {
            $conditions[] = 'l.loaned_at >= :from';
            $params['from'] = $from->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
        }

        if ($to !== null) {
            $conditions[] = 'l.loaned_at <= :to';
            $params['to'] = $to->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
        }

        if ($status !== null && trim($status) !== '') {
            $nowUtc = $now->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
            if ($status === 'closed') {
                $conditions[] = 'NOT EXISTS (
                    SELECT 1 FROM library.loan_item li
                    WHERE li.loan_id = l.id AND li.returned_at IS NULL
                )';
            } elseif ($status === 'overdue') {
                $conditions[] = 'EXISTS (
                    SELECT 1 FROM library.loan_item li
                    WHERE li.loan_id = l.id AND li.returned_at IS NULL AND li.due_at < :now_overdue
                )';
                $params['now_overdue'] = $nowUtc;
            } elseif ($status === 'open') {
                $conditions[] = 'EXISTS (
                    SELECT 1 FROM library.loan_item li
                    WHERE li.loan_id = l.id AND li.returned_at IS NULL
                ) AND NOT EXISTS (
                    SELECT 1 FROM library.loan_item li
                    WHERE li.loan_id = l.id AND li.returned_at IS NULL AND li.due_at < :now_open
                )';
                $params['now_open'] = $nowUtc;
            }
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countRow = DB::selectOne(
            "SELECT COUNT(*)::int AS total FROM library.loan l {$whereClause}",
            $params
        );
        $total = $countRow !== null ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $size;
        $loanRows = DB::select(
            "SELECT l.id, l.member_id, l.loaned_by_user_id, l.loaned_at
             FROM library.loan l
             {$whereClause}
             ORDER BY l.loaned_at DESC, l.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [
                'limit' => $size,
                'offset' => $offset,
            ])
        );

        if ($loanRows === []) {
            return ['items' => [], 'total' => $total];
        }

        $loanIds = array_map(static fn (object $r): string => (string) $r->id, $loanRows);
        $placeholders = implode(', ', array_fill(0, count($loanIds), '?'));
        $allItemRows = DB::select(
            "SELECT id, loan_id, book_id, book_title, isbn, category_name, due_at, returned_at
             FROM library.loan_item
             WHERE loan_id IN ({$placeholders})
             ORDER BY id ASC",
            $loanIds
        );

        $itemsByLoanId = [];
        foreach ($allItemRows as $itemRow) {
            $itemsByLoanId[(string) $itemRow->loan_id][] = $itemRow;
        }

        $loans = [];
        foreach ($loanRows as $loanRow) {
            $lid = (string) $loanRow->id;
            $loans[] = $this->hydrateLoan($loanRow, $itemsByLoanId[$lid] ?? []);
        }

        return [
            'items' => $loans,
            'total' => $total,
        ];
    }

    /**
     * Q8, HU-09: Líneas sin devolver con due_at < ahora, ordenadas por más días de retraso primero.
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
    public function findOverdueItems(DateTimeImmutable $now): array
    {
        $utc = new DateTimeZone('UTC');
        $nowUtc = $now->setTimezone($utc);

        $rows = DB::select(
            'SELECT li.id AS item_id,
                    li.loan_id,
                    li.book_id,
                    li.book_title,
                    li.isbn,
                    li.category_name,
                    li.due_at,
                    li.returned_at,
                    m.full_name AS member_name
             FROM library.loan_item li
             JOIN library.loan l ON l.id = li.loan_id
             JOIN library.member m ON m.id = l.member_id
             WHERE li.returned_at IS NULL
               AND li.due_at < :now
             ORDER BY li.due_at ASC, li.id ASC',
            ['now' => $nowUtc->format('Y-m-d\TH:i:s.u\Z')]
        );

        $result = [];
        foreach ($rows as $row) {
            $item = $this->hydrateLoanItem($row);
            $result[] = [
                'loan_id' => $item->loanId(),
                'item_id' => $item->id(),
                'book_title' => $item->bookTitle(),
                'member_name' => (string) $row->member_name,
                'due_at' => $item->dueAt()->format('Y-m-d\TH:i:s\Z'),
                'days_late' => $item->daysLate($nowUtc),
            ];
        }

        usort(
            $result,
            static fn (array $a, array $b): int => ($b['days_late'] <=> $a['days_late'])
                ?: strcmp($a['due_at'], $b['due_at'])
        );

        return $result;
    }

    /**
     * @param array<int, object> $itemRows
     */
    private function hydrateLoan(object $loanRow, array $itemRows): Loan
    {
        $utc = new DateTimeZone('UTC');
        $loanedAt = (new DateTimeImmutable((string) $loanRow->loaned_at, $utc))->setTimezone($utc);

        $items = array_map(fn (object $r): LoanItem => $this->hydrateLoanItem($r), $itemRows);

        return new Loan(
            id: (string) $loanRow->id,
            memberId: (string) $loanRow->member_id,
            loanedByUserId: (string) $loanRow->loaned_by_user_id,
            loanedAt: $loanedAt,
            items: $items,
        );
    }

    private function hydrateLoanItem(object $row): LoanItem
    {
        $utc = new DateTimeZone('UTC');
        $dueAt = (new DateTimeImmutable((string) $row->due_at, $utc))->setTimezone($utc);
        $returnedAt = $row->returned_at !== null
            ? (new DateTimeImmutable((string) $row->returned_at, $utc))->setTimezone($utc)
            : null;

        return new LoanItem(
            id: (string) ($row->id ?? $row->item_id),
            loanId: (string) $row->loan_id,
            bookId: (string) $row->book_id,
            bookTitle: (string) $row->book_title,
            isbn: (string) $row->isbn,
            categoryName: (string) $row->category_name,
            dueAt: $dueAt,
            returnedAt: $returnedAt,
        );
    }
}
