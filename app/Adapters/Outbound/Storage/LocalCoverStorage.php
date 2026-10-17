<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Storage;

use App\Application\Ports\Outbound\CoverStorage;
use App\Application\Services\CreateBookService;
use App\Domain\ValueObjects\Uuid;

final readonly class LocalCoverStorage implements CoverStorage
{
    public function __construct(
        private string $baseDirectory,
    ) {
    }

    /**
     * Store binary cover image on disk and return an opaque cover_key (never a path or URL).
     */
    public function store(string $bookId, string $binaryContent, string $mimeType): string
    {
        if (!is_dir($this->baseDirectory)) {
            mkdir($this->baseDirectory, 0775, true);
        }

        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';
        $coverKey = Uuid::v4() . '.' . $extension;
        $fullPath = $this->resolveSafePath($coverKey);

        file_put_contents($fullPath, $binaryContent, LOCK_EX);

        return $coverKey;
    }

    /**
     * @return array{content: string, mime_type: string}|null
     */
    public function get(string $coverKey): ?array
    {
        if (!$this->isValidOpaqueKey($coverKey)) {
            return null;
        }

        $fullPath = $this->resolveSafePath($coverKey);
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        if ($content === false || $content === '') {
            return null;
        }

        $mimeType = CreateBookService::detectImageMimeByContent($content);
        if ($mimeType === null) {
            $mimeType = str_ends_with($coverKey, '.png') ? 'image/png' : 'image/jpeg';
        }

        return [
            'content' => $content,
            'mime_type' => $mimeType,
        ];
    }

    public function delete(string $coverKey): void
    {
        if (!$this->isValidOpaqueKey($coverKey)) {
            return;
        }

        $fullPath = $this->resolveSafePath($coverKey);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function isValidOpaqueKey(string $coverKey): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(jpg|png)$/i', $coverKey) === 1;
    }

    private function resolveSafePath(string $coverKey): string
    {
        return rtrim($this->baseDirectory, '/\\') . DIRECTORY_SEPARATOR . basename($coverKey);
    }
}
