<?php

declare(strict_types=1);

namespace App\Application\Ports\Outbound;

use App\Domain\Entities\Member;
use DateTimeImmutable;

interface MemberRepository
{
    public function findById(string $id): ?Member;

    public function existsByDocumentNumber(string $documentNumber): bool;

    public function countActiveLoanItems(string $memberId): int;

    public function hasOverdueItems(string $memberId, DateTimeImmutable $now): bool;

    /**
     * @return array{items: array<int, Member>, total: int}
     */
    public function search(?string $query, int $page, int $size): array;

    public function save(Member $member): void;
}
