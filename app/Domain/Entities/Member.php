<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\DocumentNumber;

final class Member
{
    private string $id;
    private string $fullName;
    private DocumentNumber $documentNumber;

    public function __construct(
        string $id,
        string $fullName,
        DocumentNumber $documentNumber,
    ) {
        $trimmedId = trim($id);
        if ($trimmedId === '') {
            throw ValidationException::forField('id', 'obligatorio', 'El identificador del socio es obligatorio.');
        }

        $trimmedName = trim($fullName);
        if ($trimmedName === '' || mb_strlen($trimmedName, 'UTF-8') > 200) {
            throw ValidationException::forField(
                'full_name',
                'obligatorio y máximo 200 caracteres',
                'El nombre completo del socio es obligatorio y no puede superar 200 caracteres.'
            );
        }

        $this->id = $trimmedId;
        $this->fullName = $trimmedName;
        $this->documentNumber = $documentNumber;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function documentNumber(): DocumentNumber
    {
        return $this->documentNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?int $activeLoans = null): array
    {
        $data = [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'document_number' => $this->documentNumber->value(),
        ];

        if ($activeLoans !== null) {
            $data['active_loans'] = $activeLoans;
        }

        return $data;
    }
}
