<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Exceptions\ItemAlreadyReturnedException;
use App\Domain\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class LoanItem
{
    private string $id;
    private string $loanId;
    private string $bookId;
    private string $bookTitle;
    private string $isbn;
    private string $categoryName;
    private DateTimeImmutable $dueAt;
    private ?DateTimeImmutable $returnedAt;

    public function __construct(
        string $id,
        string $loanId,
        string $bookId,
        string $bookTitle,
        string $isbn,
        string $categoryName,
        DateTimeImmutable $dueAt,
        ?DateTimeImmutable $returnedAt = null,
    ) {
        if (trim($id) === '' || trim($loanId) === '' || trim($bookId) === '') {
            throw ValidationException::forField('items', 'identificadores de línea obligatorios', 'Los identificadores de la línea de préstamo son obligatorios.');
        }

        $utc = new DateTimeZone('UTC');
        $this->id = trim($id);
        $this->loanId = trim($loanId);
        $this->bookId = trim($bookId);
        $this->bookTitle = trim($bookTitle);
        $this->isbn = trim($isbn);
        $this->categoryName = trim($categoryName);
        $this->dueAt = $dueAt->setTimezone($utc);
        $this->returnedAt = $returnedAt?->setTimezone($utc);
    }

    public function markReturned(DateTimeImmutable $returnedAt): void
    {
        if ($this->returnedAt !== null) {
            throw new ItemAlreadyReturnedException();
        }

        $this->returnedAt = $returnedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function isReturned(): bool
    {
        return $this->returnedAt !== null;
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        if ($this->returnedAt !== null) {
            return false;
        }

        return $this->dueAt < $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function daysLate(DateTimeImmutable $now): int
    {
        if (!$this->isOverdue($now)) {
            return 0;
        }

        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $seconds = $nowUtc->getTimestamp() - $this->dueAt->getTimestamp();

        return max(1, (int) ceil($seconds / 86400));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function loanId(): string
    {
        return $this->loanId;
    }

    public function bookId(): string
    {
        return $this->bookId;
    }

    public function bookTitle(): string
    {
        return $this->bookTitle;
    }

    public function isbn(): string
    {
        return $this->isbn;
    }

    public function categoryName(): string
    {
        return $this->categoryName;
    }

    public function dueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function returnedAt(): ?DateTimeImmutable
    {
        return $this->returnedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->bookId,
            'book_title' => $this->bookTitle,
            'isbn' => $this->isbn,
            'category_name' => $this->categoryName,
            'due_at' => $this->dueAt->format('Y-m-d\TH:i:s\Z'),
            'returned_at' => $this->returnedAt?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
