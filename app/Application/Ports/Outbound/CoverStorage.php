<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

interface CoverStorage
{
    /**
     * Store binary cover image and return an opaque cover_key (never a file path).
     */
    public function store(string $bookId, string $binaryContent, string $mimeType): string;

    /**
     * Retrieve cover binary and MIME type by opaque cover_key.
     *
     * @return array{content: string, mime_type: string}|null
     */
    public function get(string $coverKey): ?array;

    /**
     * Physically delete cover binary by opaque cover_key.
     */
    public function delete(string $coverKey): void;
}
