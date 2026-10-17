<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\CoverStorage;

final class FakeCoverStorage implements CoverStorage
{
    /** @var array<string, array{content: string, mime_type: string}> */
    private array $files = [];
    private int $counter = 0;

    public function store(string $bookId, string $binaryContent, string $mimeType): string
    {
        $this->counter++;
        $ext = $mimeType === 'image/png' ? 'png' : 'jpg';
        $key = "cover_{$bookId}_{$this->counter}.{$ext}";

        $this->files[$key] = [
            'content' => $binaryContent,
            'mime_type' => $mimeType,
        ];

        return $key;
    }

    public function get(string $coverKey): ?array
    {
        return $this->files[$coverKey] ?? null;
    }

    public function delete(string $coverKey): void
    {
        unset($this->files[$coverKey]);
    }

    public function has(string $coverKey): bool
    {
        return isset($this->files[$coverKey]);
    }
}
