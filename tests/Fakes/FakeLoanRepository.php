<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\LoanRepository;
use App\Application\Ports\Outbound\ReportReader;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\Entities\LoanItem;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;

final class FakeLoanRepository implements LoanRepository, ReportReader
{
    /** @var array<string, Loan> */
    private array $loansById = [];

    public function __construct(
        private readonly FakeBookRepository $books,
        private readonly FakeMemberRepository $members,
    ) {
        $this->books->attachLoanRepository($this);
        $this->members->attachLoanRepository($this);
    }

    public function findById(string $id): ?Loan
    {
        return $this->loansById[$id] ?? null;
    }

    public function saveLoanWithBooks(
        Loan $loan,
        array $books,
        array $expectedAvailableCopiesByBookId,
    ): void {
        foreach ($books as $book) {
            $this->books->save($book);
        }

        $this->loansById[$loan->id()] = $loan;
    }

    public function saveReturnedItemWithBook(
        LoanItem $item,
        Book $book,
        int $expectedAvailableCopies,
    ): void {
        $this->books->save($book);
    }

    public function search(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?string $status,
        ?string $memberId,
        DateTimeImmutable $now,
        int $page,
        int $size,
    ): array {
        $filtered = [];

        foreach ($this->loansById as $loan) {
            if ($memberId !== null && $loan->memberId() !== $memberId) {
                continue;
            }
            if ($from !== null && $loan->loanedAt() < $from) {
                continue;
            }
            if ($to !== null && $loan->loanedAt() > $to) {
                continue;
            }
            if ($status !== null && $loan->status($now) !== $status) {
                continue;
            }
            $filtered[] = $loan;
        }

        // Order by loaned_at descending (CA-08.2)
        usort(
            $filtered,
            static fn (Loan $a, Loan $b): int => $b->loanedAt()->getTimestamp() <=> $a->loanedAt()->getTimestamp()
        );

        $total = count($filtered);
        $offset = ($page - 1) * $size;

        return [
            'items' => array_slice($filtered, $offset, $size),
            'total' => $total,
        ];
    }

    public function findOverdueItems(DateTimeImmutable $now): array
    {
        $overdueRows = [];

        foreach ($this->loansById as $loan) {
            $member = $this->members->findById($loan->memberId());
            $memberName = $member !== null ? $member->fullName() : '';

            foreach ($loan->items() as $item) {
                if ($item->isOverdue($now)) {
                    $overdueRows[] = [
                        'loan_id' => $loan->id(),
                        'item_id' => $item->id(),
                        'book_title' => $item->bookTitle(),
                        'member_name' => $memberName,
                        'due_at' => $item->dueAt()->format('Y-m-d\TH:i:s\Z'),
                        'days_late' => $item->daysLate($now),
                        '_due_ts' => $item->dueAt()->getTimestamp(),
                    ];
                }
            }
        }

        // Ordered by most days late first (oldest due_at first) (CA-09.2)
        usort(
            $overdueRows,
            static fn (array $a, array $b): int => ($b['days_late'] <=> $a['days_late']) ?: ($a['_due_ts'] <=> $b['_due_ts'])
        );

        return array_map(static function (array $row): array {
            unset($row['_due_ts']);
            return $row;
        }, $overdueRows);
    }

    public function mostBorrowed(DateRange $range): array
    {
        /** @var array<string, array{book_id: string, book_title: string, category_name: string, times_borrowed: int}> $grouped */
        $grouped = [];

        foreach ($this->loansById as $loan) {
            if ($loan->loanedAt() < $range->from() || $loan->loanedAt() > $range->to()) {
                continue;
            }

            foreach ($loan->items() as $item) {
                // ADR-004: Group by frozen book_id + book_title + category_name
                $key = $item->bookId() . '|' . $item->bookTitle() . '|' . $item->categoryName();
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'book_id' => $item->bookId(),
                        'book_title' => $item->bookTitle(),
                        'category_name' => $item->categoryName(),
                        'times_borrowed' => 0,
                    ];
                }
                $grouped[$key]['times_borrowed']++;
            }
        }

        $rows = array_values($grouped);

        // Order by times_borrowed DESC, then book_title ASC (api-contract.md §3.7)
        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['times_borrowed'] <=> $a['times_borrowed'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['book_title'], $b['book_title']);
        });

        return $rows;
    }

    public function countActiveItemsForMember(string $memberId): int
    {
        $count = 0;
        foreach ($this->loansById as $loan) {
            if ($loan->memberId() !== $memberId) {
                continue;
            }
            foreach ($loan->items() as $item) {
                if (!$item->isReturned()) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function hasOverdueItemsForMember(string $memberId, DateTimeImmutable $now): bool
    {
        foreach ($this->loansById as $loan) {
            if ($loan->memberId() !== $memberId) {
                continue;
            }
            foreach ($loan->items() as $item) {
                if ($item->isOverdue($now)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasUnreturnedItemsForBook(string $bookId): bool
    {
        foreach ($this->loansById as $loan) {
            foreach ($loan->items() as $item) {
                if ($item->bookId() === $bookId && !$item->isReturned()) {
                    return true;
                }
            }
        }

        return false;
    }
}
