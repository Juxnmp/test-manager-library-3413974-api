<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Services\CreateBookService;
use App\Application\Services\LendBooksService;
use App\Application\Services\MemberService;
use App\Domain\Exceptions\BookNotAvailableException;
use App\Domain\Exceptions\DuplicateBookInLoanException;
use App\Domain\Exceptions\MemberHasOverdueException;
use App\Domain\Exceptions\MemberLimitExceededException;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeBookRepository;
use Tests\Fakes\FakeCategoryRepository;
use Tests\Fakes\FakeClock;
use Tests\Fakes\FakeCoverStorage;
use Tests\Fakes\FakeLoanRepository;
use Tests\Fakes\FakeMemberRepository;

final class LendBooksUseCaseTest extends TestCase
{
    private FakeBookRepository $books;
    private FakeCategoryRepository $categories;
    private FakeMemberRepository $members;
    private FakeLoanRepository $loans;
    private FakeClock $clock;
    private CreateBookService $createBookService;
    private MemberService $memberService;
    private LendBooksService $lendBooksService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->books = new FakeBookRepository();
        $this->categories = new FakeCategoryRepository();
        $this->members = new FakeMemberRepository();
        $this->loans = new FakeLoanRepository($this->books, $this->members);
        $this->clock = new FakeClock();

        $this->createBookService = new CreateBookService(
            $this->books,
            $this->categories,
            $this->clock,
            new FakeCoverStorage(),
        );
        $this->memberService = new MemberService($this->members);
        $this->lendBooksService = new LendBooksService(
            $this->members,
            $this->books,
            $this->loans,
            $this->clock,
        );
    }

    public function test_hu06_lend_books_discounts_copies_freezes_snapshot_and_enforces_all_limits(): void
    {
        $member = $this->memberService->create('Carlos Pérez', '1000200030');
        $b1 = $this->createBookService->execute('Libro A', 'Autor 1', '9780000000001', FakeCategoryRepository::NOVELA_ID, 2);
        $b2 = $this->createBookService->execute('Libro B', 'Autor 2', '9780000000002', FakeCategoryRepository::CIENCIA_ID, 1);
        $b3 = $this->createBookService->execute('Libro C', 'Autor 3', '9780000000003', FakeCategoryRepository::HISTORIA_ID, 1);
        $b4 = $this->createBookService->execute('Libro D', 'Autor 4', '9780000000004', FakeCategoryRepository::TECNOLOGIA_ID, 2);

        $librarianId = '99999999-9999-4999-8999-999999999999';

        // CA-06.2: Duplicate book in same request -> 422 DUPLICATE_BOOK_IN_LOAN
        try {
            $this->lendBooksService->execute($member->id(), $librarianId, [$b1->id(), $b1->id()]);
            $this->fail('Expected DuplicateBookInLoanException.');
        } catch (DuplicateBookInLoanException $e) {
            $this->assertSame('DUPLICATE_BOOK_IN_LOAN', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }

        // CA-06.1, CA-06.3, CA-06.6: Lend 2 books (b1 and b2)
        $loan = $this->lendBooksService->execute($member->id(), $librarianId, [$b1->id(), $b2->id()]);
        $this->assertCount(2, $loan->items());
        $this->assertSame(1, $this->books->findById($b1->id())?->availableCopies());
        $this->assertSame(0, $this->books->findById($b2->id())?->availableCopies());
        $this->assertSame('Libro A', $loan->items()[0]->bookTitle());
        $this->assertSame('Novela', $loan->items()[0]->categoryName());
        $this->assertSame('2026-10-28T14:15:00Z', $loan->items()[0]->dueAt()->format('Y-m-d\TH:i:s\Z'));

        // CA-06.3: Lending b2 (0 available copies) in a multi-item loan with b3 fails with 409 BOOK_NOT_AVAILABLE and registers nothing
        try {
            $this->lendBooksService->execute($member->id(), $librarianId, [$b2->id()]);
            $this->fail('Expected BookNotAvailableException.');
        } catch (BookNotAvailableException $e) {
            $this->assertSame('BOOK_NOT_AVAILABLE', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(2, $this->members->countActiveLoanItems($member->id()));
        }

        // CA-06.4: Member currently has 2 active items; requesting 2 more (b3, b4) exceeds max 3 -> 422 MEMBER_LIMIT_EXCEEDED
        try {
            $this->lendBooksService->execute($member->id(), $librarianId, [$b3->id(), $b4->id()]);
            $this->fail('Expected MemberLimitExceededException.');
        } catch (MemberLimitExceededException $e) {
            $this->assertSame('MEMBER_LIMIT_EXCEEDED', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertStringContainsString('2', $e->getMessage());
        }

        // CA-06.5: Advance clock 15 days so existing loan is overdue -> member cannot borrow b3 (409 MEMBER_HAS_OVERDUE)
        $this->clock->advanceDays(15);
        try {
            $this->lendBooksService->execute($member->id(), $librarianId, [$b3->id()]);
            $this->fail('Expected MemberHasOverdueException.');
        } catch (MemberHasOverdueException $e) {
            $this->assertSame('MEMBER_HAS_OVERDUE', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }
    }
}
