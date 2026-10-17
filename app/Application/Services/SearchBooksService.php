<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\BookRepository;
use App\Domain\Entities\Book;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\Uuid;

final readonly class SearchBooksService
{
    public const int MAX_PAGE_SIZE = 50;

    public function __construct(
        private BookRepository $books,
    ) {
    }

    /**
     * HU-04 · Buscar libros activos por texto parcial (título/autor) y categoría,
     * ordenados por título y paginados (CA-04.1, CA-04.2).
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, size: int, total: int}
     */
    public function search(
        ?string $query = null,
        ?string $categoryId = null,
        int $page = 1,
        int $size = 20,
    ): array {
        if ($page < 1) {
            throw ValidationException::forField('page', 'debe ser mayor o igual a 1', 'El número de página debe ser mayor o igual a 1.');
        }

        if ($size < 1 || $size > self::MAX_PAGE_SIZE) {
            throw ValidationException::forField('size', 'debe estar entre 1 y 50', 'El tamaño de página debe estar entre 1 y 50.');
        }

        $trimmedCategory = ($categoryId !== null && trim($categoryId) !== '') ? trim($categoryId) : null;
        if ($trimmedCategory !== null && !Uuid::isValid($trimmedCategory)) {
            throw ValidationException::forField('category_id', 'formato UUID inválido', 'El filtro de categoría no es un UUID válido.');
        }

        $trimmedQuery = ($query !== null && trim($query) !== '') ? trim($query) : null;

        $result = $this->books->search($trimmedQuery, $trimmedCategory, $page, $size);

        return [
            'items' => array_map(static fn (Book $book): array => $book->toArray(), $result['items']),
            'page' => $page,
            'size' => $size,
            'total' => $result['total'],
        ];
    }

    /**
     * HU-04 · Consultar un libro por id; inexistente o dado de baja responde 404 (CA-04.3).
     */
    public function getById(string $id): Book
    {
        if (!Uuid::isValid($id)) {
            throw new ResourceNotFoundException('El libro solicitado no existe.');
        }

        $book = $this->books->findById(trim($id));
        if ($book === null || $book->isDeleted()) {
            throw new ResourceNotFoundException('El libro solicitado no existe.');
        }

        return $book;
    }
}
