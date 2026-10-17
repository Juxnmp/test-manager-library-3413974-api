<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Exceptions\BookHasOpenLoansException;
use App\Domain\Exceptions\BookNotAvailableException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use DateTimeImmutable;

final class Book
{
    private string $id;
    private string $title;
    private string $author;
    private Isbn $isbn;
    private string $categoryId;
    private ?string $categoryName;
    private Quantity $totalCopies;
    private int $availableCopies;
    private ?string $coverKey;
    private ?DateTimeImmutable $deletedAt;

    public function __construct(
        string $id,
        string $title,
        string $author,
        Isbn $isbn,
        string $categoryId,
        Quantity $totalCopies,
        int $availableCopies,
        ?string $coverKey = null,
        ?DateTimeImmutable $deletedAt = null,
        ?string $categoryName = null,
    ) {
        $trimmedId = trim($id);
        if ($trimmedId === '') {
            throw ValidationException::forField('id', 'obligatorio', 'El identificador del libro es obligatorio.');
        }

        $trimmedTitle = trim($title);
        if ($trimmedTitle === '' || mb_strlen($trimmedTitle, 'UTF-8') > 250) {
            throw ValidationException::forField('title', 'obligatorio y máximo 250 caracteres', 'El título del libro es obligatorio y no puede superar 250 caracteres.');
        }

        $trimmedAuthor = trim($author);
        if ($trimmedAuthor === '' || mb_strlen($trimmedAuthor, 'UTF-8') > 200) {
            throw ValidationException::forField('author', 'obligatorio y máximo 200 caracteres', 'El autor del libro es obligatorio y no puede superar 200 caracteres.');
        }

        $trimmedCategory = trim($categoryId);
        if ($trimmedCategory === '') {
            throw ValidationException::forField('category_id', 'obligatorio', 'La categoría del libro es obligatoria.');
        }

        if ($availableCopies < 0 || $availableCopies > $totalCopies->value()) {
            throw ValidationException::forField(
                'available_copies',
                'debe estar entre 0 y total_copies',
                'Los ejemplares disponibles deben estar entre 0 y el total de ejemplares.'
            );
        }

        // CA-03.4: sin portada el campo es null, nunca cadena vacía
        $normalizedCoverKey = ($coverKey !== null && trim($coverKey) !== '') ? trim($coverKey) : null;

        $this->id = $trimmedId;
        $this->title = $trimmedTitle;
        $this->author = $trimmedAuthor;
        $this->isbn = $isbn;
        $this->categoryId = $trimmedCategory;
        $this->categoryName = $categoryName;
        $this->totalCopies = $totalCopies;
        $this->availableCopies = $availableCopies;
        $this->coverKey = $normalizedCoverKey;
        $this->deletedAt = $deletedAt;
    }

    public static function create(
        string $id,
        string $title,
        string $author,
        Isbn $isbn,
        string $categoryId,
        Quantity $totalCopies,
        ?string $categoryName = null,
    ): self {
        return new self(
            id: $id,
            title: $title,
            author: $author,
            isbn: $isbn,
            categoryId: $categoryId,
            totalCopies: $totalCopies,
            availableCopies: $totalCopies->value(),
            coverKey: null,
            deletedAt: null,
            categoryName: $categoryName,
        );
    }

    /**
     * Withdraw 1 available copy (CA-06.3, RN-1).
     */
    public function withdraw(int $itemIndex = 0): void
    {
        if ($this->isDeleted() || $this->availableCopies <= 0) {
            throw new BookNotAvailableException($this->title, $itemIndex);
        }

        $this->availableCopies -= 1;
    }

    /**
     * Restock 1 returned copy (CA-07.1, CA-07.3, RN-1).
     */
    public function restock(): void
    {
        if ($this->availableCopies >= $this->totalCopies->value()) {
            throw ValidationException::forField(
                'available_copies',
                'no puede superar total_copies',
                'Los ejemplares disponibles jamás pueden superar el total de ejemplares.'
            );
        }

        $this->availableCopies += 1;
    }

    /**
     * Update book attributes and adjust available_copies by the same delta (CA-03.3).
     */
    public function updateDetails(
        string $title,
        string $author,
        string $categoryId,
        Quantity $newTotalCopies,
        ?string $categoryName = null,
    ): void {
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '' || mb_strlen($trimmedTitle, 'UTF-8') > 250) {
            throw ValidationException::forField('title', 'obligatorio y máximo 250 caracteres', 'El título del libro es obligatorio y no puede superar 250 caracteres.');
        }

        $trimmedAuthor = trim($author);
        if ($trimmedAuthor === '' || mb_strlen($trimmedAuthor, 'UTF-8') > 200) {
            throw ValidationException::forField('author', 'obligatorio y máximo 200 caracteres', 'El autor del libro es obligatorio y no puede superar 200 caracteres.');
        }

        $trimmedCategory = trim($categoryId);
        if ($trimmedCategory === '') {
            throw ValidationException::forField('category_id', 'obligatorio', 'La categoría del libro es obligatoria.');
        }

        $delta = $newTotalCopies->value() - $this->totalCopies->value();
        $newAvailable = $this->availableCopies + $delta;

        if ($newAvailable < 0) {
            throw ValidationException::forField(
                'total_copies',
                'deja ejemplares disponibles en negativo',
                'El nuevo total de ejemplares dejaría los disponibles por debajo de cero.'
            );
        }

        $this->title = $trimmedTitle;
        $this->author = $trimmedAuthor;
        $this->categoryId = $trimmedCategory;
        if ($categoryName !== null) {
            $this->categoryName = $categoryName;
        }
        $this->totalCopies = $newTotalCopies;
        $this->availableCopies = $newAvailable;
    }

    /**
     * Logical deletion (CA-03.5, ADR-003).
     */
    public function softDelete(DateTimeImmutable $now, bool $hasOpenLoans = false): void
    {
        if ($hasOpenLoans) {
            throw new BookHasOpenLoansException($this->title);
        }

        $this->deletedAt = $now;
    }

    public function assignCover(string $coverKey): void
    {
        $trimmed = trim($coverKey);
        if ($trimmed === '') {
            throw ValidationException::forField('cover_key', 'no puede ser cadena vacía', 'La clave de portada no puede ser una cadena vacía.');
        }

        $this->coverKey = $trimmed;
    }

    public function removeCover(): void
    {
        $this->coverKey = null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function author(): string
    {
        return $this->author;
    }

    public function isbn(): Isbn
    {
        return $this->isbn;
    }

    public function categoryId(): string
    {
        return $this->categoryId;
    }

    public function categoryName(): ?string
    {
        return $this->categoryName;
    }

    public function withCategoryName(string $categoryName): void
    {
        $this->categoryName = $categoryName;
    }

    public function totalCopies(): Quantity
    {
        return $this->totalCopies;
    }

    public function availableCopies(): int
    {
        return $this->availableCopies;
    }

    public function coverKey(): ?string
    {
        return $this->coverKey;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'isbn' => $this->isbn->value(),
            'category' => [
                'id' => $this->categoryId,
                'name' => $this->categoryName ?? '',
            ],
            'total_copies' => $this->totalCopies->value(),
            'available_copies' => $this->availableCopies,
            'cover_key' => $this->coverKey,
        ];
    }
}
