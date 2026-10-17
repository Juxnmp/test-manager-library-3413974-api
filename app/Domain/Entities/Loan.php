<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Exceptions\DuplicateBookInLoanException;
use App\Domain\Exceptions\MemberHasOverdueException;
use App\Domain\Exceptions\MemberLimitExceededException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class Loan
{
    public const int MAX_ITEMS_PER_LOAN = 3;
    public const int MAX_ACTIVE_ITEMS_PER_MEMBER = 3;
    public const int LOAN_DAYS = 14;

    private string $id;
    private string $memberId;
    private string $loanedByUserId;
    private DateTimeImmutable $loanedAt;
    /** @var array<int, LoanItem> */
    private array $items;
    private int $initialMemberActiveItems;

    /**
     * @param array<int, LoanItem> $items
     */
    public function __construct(
        string $id,
        string $memberId,
        string $loanedByUserId,
        DateTimeImmutable $loanedAt,
        array $items = [],
        int $initialMemberActiveItems = 0,
    ) {
        if (trim($id) === '' || trim($memberId) === '' || trim($loanedByUserId) === '') {
            throw ValidationException::forField('loan', 'identificadores de préstamo obligatorios', 'Los identificadores del préstamo son obligatorios.');
        }

        $utc = new DateTimeZone('UTC');
        $this->id = trim($id);
        $this->memberId = trim($memberId);
        $this->loanedByUserId = trim($loanedByUserId);
        $this->loanedAt = $loanedAt->setTimezone($utc);
        $this->items = array_values($items);
        $this->initialMemberActiveItems = $initialMemberActiveItems;
    }

    /**
     * Start a new loan enforcing member overdue check (CA-06.5).
     */
    public static function open(
        string $id,
        string $memberId,
        string $loanedByUserId,
        DateTimeImmutable $loanedAt,
        int $currentActiveItems = 0,
        bool $memberHasOverdue = false,
    ): self {
        if ($memberHasOverdue) {
            throw new MemberHasOverdueException();
        }

        return new self(
            id: $id,
            memberId: $memberId,
            loanedByUserId: $loanedByUserId,
            loanedAt: $loanedAt,
            items: [],
            initialMemberActiveItems: $currentActiveItems,
        );
    }

    /**
     * Add a line to the loan, withdrawing 1 copy from the Book in the same operation
     * and freezing book_title, isbn, and category_name (CA-06.1..CA-06.6, architecture.md §3).
     */
    public function addItem(string $itemId, Book $book, ?string $categoryName = null): LoanItem
    {
        foreach ($this->items as $existing) {
            if ($existing->bookId() === $book->id()) {
                throw new DuplicateBookInLoanException($book->id());
            }
        }

        if (count($this->items) >= self::MAX_ITEMS_PER_LOAN) {
            throw ValidationException::forField(
                'items',
                'un préstamo solo admite entre 1 y 3 líneas',
                'Un préstamo debe contener entre 1 y 3 libros.'
            );
        }

        $projectedTotalActive = $this->initialMemberActiveItems + count($this->items) + 1;
        if ($projectedTotalActive > self::MAX_ACTIVE_ITEMS_PER_MEMBER) {
            throw new MemberLimitExceededException(
                $this->initialMemberActiveItems,
                count($this->items) + 1
            );
        }

        $resolvedCategoryName = $categoryName ?? $book->categoryName() ?? '';
        if (trim($resolvedCategoryName) === '') {
            throw ValidationException::forField('category_name', 'obligatorio para congelar en la línea', 'El nombre de la categoría es obligatorio para la línea de préstamo.');
        }

        // Withdraw 1 copy from Book before creating the line (architecture.md §3)
        $book->withdraw(count($this->items));

        $dueAt = $this->loanedAt->modify('+' . self::LOAN_DAYS . ' days');

        $item = new LoanItem(
            id: $itemId,
            loanId: $this->id,
            bookId: $book->id(),
            bookTitle: $book->title(),
            isbn: $book->isbn()->value(),
            categoryName: $resolvedCategoryName,
            dueAt: $dueAt,
            returnedAt: null,
        );

        $this->items[] = $item;

        return $item;
    }

    /**
     * Ensure loan has between 1 and 3 items (CA-06.1).
     */
    public function assertValidItemCount(): void
    {
        $count = count($this->items);
        if ($count < 1 || $count > self::MAX_ITEMS_PER_LOAN) {
            throw ValidationException::forField(
                'items',
                'debe contener entre 1 y 3 libros',
                'Un préstamo debe incluir entre 1 y 3 libros.'
            );
        }
    }

    /**
     * Return a single item and restock its Book once (CA-07.1..CA-07.4, architecture.md §3).
     */
    public function returnItem(string $itemId, Book $book, DateTimeImmutable $returnedAt): LoanItem
    {
        $target = null;
        foreach ($this->items as $item) {
            if ($item->id() === $itemId) {
                $target = $item;
                break;
            }
        }

        if ($target === null) {
            throw new ResourceNotFoundException('La línea de préstamo solicitada no existe.');
        }

        if ($target->bookId() !== $book->id()) {
            throw ValidationException::forField('book_id', 'no coincide con el libro de la línea', 'El libro no corresponde a la línea del préstamo.');
        }

        // Marks returnedAt (throws ITEM_ALREADY_RETURNED if already returned) and restocks Book once
        $target->markReturned($returnedAt);
        $book->restock();

        return $target;
    }

    /**
     * Dynamically calculate loan status: 'open', 'overdue', or 'closed' (CA-08.1, CA-08.3, Art. VII).
     */
    public function status(DateTimeImmutable $now): string
    {
        if ($this->items === []) {
            return 'closed';
        }

        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $allReturned = true;
        $hasOverdue = false;

        foreach ($this->items as $item) {
            if (!$item->isReturned()) {
                $allReturned = false;
                if ($item->isOverdue($nowUtc)) {
                    $hasOverdue = true;
                }
            }
        }

        if ($allReturned) {
            return 'closed';
        }

        if ($hasOverdue) {
            return 'overdue';
        }

        return 'open';
    }

    public function id(): string
    {
        return $this->id;
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function loanedByUserId(): string
    {
        return $this->loanedByUserId;
    }

    public function loanedAt(): DateTimeImmutable
    {
        return $this->loanedAt;
    }

    /**
     * @return array<int, LoanItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function findItem(string $itemId): ?LoanItem
    {
        foreach ($this->items as $item) {
            if ($item->id() === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(DateTimeImmutable $now): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->memberId,
            'loaned_at' => $this->loanedAt->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->status($now),
            'items' => array_map(static fn (LoanItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
