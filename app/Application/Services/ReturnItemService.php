<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\BookRepository;
use App\Application\Ports\Outbound\Clock;
use App\Application\Ports\Outbound\LoanRepository;
use App\Domain\Entities\LoanItem;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\ValueObjects\Uuid;

final readonly class ReturnItemService
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private Clock $clock,
    ) {
    }

    /**
     * HU-07 · Registrar una devolución por línea (T-10, CA-07.1..CA-07.4).
     */
    public function execute(string $loanId, string $itemId): LoanItem
    {
        if (!Uuid::isValid($loanId) || !Uuid::isValid($itemId)) {
            throw new ResourceNotFoundException('El préstamo o la línea indicada no existe.');
        }

        $loan = $this->loans->findById(trim($loanId));
        if ($loan === null) {
            throw new ResourceNotFoundException('El préstamo solicitado no existe.');
        }

        $item = $loan->findItem(trim($itemId));
        if ($item === null) {
            throw new ResourceNotFoundException('La línea de préstamo solicitada no existe.');
        }

        $book = $this->books->findById($item->bookId());
        if ($book === null) {
            throw new ResourceNotFoundException('El libro asociado a la línea no existe.');
        }

        $expectedAvailable = $book->availableCopies();
        $returnedItem = $loan->returnItem($item->id(), $book, $this->clock->now());

        $this->loans->saveReturnedItemWithBook($returnedItem, $book, $expectedAvailable);

        return $returnedItem;
    }
}
