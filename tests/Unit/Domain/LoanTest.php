<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\Exceptions\DuplicateBookInLoanException;
use App\Domain\Exceptions\ItemAlreadyReturnedException;
use App\Domain\Exceptions\MemberHasOverdueException;
use App\Domain\Exceptions\MemberLimitExceededException;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeClock;

final class LoanTest extends TestCase
{
    private function createBook(string $id, string $title, string $isbn, int $copies = 2): Book
    {
        return Book::create(
            id: $id,
            title: $title,
            author: 'Autor Prueba',
            isbn: new Isbn($isbn),
            categoryId: '11111111-1111-4111-8111-111111111111',
            totalCopies: new Quantity($copies),
            categoryName: 'Novela',
        );
    }

    public function test_loan_calculates_open_overdue_and_closed_with_fake_clock_and_14_days_due_date(): void
    {
        $clock = new FakeClock();
        $book1 = $this->createBook('b1111111-1111-4111-8111-111111111111', 'Libro 1', '9780307474721', 2);
        $book2 = $this->createBook('b2222222-2222-4222-8222-222222222222', 'Libro 2', '9780307474722', 1);

        $loan = Loan::open(
            id: 'c1111111-1111-4111-8111-111111111111',
            memberId: 'd1111111-1111-4111-8111-111111111111',
            loanedByUserId: 'e1111111-1111-4111-8111-111111111111',
            loanedAt: $clock->now(),
        );

        $item1 = $loan->addItem('f1111111-1111-4111-8111-111111111111', $book1, 'Novela');
        $item2 = $loan->addItem('f2222222-2222-4222-8222-222222222222', $book2, 'Novela');

        // Withdrawals happened atomically inside AddItem
        $this->assertSame(1, $book1->availableCopies());
        $this->assertSame(0, $book2->availableCopies());

        // Due date is loaned_at + 14 days
        $this->assertSame('2026-10-28T14:15:00Z', $item1->dueAt()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('open', $loan->status($clock->now()));

        // Advance clock 14 days -> exactly on due_at is still open
        $clock->advanceDays(14);
        $this->assertSame('open', $loan->status($clock->now()));

        // Advance 1 more day (15 days total) -> overdue
        $clock->advanceDays(1);
        $this->assertSame('overdue', $loan->status($clock->now()));
        $this->assertSame(1, $item1->daysLate($clock->now()));

        // Return first item -> still overdue because item2 is unreturned
        $loan->returnItem($item1->id(), $book1, $clock->now());
        $this->assertSame(2, $book1->availableCopies());
        $this->assertSame('overdue', $loan->status($clock->now()));

        // Return second item -> loan becomes closed
        $loan->returnItem($item2->id(), $book2, $clock->now());
        $this->assertSame(1, $book2->availableCopies());
        $this->assertSame('closed', $loan->status($clock->now()));
    }

    public function test_return_item_twice_throws_item_already_returned_and_never_increments_twice(): void
    {
        $clock = new FakeClock();
        $book = $this->createBook('b1111111-1111-4111-8111-111111111111', 'Libro Único', '9780307474721', 2);

        $loan = Loan::open(
            id: 'c1111111-1111-4111-8111-111111111111',
            memberId: 'd1111111-1111-4111-8111-111111111111',
            loanedByUserId: 'e1111111-1111-4111-8111-111111111111',
            loanedAt: $clock->now(),
        );

        $item = $loan->addItem('f1111111-1111-4111-8111-111111111111', $book, 'Novela');
        $this->assertSame(1, $book->availableCopies());

        $loan->returnItem($item->id(), $book, $clock->now());
        $this->assertSame(2, $book->availableCopies());

        try {
            $loan->returnItem($item->id(), $book, $clock->now());
            $this->fail('Expected ItemAlreadyReturnedException was not thrown.');
        } catch (ItemAlreadyReturnedException $e) {
            $this->assertSame('ITEM_ALREADY_RETURNED', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(2, $book->availableCopies());
        }
    }

    public function test_loan_enforces_no_duplicate_books_max_3_items_and_no_overdue_member(): void
    {
        $clock = new FakeClock();
        $book1 = $this->createBook('b1111111-1111-4111-8111-111111111111', 'Libro 1', '9780307474721', 3);
        $book2 = $this->createBook('b2222222-2222-4222-8222-222222222222', 'Libro 2', '9780307474722', 3);

        // Member with overdue items cannot open a loan (409 MEMBER_HAS_OVERDUE)
        try {
            Loan::open('c1', 'm1', 'u1', $clock->now(), currentActiveItems: 1, memberHasOverdue: true);
            $this->fail('Expected MemberHasOverdueException was not thrown.');
        } catch (MemberHasOverdueException $e) {
            $this->assertSame('MEMBER_HAS_OVERDUE', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        // Duplicate book in same loan (422 DUPLICATE_BOOK_IN_LOAN)
        $loan = Loan::open('c1', 'm1', 'u1', $clock->now(), currentActiveItems: 2, memberHasOverdue: false);
        $loan->addItem('i1', $book1, 'Novela');

        try {
            $loan->addItem('i2', $book1, 'Novela');
            $this->fail('Expected DuplicateBookInLoanException was not thrown.');
        } catch (DuplicateBookInLoanException $e) {
            $this->assertSame('DUPLICATE_BOOK_IN_LOAN', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }

        // Exceeding 3 active items for member (2 existing + 2 new = 4 > 3 -> 422 MEMBER_LIMIT_EXCEEDED)
        try {
            $loan->addItem('i2', $book2, 'Novela');
            $this->fail('Expected MemberLimitExceededException was not thrown.');
        } catch (MemberLimitExceededException $e) {
            $this->assertSame('MEMBER_LIMIT_EXCEEDED', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }
    }
}
