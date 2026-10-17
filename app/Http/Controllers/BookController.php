<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\CreateBookService;
use App\Application\Services\SearchBooksService;
use App\Domain\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BookController extends Controller
{
    public function __construct(
        private readonly CreateBookService $createBookService,
        private readonly SearchBooksService $searchBooksService,
    ) {
    }

    /**
     * GET /api/v1/books (Authenticated)
     * HU-04 · CA-04.1, CA-04.2
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->query('q');
        $categoryId = $request->query('category_id');
        $page = $this->parseIntParam($request->query('page', 1), 'page');
        $size = $this->parseIntParam($request->query('size', 20), 'size');

        $result = $this->searchBooksService->search(
            query: is_string($query) ? $query : null,
            categoryId: is_string($categoryId) ? $categoryId : null,
            page: $page,
            size: $size,
        );

        return response()->json($result, 200);
    }

    /**
     * GET /api/v1/books/{id} (Authenticated)
     * HU-04 · CA-04.3
     */
    public function show(string $id): JsonResponse
    {
        $book = $this->searchBooksService->getById($id);

        return response()->json($book->toArray(), 200);
    }

    /**
     * POST /api/v1/books (Admin only)
     * HU-03 · CA-03.1, CA-03.2, CA-03.3
     */
    public function store(Request $request): JsonResponse
    {
        $title = (string) $request->input('title', '');
        $author = (string) $request->input('author', '');
        $isbn = (string) $request->input('isbn', '');
        $categoryId = (string) $request->input('category_id', '');
        $totalCopies = $this->parseIntParam($request->input('total_copies'), 'total_copies');

        $book = $this->createBookService->execute(
            title: $title,
            author: $author,
            rawIsbn: $isbn,
            categoryId: $categoryId,
            totalCopies: $totalCopies,
        );

        return response()->json($book->toArray(), 201);
    }

    /**
     * PUT /api/v1/books/{id} (Admin only)
     * HU-03 · CA-03.3
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $title = (string) $request->input('title', '');
        $author = (string) $request->input('author', '');
        $categoryId = (string) $request->input('category_id', '');
        $totalCopies = $this->parseIntParam($request->input('total_copies'), 'total_copies');

        $book = $this->createBookService->update(
            bookId: $id,
            title: $title,
            author: $author,
            categoryId: $categoryId,
            totalCopies: $totalCopies,
        );

        return response()->json($book->toArray(), 200);
    }

    /**
     * DELETE /api/v1/books/{id} (Admin only)
     * HU-03 · CA-03.5
     */
    public function destroy(string $id): Response
    {
        $this->createBookService->delete($id);

        return response()->noContent();
    }

    /**
     * PUT /api/v1/books/{id}/cover (Admin only)
     * HU-03 · CA-03.4, api-contract.md §3.4 & §4
     */
    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $binary = $this->extractCoverBinary($request);
        $book = $this->createBookService->uploadCover($id, $binary);

        return response()->json($book->toArray(), 200);
    }

    /**
     * DELETE /api/v1/books/{id}/cover (Admin only)
     * HU-03 · data-model.md §7
     */
    public function deleteCover(string $id): Response
    {
        $this->createBookService->deleteCover($id);

        return response()->noContent();
    }

    /**
     * GET /api/v1/books/{id}/cover (Authenticated)
     * api-contract.md §3.4: devuelve el binario o 404
     */
    public function showCover(string $id): Response
    {
        $cover = $this->createBookService->getCover($id);

        return response($cover['content'], 200)
            ->header('Content-Type', $cover['mime_type'])
            ->header('Content-Length', (string) strlen($cover['content']));
    }

    private function parseIntParam(mixed $raw, string $field): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw ValidationException::forField($field, 'debe ser un número entero', "El campo «{$field}» es obligatorio y debe ser un número entero.");
    }

    private function extractCoverBinary(Request $request): string
    {
        $uploaded = $request->file('file');
        if ($uploaded instanceof UploadedFile) {
            if ($uploaded->getError() === UPLOAD_ERR_INI_SIZE || $uploaded->getError() === UPLOAD_ERR_FORM_SIZE) {
                throw ValidationException::forField(
                    'file',
                    'supera el tamaño máximo de 2 MB',
                    'La portada no puede superar los 2 MB.'
                );
            }

            if (!$uploaded->isValid()) {
                throw ValidationException::forField('file', 'archivo inválido', 'No se pudo procesar el archivo subido.');
            }

            return (string) $uploaded->getContent();
        }

        $rawContent = (string) $request->getContent();
        $contentType = (string) $request->header('Content-Type', '');

        if ($rawContent !== '' && str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            $extracted = $this->parseMultipartFileField($rawContent, $contentType, 'file');
            if ($extracted !== null) {
                return $extracted;
            }
        }

        if ($rawContent !== '' && CreateBookService::detectImageMimeByContent($rawContent) !== null) {
            return $rawContent;
        }

        throw ValidationException::forField(
            'file',
            'archivo obligatorio',
            'Debe adjuntar el archivo de portada en el campo «file».'
        );
    }

    private function parseMultipartFileField(string $rawBody, string $contentType, string $fieldName): ?string
    {
        if (preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $matches) !== 1) {
            return null;
        }

        $boundary = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
        if ($boundary === '') {
            return null;
        }

        $parts = explode('--' . $boundary, $rawBody);
        foreach ($parts as $part) {
            if ($part === '' || $part === "--\r\n" || $part === '--') {
                continue;
            }

            $part = ltrim($part, "\r\n");
            $separatorPos = strpos($part, "\r\n\r\n");
            if ($separatorPos === false) {
                continue;
            }

            $headers = substr($part, 0, $separatorPos);
            $content = substr($part, $separatorPos + 4);
            if (str_ends_with($content, "\r\n")) {
                $content = substr($content, 0, -2);
            }

            if (preg_match('/name="' . preg_quote($fieldName, '/') . '"/i', $headers) === 1) {
                return $content;
            }
        }

        return null;
    }
}
