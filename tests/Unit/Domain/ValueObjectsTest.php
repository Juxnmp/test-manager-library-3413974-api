<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exceptions\InvalidDateRangeException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\DateRange;
use App\Domain\ValueObjects\DocumentNumber;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function test_isbn_accepts_exact_13_digits(): void
    {
        $isbn = new Isbn(' 9780307474728 ');
        $this->assertSame('9780307474728', $isbn->value());
        $this->assertTrue($isbn->equals(new Isbn('9780307474728')));
    }

    public function test_isbn_rejects_non_13_digits(): void
    {
        $this->expectException(ValidationException::class);
        new Isbn('978-030747472');
    }

    public function test_isbn_rejects_short_number(): void
    {
        try {
            new Isbn('123456789012');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame('isbn', $e->details()[0]['field']);
        }
    }

    public function test_quantity_accepts_positive_integer(): void
    {
        $qty = new Quantity(4);
        $this->assertSame(4, $qty->value());
    }

    public function test_quantity_rejects_zero_or_negative(): void
    {
        foreach ([0, -1, -5] as $invalid) {
            try {
                new Quantity($invalid);
                $this->fail("Expected ValidationException for quantity {$invalid}");
            } catch (ValidationException $e) {
                $this->assertSame('VALIDATION_ERROR', $e->errorCode());
                $this->assertSame(422, $e->httpStatus());
            }
        }
    }

    public function test_date_range_accepts_end_equal_or_after_start(): void
    {
        $range = DateRange::fromStrings('2026-10-01', '2026-10-31');
        $this->assertSame('2026-10-01', $range->fromDate());
        $this->assertSame('2026-10-31', $range->toDate());
        $this->assertTrue($range->to() >= $range->from());

        $sameDay = DateRange::fromStrings('2026-10-15', '2026-10-15');
        $this->assertTrue($sameDay->to() >= $sameDay->from());
    }

    public function test_date_range_rejects_end_before_start_with_invalid_date_range_code(): void
    {
        try {
            DateRange::fromStrings('2026-10-31', '2026-10-01');
            $this->fail('Expected InvalidDateRangeException was not thrown.');
        } catch (InvalidDateRangeException $e) {
            $this->assertSame('INVALID_DATE_RANGE', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }
    }

    public function test_document_number_trims_and_validates_length(): void
    {
        $doc = new DocumentNumber('  1020304050  ');
        $this->assertSame('1020304050', $doc->value());

        $this->expectException(ValidationException::class);
        new DocumentNumber('   ');
    }
}
