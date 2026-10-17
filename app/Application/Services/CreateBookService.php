<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\BookRepository;
use App\Application\Ports\Outbound\CategoryRepository;
use App\Application\Ports\Outbound\Clock;
use App\Application\Ports\Outbound\CoverStorage;
use App\Domain\Entities\Book;
use App\Domain\Exceptions\IsbnTakenException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use App\Domain\ValueObjects\Uuid;

final readonly class CreateBookService
{
    public const int MAX_COVER_BYTES = 2097152; // 2 MB
    private const array ALLOWED_MIMES = ['image/jpeg', 'image/png'];

    public function __construct(
        private BookRepository $books,
        private CategoryRepository $categories,
        private Clock $clock,
        private CoverStorage $coverStorage,
    ) {
    }

    /**
     * HU-03 · Crear libro (CA-03.1, CA-03.2, CA-03.3).
     */
    public function execute(
        string $title,
        string $author,
        string $rawIsbn,
        string $categoryId,
        int $totalCopies,
    ): Book {
        $isbn = new Isbn($rawIsbn);
        $quantity = new Quantity($totalCopies, 'total_copies');

        $category = $this->categories->findById(trim($categoryId));
        if ($category === null) {
            throw ValidationException::forField('category_id', 'categoría inexistente', 'La categoría indicada no existe.');
        }

        if ($this->books->existsActiveByIsbn($isbn->value())) {
            throw new IsbnTakenException($isbn->value());
        }

        $book = Book::create(
            id: Uuid::v4(),
            title: $title,
            author: $author,
            isbn: $isbn,
            categoryId: $category['id'],
            totalCopies: $quantity,
            categoryName: $category['name'],
        );

        $this->books->save($book);

        return $book;
    }

    /**
     * HU-03 · Editar libro: título, autor, categoría y total_copies (CA-03.3).
     */
    public function update(
        string $bookId,
        string $title,
        string $author,
        string $categoryId,
        int $totalCopies,
    ): Book {
        $book = $this->findActiveBookOrFail($bookId);
        $quantity = new Quantity($totalCopies, 'total_copies');

        $category = $this->categories->findById(trim($categoryId));
        if ($category === null) {
            throw ValidationException::forField('category_id', 'categoría inexistente', 'La categoría indicada no existe.');
        }

        $expectedAvailable = $book->availableCopies();
        $book->updateDetails(
            title: $title,
            author: $author,
            categoryId: $category['id'],
            newTotalCopies: $quantity,
            categoryName: $category['name'],
        );

        $this->books->save($book, $expectedAvailable);

        return $book;
    }

    /**
     * HU-03 · Baja lógica de un libro (CA-03.5, ADR-003).
     */
    public function delete(string $bookId): void
    {
        $book = $this->findActiveBookOrFail($bookId);
        $hasOpenLoans = $this->books->hasOpenLoans($book->id());

        $book->softDelete($this->clock->now(), $hasOpenLoans);
        $this->books->save($book);
    }

    /**
     * HU-03 · Subir portada JPG/PNG máx. 2 MB validada por contenido (CA-03.4, api-contract.md §4).
     */
    public function uploadCover(string $bookId, string $binaryContent): Book
    {
        $book = $this->findActiveBookOrFail($bookId);

        $size = strlen($binaryContent);
        if ($size === 0) {
            throw ValidationException::forField('file', 'archivo vacío', 'El archivo de portada está vacío.');
        }

        if ($size > self::MAX_COVER_BYTES) {
            throw ValidationException::forField(
                'file',
                'supera el tamaño máximo de 2 MB',
                'La portada no puede superar los 2 MB.'
            );
        }

        $detectedMime = self::detectImageMimeByContent($binaryContent);
        if ($detectedMime === null || !in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::forField(
                'file',
                'tipo de archivo no permitido (solo JPG o PNG)',
                'La portada debe ser una imagen JPG o PNG válida.'
            );
        }

        $previousKey = $book->coverKey();
        $newKey = $this->coverStorage->store($book->id(), $binaryContent, $detectedMime);
        $book->assignCover($newKey);
        $this->books->save($book);

        if ($previousKey !== null && $previousKey !== $newKey) {
            $this->coverStorage->delete($previousKey);
        }

        return $book;
    }

    /**
     * HU-03 · Quitar portada: primero anula cover_key y guarda; después borra el binario (data-model.md §7).
     */
    public function deleteCover(string $bookId): Book
    {
        $book = $this->findActiveBookOrFail($bookId);
        $previousKey = $book->coverKey();

        $book->removeCover();
        $this->books->save($book);

        if ($previousKey !== null) {
            $this->coverStorage->delete($previousKey);
        }

        return $book;
    }

    /**
     * Obtener el binario de la portada de un libro activo (GET /books/{id}/cover).
     *
     * @return array{content: string, mime_type: string}
     */
    public function getCover(string $bookId): array
    {
        $book = $this->findActiveBookOrFail($bookId);
        $coverKey = $book->coverKey();
        if ($coverKey === null) {
            throw new ResourceNotFoundException('El libro no tiene portada asignada.');
        }

        $stored = $this->coverStorage->get($coverKey);
        if ($stored === null) {
            throw new ResourceNotFoundException('El archivo de portada no se encontró.');
        }

        return $stored;
    }

    private function findActiveBookOrFail(string $bookId): Book
    {
        if (!Uuid::isValid($bookId)) {
            throw new ResourceNotFoundException('El libro solicitado no existe.');
        }

        $book = $this->books->findById(trim($bookId));
        if ($book === null || $book->isDeleted()) {
            throw new ResourceNotFoundException('El libro solicitado no existe.');
        }

        return $book;
    }

    /**
     * Validate image MIME type strictly from binary magic bytes (api-contract.md §4).
     */
    public static function detectImageMimeByContent(string $binaryContent): ?string
    {
        // PNG magic header: 89 50 4E 47 0D 0A 1A 0A
        if (str_starts_with($binaryContent, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        // JPEG magic header: FF D8 FF
        if (str_starts_with($binaryContent, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }

        return null;
    }
}
