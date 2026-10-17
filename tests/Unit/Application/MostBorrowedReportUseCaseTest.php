<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Services\CreateBookService;
use App\Application\Services\LendBooksService;
use App\Application\Services\MemberService;
use App\Application\Services\MostBorrowedReportService;
use App\Application\Services\ReturnItemService;
use App\Domain\Exceptions\InvalidDateRangeException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeBookRepository;
use Tests\Fakes\FakeCategoryRepository;
use Tests\Fakes\FakeClock;
use Tests\Fakes\FakeCoverStorage;
use Tests\Fakes\FakeLoanRepository;
use Tests\Fakes\FakeMemberRepository;

final class MostBorrowedReportUseCaseTest extends TestCase
{
    private FakeBookRepository $books;
    private FakeCategoryRepository $categories;
    private FakeMemberRepository $members;
    private FakeLoanRepository $loans;
    private FakeClock $clock;
    private CreateBookService $createBookService;
    private MemberService $memberService;
    private LendBooksService $lendBooksService;
    private ReturnItemService $returnItemService;
    private MostBorrowedReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->books = new FakeBookRepository();
        $this->categories = new FakeCategoryRepository();
        $this->members = new FakeMemberRepository();
        $this->loans = new FakeLoanRepository($this->books, $this->members);
        $this->clock = new FakeClock(new DateTimeImmutable('2026-10-10T12:00:00Z', new DateTimeZone('UTC')));

        $this->createBookService = new CreateBookService(
            $this->books,
            $this->categories,
            $this->clock,
            new FakeCoverStorage(),
        );
        $this->memberService = new MemberService($this->members);
        $this->lendBooksService = new LendBooksService($this->members, $this->books, $this->loans, $this->clock);
        $this->returnItemService = new ReturnItemService($this->loans, $this->books, $this->clock);
        $this->reportService = new MostBorrowedReportService($this->loans);
    }

    public function test_hu10_report_preserves_frozen_book_title_and_category_after_live_catalog_rename_adr004(): void
    {
        $member = $this->memberService->create('Ana Torres', '3000111222');
        $book1 = $this->createBookService->execute(
            title: 'Cien años de soledad',
            author: 'Gabriel García Márquez',
            rawIsbn: '9780307474728',
            categoryId: FakeCategoryRepository::NOVELA_ID,
            totalCopies: 3,
        );
        $book2 = $this->createBookService->execute(
            title: 'El origen de las especies',
            author: 'Charles Darwin',
            rawIsbn: '9788420656076',
            categoryId: FakeCategoryRepository::CIENCIA_ID,
            totalCopies: 3,
        );

        $librarianId = '99999999-9999-4999-8999-999999999999';

        // Borrow book1 and book2 on Oct 10
        $loan1 = $this->lendBooksService->execute($member->id(), $librarianId, [$book1->id(), $book2->id()]);
        $this->returnItemService->execute($loan1->id(), $loan1->items()[0]->id());

        // Borrow book1 a second time on Oct 12
        $this->clock->advanceDays(2);
        $this->lendBooksService->execute($member->id(), $librarianId, [$book1->id()]);

        // ADR-004 / CA-10.4: Rename book1 in live catalog after loans were registered
        $this->createBookService->update(
            bookId: $book1->id(),
            title: 'Cien años de soledad (Edición Conmemorativa Renombrada)',
            author: 'Gabriel García Márquez',
            categoryId: FakeCategoryRepository::NOVELA_ID,
            totalCopies: 3,
        );

        // Request report for October
        $report = $this->reportService->execute('2026-10-01', '2026-10-31');

        $this->assertSame('2026-10-01', $report['from']);
        $this->assertSame('2026-10-31', $report['to']);
        $this->assertCount(2, $report['rows']);

        // Ordered by times_borrowed DESC, preserving the OLD frozen title "Cien años de soledad"
        $this->assertSame([
            [
                'book_id' => $book1->id(),
                'book_title' => 'Cien años de soledad',
                'category_name' => 'Novela',
                'times_borrowed' => 2,
            ],
            [
                'book_id' => $book2->id(),
                'book_title' => 'El origen de las especies',
                'category_name' => 'Ciencia',
                'times_borrowed' => 1,
            ],
        ], $report['rows']);

        // CA-10.2: Range without loans returns empty rows, not error
        $emptyReport = $this->reportService->execute('2026-09-01', '2026-09-30');
        $this->assertSame([], $emptyReport['rows']);

        // CA-10.2: End before start throws 422 INVALID_DATE_RANGE
        try {
            $this->reportService->execute('2026-10-31', '2026-10-01');
            $this->fail('Expected InvalidDateRangeException.');
        } catch (InvalidDateRangeException $e) {
            $this->assertSame('INVALID_DATE_RANGE', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }
    }
}
