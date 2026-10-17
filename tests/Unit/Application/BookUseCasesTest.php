<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Services\CreateBookService;
use App\Application\Services\SearchBooksService;
use App\Domain\Exceptions\BookHasOpenLoansException;
use App\Domain\Exceptions\IsbnTakenException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeBookRepository;
use Tests\Fakes\FakeCategoryRepository;
use Tests\Fakes\FakeClock;
use Tests\Fakes\FakeCoverStorage;

final class BookUseCasesTest extends TestCase
{
    private FakeBookRepository $books;
    private FakeCategoryRepository $categories;
    private FakeClock $clock;
    private FakeCoverStorage $covers;
    private CreateBookService $createBookService;
    private SearchBooksService $searchBooksService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->books = new FakeBookRepository();
        $this->categories = new FakeCategoryRepository();
        $this->clock = new FakeClock();
        $this->covers = new FakeCoverStorage();

        $this->createBookService = new CreateBookService(
            $this->books,
            $this->categories,
            $this->clock,
            $this->covers,
        );
        $this->searchBooksService = new SearchBooksService($this->books);
    }

    public function test_hu03_create_edit_cover_and_soft_delete_book(): void
    {
        // CA-03.1: Create book -> available_copies == total_copies, cover_key == null
        $book = $this->createBookService->execute(
            title: 'Cien años de soledad',
            author: 'Gabriel García Márquez',
            rawIsbn: '9780307474728',
            categoryId: FakeCategoryRepository::NOVELA_ID,
            totalCopies: 4,
        );

        $this->assertSame(4, $book->totalCopies()->value());
        $this->assertSame(4, $book->availableCopies());
        $this->assertNull($book->coverKey());

        // CA-03.2: Duplicate active ISBN -> 409 ISBN_TAKEN
        try {
            $this->createBookService->execute(
                title: 'Otro Libro',
                author: 'Otro Autor',
                rawIsbn: '9780307474728',
                categoryId: FakeCategoryRepository::NOVELA_ID,
                totalCopies: 2,
            );
            $this->fail('Expected IsbnTakenException was not thrown.');
        } catch (IsbnTakenException $e) {
            $this->assertSame('ISBN_TAKEN', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        // CA-03.4: Upload valid PNG cover (magic header \x89PNG\r\n\x1a\n)
        $pngBytes = "\x89PNG\r\n\x1a\n" . str_repeat('A', 256);
        $updatedWithCover = $this->createBookService->uploadCover($book->id(), $pngBytes);
        $this->assertNotNull($updatedWithCover->coverKey());
        $coverKey = $updatedWithCover->coverKey();
        $this->assertTrue($this->covers->has($coverKey));

        // CA-03.4: Oversized cover (> 2 MB) -> 422 VALIDATION_ERROR
        $oversizedPng = "\x89PNG\r\n\x1a\n" . str_repeat('B', CreateBookService::MAX_COVER_BYTES + 10);
        try {
            $this->createBookService->uploadCover($book->id(), $oversizedPng);
            $this->fail('Expected ValidationException for >2MB cover.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->httpStatus());
        }

        // Remove cover -> cover_key becomes null and binary is deleted
        $withoutCover = $this->createBookService->deleteCover($book->id());
        $this->assertNull($withoutCover->coverKey());
        $this->assertFalse($this->covers->has($coverKey));

        // CA-03.5: Book with open loans cannot be deleted (409 BOOK_HAS_OPEN_LOANS)
        $this->books->markBookHasOpenLoans($book->id(), true);
        try {
            $this->createBookService->delete($book->id());
            $this->fail('Expected BookHasOpenLoansException was not thrown.');
        } catch (BookHasOpenLoansException $e) {
            $this->assertSame('BOOK_HAS_OPEN_LOANS', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        // Soft delete succeeds when no open loans exist
        $this->books->markBookHasOpenLoans($book->id(), false);
        $this->createBookService->delete($book->id());

        // CA-04.3: Getting soft-deleted book by ID throws 404 NOT_FOUND
        try {
            $this->searchBooksService->getById($book->id());
            $this->fail('Expected ResourceNotFoundException for soft-deleted book.');
        } catch (ResourceNotFoundException $e) {
            $this->assertSame('NOT_FOUND', $e->errorCode());
            $this->assertSame(404, $e->httpStatus());
        }

        // ADR-003: Soft-deleted ISBN can be registered again
        $recreated = $this->createBookService->execute(
            title: 'Cien años de soledad (Nueva Edición)',
            author: 'Gabriel García Márquez',
            rawIsbn: '9780307474728',
            categoryId: FakeCategoryRepository::NOVELA_ID,
            totalCopies: 3,
        );
        $this->assertSame(3, $recreated->availableCopies());
    }

    public function test_hu04_search_books_filters_orders_by_title_and_paginates(): void
    {
        $this->createBookService->execute('Rayuela', 'Julio Cortázar', '9788420431321', FakeCategoryRepository::NOVELA_ID, 2);
        $this->createBookService->execute('El Aleph', 'Jorge Luis Borges', '9788420633114', FakeCategoryRepository::NOVELA_ID, 3);
        $this->createBookService->execute('Breve historia del tiempo', 'Stephen Hawking', '9788498920536', FakeCategoryRepository::CIENCIA_ID, 1);

        // Search all ordered by title
        $all = $this->searchBooksService->search(null, null, 1, 20);
        $this->assertSame(3, $all['total']);
        $this->assertSame('Breve historia del tiempo', $all['items'][0]['title']);
        $this->assertSame('El Aleph', $all['items'][1]['title']);
        $this->assertSame('Rayuela', $all['items'][2]['title']);

        // Filter by category Novela + partial author query
        $filtered = $this->searchBooksService->search('borges', FakeCategoryRepository::NOVELA_ID, 1, 10);
        $this->assertSame(1, $filtered['total']);
        $this->assertSame('El Aleph', $filtered['items'][0]['title']);

        // Max page size > 50 -> 422 VALIDATION_ERROR
        $this->expectException(ValidationException::class);
        $this->searchBooksService->search(null, null, 1, 51);
    }
}
