<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Member;
use App\Domain\Entities\User;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\DocumentNumber;
use PHPUnit\Framework\TestCase;

final class MemberAndUserTest extends TestCase
{
    public function test_user_normalizes_username_and_rejects_admin_role_via_api(): void
    {
        // "Ana " becomes "ana" (T-06, CA-02.2)
        $user = User::createFromApiRequest(
            id: '11111111-1111-4111-8111-111111111111',
            rawUsername: '  Ana  ',
            passwordHash: 'hashed_secret',
            requestedRole: 'librarian',
        );

        $this->assertSame('ana', $user->username());
        $this->assertSame('librarian', $user->role());
        $this->assertArrayNotHasKey('password', $user->toPublicArray());
        $this->assertArrayNotHasKey('password_hash', $user->toPublicArray());

        // Requesting 'admin' role via API throws 422 VALIDATION_ERROR (T-06, CA-02.3)
        try {
            User::createFromApiRequest(
                id: '22222222-2222-4222-8222-222222222222',
                rawUsername: 'carlos',
                passwordHash: 'hashed_secret',
                requestedRole: 'admin',
            );
            $this->fail('Expected ValidationException when requesting admin role via API.');
        } catch (ValidationException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame('role', $e->details()[0]['field']);
        }
    }

    public function test_member_validates_full_name_and_document_number(): void
    {
        $member = new Member(
            id: '33333333-3333-4333-8333-333333333333',
            fullName: '  Laura Gómez  ',
            documentNumber: new DocumentNumber(' 1098765432 '),
        );

        $this->assertSame('Laura Gómez', $member->fullName());
        $this->assertSame('1098765432', $member->documentNumber()->value());
        $this->assertSame([
            'id' => '33333333-3333-4333-8333-333333333333',
            'full_name' => 'Laura Gómez',
            'document_number' => '1098765432',
            'active_loans' => 2,
        ], $member->toArray(2));
    }
}
