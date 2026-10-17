<?php

declare(strict_types=1);

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports\Outbound\MemberRepository;
use App\Domain\Entities\Member;
use App\Domain\Exceptions\DocumentTakenException;
use App\Domain\ValueObjects\DocumentNumber;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PostgresMemberRepository implements MemberRepository
{
    public function findById(string $id): ?Member
    {
        $row = DB::selectOne(
            'SELECT id, full_name, document_number FROM library.member WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function existsByDocumentNumber(string $documentNumber): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS found FROM library.member WHERE document_number = :doc LIMIT 1',
            ['doc' => trim($documentNumber)]
        );

        return $row !== null;
    }

    /**
     * Q7: Conteo de líneas sin devolver de un socio.
     */
    public function countActiveLoanItems(string $memberId): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*)::int AS active_count
             FROM library.loan_item li
             JOIN library.loan l ON l.id = li.loan_id
             WHERE l.member_id = :member_id AND li.returned_at IS NULL',
            ['member_id' => $memberId]
        );

        return $row !== null ? (int) $row->active_count : 0;
    }

    /**
     * Q7: Comprueba si el socio tiene alguna línea sin devolver con due_at < $now.
     */
    public function hasOverdueItems(string $memberId, DateTimeImmutable $now): bool
    {
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        $row = DB::selectOne(
            'SELECT 1 AS found
             FROM library.loan_item li
             JOIN library.loan l ON l.id = li.loan_id
             WHERE l.member_id = :member_id
               AND li.returned_at IS NULL
               AND li.due_at < :now
             LIMIT 1',
            [
                'member_id' => $memberId,
                'now' => $nowUtc,
            ]
        );

        return $row !== null;
    }

    /**
     * HU-05 · Listar y buscar socios por nombre o documento, paginado (CA-05.2).
     *
     * @return array{items: array<int, Member>, total: int}
     */
    public function search(?string $query, int $page, int $size): array
    {
        $params = [];
        $where = '';

        if ($query !== null && trim($query) !== '') {
            $where = 'WHERE (full_name ILIKE :q OR document_number ILIKE :q)';
            $params['q'] = '%' . trim($query) . '%';
        }

        $countRow = DB::selectOne(
            "SELECT COUNT(*)::int AS total FROM library.member {$where}",
            $params
        );
        $total = $countRow !== null ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $size;
        $rows = DB::select(
            "SELECT id, full_name, document_number
             FROM library.member
             {$where}
             ORDER BY full_name ASC, id ASC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [
                'limit' => $size,
                'offset' => $offset,
            ])
        );

        return [
            'items' => array_map(fn (object $row): Member => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function save(Member $member): void
    {
        try {
            DB::statement(
                'INSERT INTO library.member (id, full_name, document_number)
                 VALUES (:id, :full_name, :document_number)
                 ON CONFLICT (id) DO UPDATE SET
                     full_name = EXCLUDED.full_name,
                     document_number = EXCLUDED.document_number',
                [
                    'id' => $member->id(),
                    'full_name' => $member->fullName(),
                    'document_number' => $member->documentNumber()->value(),
                ]
            );
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new DocumentTakenException($member->documentNumber()->value());
            }

            throw $e;
        }
    }

    private function hydrate(object $row): Member
    {
        return new Member(
            id: (string) $row->id,
            fullName: (string) $row->full_name,
            documentNumber: new DocumentNumber((string) $row->document_number),
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();

        return (string) $sqlState === '23505';
    }
}
