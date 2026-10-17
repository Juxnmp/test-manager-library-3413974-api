<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Ports\Outbound\Clock;
use App\Application\Ports\Outbound\LoanRepository;
use App\Domain\Entities\Loan;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\DateRange;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class OverdueService
{
    public const int MAX_PAGE_SIZE = 50;
    private const array VALID_STATUSES = ['open', 'overdue', 'closed'];

    public function __construct(
        private LoanRepository $loans,
        private Clock $clock,
    ) {
    }

    /**
     * HU-09 · Libros vencidos: líneas sin devolver con due_at < ahora,
     * ordenadas por más días de retraso primero (T-10, CA-09.1, CA-09.2).
     *
     * @return array{items: array<int, array{
     *     loan_id: string,
     *     item_id: string,
     *     book_title: string,
     *     member_name: string,
     *     due_at: string,
     *     days_late: int
     * }>}
     */
    public function listOverdue(): array
    {
        $now = $this->clock->now();

        return [
            'items' => $this->loans->findOverdueItems($now),
        ];
    }

    /**
     * HU-08 · Consultar un préstamo por id con sus líneas y estado calculado (CA-08.1, CA-08.3).
     *
     * @return array<string, mixed>
     */
    public function getLoanById(string $loanId): array
    {
        if (!Uuid::isValid($loanId)) {
            throw new ResourceNotFoundException('El préstamo solicitado no existe.');
        }

        $loan = $this->loans->findById(trim($loanId));
        if ($loan === null) {
            throw new ResourceNotFoundException('El préstamo solicitado no existe.');
        }

        return $loan->toArray($this->clock->now());
    }

    /**
     * HU-08 · Listar préstamos por rango de fechas, estado y socio, orden descendente, paginado (CA-08.2).
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, size: int, total: int}
     */
    public function searchLoans(
        ?string $fromRaw = null,
        ?string $toRaw = null,
        ?string $status = null,
        ?string $memberId = null,
        int $page = 1,
        int $size = 20,
    ): array {
        if ($page < 1) {
            throw ValidationException::forField('page', 'debe ser mayor o igual a 1', 'El número de página debe ser mayor o igual a 1.');
        }

        if ($size < 1 || $size > self::MAX_PAGE_SIZE) {
            throw ValidationException::forField('size', 'debe estar entre 1 y 50', 'El tamaño de página debe estar entre 1 y 50.');
        }

        $trimmedStatus = ($status !== null && trim($status) !== '') ? trim($status) : null;
        if ($trimmedStatus !== null && !in_array($trimmedStatus, self::VALID_STATUSES, true)) {
            throw ValidationException::forField('status', 'debe ser open, overdue o closed', 'El filtro de estado no es válido.');
        }

        $trimmedMemberId = ($memberId !== null && trim($memberId) !== '') ? trim($memberId) : null;
        if ($trimmedMemberId !== null && !Uuid::isValid($trimmedMemberId)) {
            throw ValidationException::forField('member_id', 'formato UUID inválido', 'El filtro de socio no es un UUID válido.');
        }

        $from = null;
        $to = null;
        $hasFrom = $fromRaw !== null && trim($fromRaw) !== '';
        $hasTo = $toRaw !== null && trim($toRaw) !== '';

        if ($hasFrom && $hasTo) {
            $range = DateRange::fromStrings($fromRaw, $toRaw);
            $from = $range->from();
            $to = $range->to();
        } elseif ($hasFrom) {
            $from = self::parseDateBoundary($fromRaw, 'from', false);
        } elseif ($hasTo) {
            $to = self::parseDateBoundary($toRaw, 'to', true);
        }

        $now = $this->clock->now();
        $result = $this->loans->search($from, $to, $trimmedStatus, $trimmedMemberId, $now, $page, $size);

        return [
            'items' => array_map(static fn (Loan $loan): array => $loan->toArray($now), $result['items']),
            'page' => $page,
            'size' => $size,
            'total' => $result['total'],
        ];
    }

    private static function parseDateBoundary(string $raw, string $field, bool $endOfDay): DateTimeImmutable
    {
        $trimmed = trim($raw);
        $utc = new DateTimeZone('UTC');
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
                $suffix = $endOfDay ? 'T23:59:59.999999Z' : 'T00:00:00Z';
                return new DateTimeImmutable($trimmed . $suffix, $utc);
            }
            return (new DateTimeImmutable($trimmed, $utc))->setTimezone($utc);
        } catch (Throwable) {
            throw ValidationException::forField($field, 'formato de fecha inválido', "El parámetro «{$field}» no tiene un formato de fecha válido.");
        }
    }
}
