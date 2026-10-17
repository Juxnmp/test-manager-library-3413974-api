<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Services\CreateBookService;
use App\Application\Services\LendBooksService;
use App\Application\Services\MemberService;
use App\Application\Services\OverdueService;
use App\Application\Services\ReturnItemService;
use App\Domain\Exceptions\ItemAlreadyReturnedException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeBookRepository;
use Tests\Fakes\FakeCategoryRepository;
use Tests\Fakes\FakeClock;
use Tests\Fakes\FakeCoverStorage;
use Tests\Fakes\FakeLoanRepository;
use Tests\Fakes\FakeMemberRepository;

final class ReturnAndOverdueUseCasesTest extends TestCase
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
    private OverdueService $overdueService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->books = new FakeBookRepository();
        $this->categories = new FakeCategoryRepository();
        $this->members = new FakeMemberRepository();
        $this->loans = new FakeLoanRepository($this->books, $this->members);
        $this->clock = new FakeClock(new DateTimeImmutable('2026-10-01T10:00:00Z', new DateTimeZone('UTC')));

        $this->createBookService = new CreateBookService(
            $this->books,
            $this->categories,
            $this->clock,
            new FakeCoverStorage(),
        );
        $this->memberService = new MemberService($this->members);
        $this->lendBooksService = new LendBooksService($this->members, $this->books, $this->loans, $this->clock);
        $this->returnItemService = new ReturnItemService($this->loans, $this->books, $this->clock);
        $this->overdueService = new OverdueService($this->loans, $this->clock);
    }

    public function test_hu07_and_hu09_return_item_and_list_overdue_ordered_by_days_late(): void
    {
        $member1 = $this->memberService->create('María López', '2000111222');
        $member2 = $this->memberService->create('Pedro Gómez', '2000111333');
        $b1 = $this->createBookService->execute('Rayuela', 'Julio Cortázar', '9780000000011', FakeCategoryRepository::NOVELA_ID, 1);
        $b2 = $this->createBookService->execute('Ficciones', 'Jorge Luis Borges', '9780000000012', FakeCategoryRepository::NOVELA_ID, 1);

        $librarianId = '99999999-9999-4999-8999-999999999999';

        // Day 0 (Oct 1): Member 1 borrows b1 (due Oct 15)
        $loan1 = $this->lendBooksService->execute($member1->id(), $librarianId, [$b1->id()]);

        // Day 3 (Oct 4): Member 2 borrows b2 (due Oct 18)
        $this->clock->advanceDays(3);
        $loan2 = $this->lendBooksService->execute($member2->id(), $librarianId, [$b2->id()]);

        // Advance to Oct 20 -> loan1 is 5 days late, loan2 is 2 days late
        $this->clock->setNow(new DateTimeImmutable('2026-10-20T10:00:00Z', new DateTimeZone('UTC')));

        $overdue = $this->overdueService->listOverdue();
        $this->assertCount(2, $overdue['items']);
        // CA-09.2: Ordered by most days late first
        $this->assertSame('Rayuela', $overdue['items'][0]['book_title']);
        $this->assertSame('María López', $overdue['items'][0]['member_name']);
        $this->assertSame(5, $overdue['items'][0]['days_late']);

        $this->assertSame('Ficciones', $overdue['items'][1]['book_title']);
        $this->assertSame('Pedro Gómez', $overdue['items'][1]['member_name']);
        $this->assertSame(2, $overdue['items'][1]['days_late']);

        // CA-07.1 & CA-07.4: Return loan1 item -> available_copies becomes 1, loan1 status becomes closed
        $item1Id = $loan1->items()[0]->id();
        $returned = $this->returnItemService->execute($loan1->id(), $item1Id);
        $this->assertNotNull($returned->returnedAt());
        $this->assertSame(1, $this->books->findById($b1->id())?->availableCopies());
        $this->assertSame('closed', $this->overdueService->getLoanById($loan1->id())['status']);

        // CA-07.2: Returning same item twice throws 409 ITEM_ALREADY_RETURNED and does not increment again
        try {
            $this->returnItemService->execute($loan1->id(), $item1Id);
            $this->fail('Expected ItemAlreadyReturnedException.');
        } catch (ItemAlreadyReturnedException $e) {
            $this->assertSame('ITEM_ALREADY_RETURNED', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(1, $this->books->findById($b1->id())?->availableCopies());
        }

        // Now overdue list has only 1 item left (Ficciones)
        $overdueAfter = $this->overdueService->listOverdue();
        $this->assertCount(1, $overdueAfter['items']);
        $this->assertSame('Ficciones', $overdueAfter['items'][0]['book_title']);
    }
}
