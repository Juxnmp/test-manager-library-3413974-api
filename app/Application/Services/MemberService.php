<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\MemberRepository;
use App\Domain\Entities\Member;
use App\Domain\Exceptions\DocumentTakenException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\DocumentNumber;
use App\Domain\ValueObjects\Uuid;

final readonly class MemberService
{
    public const int MAX_PAGE_SIZE = 50;

    public function __construct(
        private MemberRepository $members,
    ) {
    }

    /**
     * HU-05 · Crear socio con nombre y documento único (CA-05.1).
     */
    public function create(string $fullName, string $rawDocumentNumber): Member
    {
        $doc = new DocumentNumber($rawDocumentNumber);

        $member = new Member(
            id: Uuid::v4(),
            fullName: $fullName,
            documentNumber: $doc,
        );

        if ($this->members->existsByDocumentNumber($doc->value())) {
            throw new DocumentTakenException($doc->value());
        }

        $this->members->save($member);

        return $member;
    }

    /**
     * HU-05 · Listar y buscar socios por nombre o documento, paginado (CA-05.2).
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, size: int, total: int}
     */
    public function search(?string $query = null, int $page = 1, int $size = 20): array
    {
        if ($page < 1) {
            throw ValidationException::forField('page', 'debe ser mayor o igual a 1', 'El número de página debe ser mayor o igual a 1.');
        }

        if ($size < 1 || $size > self::MAX_PAGE_SIZE) {
            throw ValidationException::forField('size', 'debe estar entre 1 y 50', 'El tamaño de página debe estar entre 1 y 50.');
        }

        $trimmedQuery = ($query !== null && trim($query) !== '') ? trim($query) : null;
        $result = $this->members->search($trimmedQuery, $page, $size);

        return [
            'items' => array_map(static fn (Member $member): array => $member->toArray(), $result['items']),
            'page' => $page,
            'size' => $size,
            'total' => $result['total'],
        ];
    }

    /**
     * HU-05 · Detalle de socio con conteo de préstamos activos sin devolver (api-contract.md §3.5).
     *
     * @return array<string, mixed>
     */
    public function getById(string $memberId): array
    {
        if (!Uuid::isValid($memberId)) {
            throw new ResourceNotFoundException('El socio solicitado no existe.');
        }

        $member = $this->members->findById(trim($memberId));
        if ($member === null) {
            throw new ResourceNotFoundException('El socio solicitado no existe.');
        }

        $activeLoans = $this->members->countActiveLoanItems($member->id());

        return $member->toArray($activeLoans);
    }
}
