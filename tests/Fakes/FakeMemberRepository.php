<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\Outbound\MemberRepository;
use App\Domain\Entities\Member;
use DateTimeImmutable;

final class FakeMemberRepository implements MemberRepository
{
    /** @var array<string, Member> */
    private array $byId = [];

    private ?FakeLoanRepository $loanRepository = null;

    public function attachLoanRepository(FakeLoanRepository $loanRepository): void
    {
        $this->loanRepository = $loanRepository;
    }

    public function findById(string $id): ?Member
    {
        return $this->byId[$id] ?? null;
    }

    public function existsByDocumentNumber(string $documentNumber): bool
    {
        foreach ($this->byId as $member) {
            if ($member->documentNumber()->value() === $documentNumber) {
                return true;
            }
        }

        return false;
    }

    public function countActiveLoanItems(string $memberId): int
    {
        if ($this->loanRepository === null) {
            return 0;
        }

        return $this->loanRepository->countActiveItemsForMember($memberId);
    }

    public function hasOverdueItems(string $memberId, DateTimeImmutable $now): bool
    {
        if ($this->loanRepository === null) {
            return false;
        }

        return $this->loanRepository->hasOverdueItemsForMember($memberId, $now);
    }

    public function search(?string $query, int $page, int $size): array
    {
        $filtered = [];
        foreach ($this->byId as $member) {
            if ($query !== null && $query !== '') {
                $qLower = mb_strtolower($query, 'UTF-8');
                $inName = str_contains(mb_strtolower($member->fullName(), 'UTF-8'), $qLower);
                $inDoc = str_contains(mb_strtolower($member->documentNumber()->value(), 'UTF-8'), $qLower);
                if (!$inName && !$inDoc) {
                    continue;
                }
            }
            $filtered[] = $member;
        }

        usort($filtered, static fn (Member $a, Member $b): int => strcmp($a->fullName(), $b->fullName()));

        $total = count($filtered);
        $offset = ($page - 1) * $size;

        return [
            'items' => array_slice($filtered, $offset, $size),
            'total' => $total,
        ];
    }

    public function save(Member $member): void
    {
        $this->byId[$member->id()] = $member;
    }
}
