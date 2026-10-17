<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Book;
use App\Domain\Exceptions\BookHasOpenLoansException;
use App\Domain\Exceptions\BookNotAvailableException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BookTest extends TestCase
{
    private function makeBook(int $total = 2): Book
    {
        return Book::create(
            id: 'a1111111-1111-4111-8111-111111111111',
            title: ' Cien años de soledad ',
            author: ' Gabriel García Márquez ',
            isbn: new Isbn('9780307474728'),
            categoryId: '11111111-1111-4111-8111-111111111111',
            totalCopies: new Quantity($total),
            categoryName: 'Novela',
        );
    }

    public function test_create_book_starts_with_available_equal_to_total_and_null_cover(): void
    {
        $book = $this->makeBook(4);

        $this->assertSame('Cien años de soledad', $book->title());
        $this->assertSame('Gabriel García Márquez', $book->author());
        $this->assertSame(4, $book->totalCopies()->value());
        $this->assertSame(4, $book->availableCopies());
        $this->assertNull($book->coverKey());
        $this->assertFalse($book->isDeleted());
    }

    public function test_withdraw_never_leaves_available_below_zero(): void
    {
        $book = $this->makeBook(1);
        $book->withdraw();
        $this->assertSame(0, $book->availableCopies());

        try {
            $book->withdraw();
            $this->fail('Expected BookNotAvailableException when available_copies is 0.');
        } catch (BookNotAvailableException $e) {
            $this->assertSame('BOOK_NOT_AVAILABLE', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(0, $book->availableCopies());
        }
    }

    public function test_restock_never_exceeds_total_copies(): void
    {
        $book = $this->makeBook(2);
        $book->withdraw();
        $this->assertSame(1, $book->availableCopies());

        $book->restock();
        $this->assertSame(2, $book->availableCopies());

        try {
            $book->restock();
            $this->fail('Expected ValidationException when restocking above total_copies.');
        } catch (ValidationException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame(2, $book->availableCopies());
        }
    }

    public function test_update_details_adjusts_available_by_same_delta_and_rejects_negative(): void
    {
        $book = $this->makeBook(4);
        $book->withdraw(); // available = 3, loaned = 1
        $book->withdraw(); // available = 2, loaned = 2

        // Increase total from 4 to 6 (+2) -> available becomes 4
        $book->updateDetails('Título Editado', 'Autor', $book->categoryId(), new Quantity(6), 'Novela');
        $this->assertSame(6, $book->totalCopies()->value());
        $this->assertSame(4, $book->availableCopies());

        // Decrease total from 6 to 2 (-4) -> available becomes 0 (valid)
        $book->updateDetails('Título Editado', 'Autor', $book->categoryId(), new Quantity(2), 'Novela');
        $this->assertSame(2, $book->totalCopies()->value());
        $this->assertSame(0, $book->availableCopies());

        // Attempt to decrease total from 2 to 1 (-1) while 2 copies are loaned -> available would be -1 (422)
        try {
            $book->updateDetails('Título Editado', 'Autor', $book->categoryId(), new Quantity(1), 'Novela');
            $this->fail('Expected ValidationException when new total leaves available < 0.');
        } catch (ValidationException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame(2, $book->totalCopies()->value());
            $this->assertSame(0, $book->availableCopies());
        }
    }

    public function test_soft_delete_rejects_book_with_open_loans_and_marks_deleted_otherwise(): void
    {
        $book = $this->makeBook(2);
        $now = new DateTimeImmutable('2026-10-14T14:15:00Z', new DateTimeZone('UTC'));

        try {
            $book->softDelete($now, true);
            $this->fail('Expected BookHasOpenLoansException when book has open loans.');
        } catch (BookHasOpenLoansException $e) {
            $this->assertSame('BOOK_HAS_OPEN_LOANS', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertFalse($book->isDeleted());
        }

        $book->softDelete($now, false);
        $this->assertTrue($book->isDeleted());
        $this->assertSame($now, $book->deletedAt());
    }
}
