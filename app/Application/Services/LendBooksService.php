<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\BookRepository;
use App\Application\Ports\Outbound\Clock;
use App\Application\Ports\Outbound\LoanRepository;
use App\Application\Ports\Outbound\MemberRepository;
use App\Domain\Entities\Book;
use App\Domain\Entities\Loan;
use App\Domain\Exceptions\DuplicateBookInLoanException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\Uuid;

final readonly class LendBooksService
{
    public function __construct(
        private MemberRepository $members,
        private BookRepository $books,
        private LoanRepository $loans,
        private Clock $clock,
    ) {
    }

    /**
     * HU-06 · Registrar un préstamo (T-09, CA-06.1..CA-06.7, architecture.md §4).
     *
     * @param array<int, string> $bookIds
     */
    public function execute(string $memberId, string $loanedByUserId, array $bookIds): Loan
    {
        if (trim($memberId) === '' || !Uuid::isValid($memberId)) {
            throw ValidationException::forField('member_id', 'UUID obligatorio', 'El identificador del socio es obligatorio y debe ser un UUID válido.');
        }

        if ($bookIds === [] || count($bookIds) > Loan::MAX_ITEMS_PER_LOAN) {
            throw ValidationException::forField(
                'items',
                'debe contener entre 1 y 3 libros',
                'Un préstamo debe contener entre 1 y 3 líneas.'
            );
        }

        // CA-06.2: Check duplicate books in loan upfront before touching persistence
        $normalizedBookIds = [];
        $seen = [];
        foreach ($bookIds as $index => $rawBookId) {
            $trimmed = trim((string) $rawBookId);
            if ($trimmed === '' || !Uuid::isValid($trimmed)) {
                throw ValidationException::forField("items[{$index}].book_id", 'UUID inválido', 'El identificador del libro no es válido.');
            }
            if (isset($seen[$trimmed])) {
                throw new DuplicateBookInLoanException($trimmed);
            }
            $seen[$trimmed] = true;
            $normalizedBookIds[] = $trimmed;
        }

        $member = $this->members->findById(trim($memberId));
        if ($member === null) {
            throw new ResourceNotFoundException('El socio indicado no existe.');
        }

        $now = $this->clock->now();

        // CA-06.5: Un socio con algún libro vencido no puede pedir otro (409 MEMBER_HAS_OVERDUE)
        $hasOverdue = $this->members->hasOverdueItems($member->id(), $now);
        $currentActiveCount = $this->members->countActiveLoanItems($member->id());

        $loan = Loan::open(
            id: Uuid::v4(),
            memberId: $member->id(),
            loanedByUserId: $loanedByUserId,
            loanedAt: $now,
            currentActiveItems: $currentActiveCount,
            memberHasOverdue: $hasOverdue,
        );

        // Q3: Obtener libros por lote de ids
        $booksById = $this->books->findByIds($normalizedBookIds);

        /** @var array<int, Book> $orderedBooks */
        $orderedBooks = [];
        /** @var array<string, int> $expectedAvailableCopies */
        $expectedAvailableCopies = [];

        foreach ($normalizedBookIds as $bookId) {
            $book = $booksById[$bookId] ?? null;
            if ($book === null || $book->isDeleted()) {
                throw new ResourceNotFoundException("El libro «{$bookId}» no existe o fue dado de baja.");
            }
            $expectedAvailableCopies[$book->id()] = $book->availableCopies();
            $orderedBooks[] = $book;
        }

        // Loan.AddItem -> Book.Withdraw for each book (CA-06.3, CA-06.4, CA-06.6)
        foreach ($orderedBooks as $book) {
            $loan->addItem(
                itemId: Uuid::v4(),
                book: $book,
                categoryName: $book->categoryName() ?? '',
            );
        }

        $loan->assertValidItemCount();

        // Persist loan + items + updated books in one atomic transaction (CA-06.3, ADR-002)
        $this->loans->saveLoanWithBooks($loan, $orderedBooks, $expectedAvailableCopies);

        return $loan;
    }
}
