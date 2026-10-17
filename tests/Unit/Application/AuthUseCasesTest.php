<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Services\CreateLibrarianService;
use App\Application\Services\LoginService;
use App\Domain\Entities\User;
use App\Domain\Exceptions\InvalidCredentialsException;
use App\Domain\Exceptions\UsernameTakenException;
use App\Domain\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakePasswordHasher;
use Tests\Fakes\FakeTokenIssuer;
use Tests\Fakes\FakeUserRepository;

final class AuthUseCasesTest extends TestCase
{
    private FakeUserRepository $users;
    private FakePasswordHasher $hasher;
    private FakeTokenIssuer $tokens;
    private LoginService $loginService;
    private CreateLibrarianService $createLibrarianService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new FakeUserRepository();
        $this->hasher = new FakePasswordHasher();
        $this->tokens = new FakeTokenIssuer();
        $this->loginService = new LoginService($this->users, $this->hasher, $this->tokens);
        $this->createLibrarianService = new CreateLibrarianService($this->users, $this->hasher);
    }

    public function test_hu01_login_succeeds_with_8_hour_token_and_fails_with_uniform_401_message(): void
    {
        $admin = User::provisionAdmin(
            id: '11111111-1111-4111-8111-111111111111',
            rawUsername: 'admin',
            passwordHash: $this->hasher->hash('SecretPass123!'),
        );
        $this->users->save($admin);

        // CA-01.1: Valid credentials return 8h token (28800s)
        $result = $this->loginService->execute(' ADMIN ', 'SecretPass123!');
        $this->assertSame('Bearer', $result['token_type']);
        $this->assertSame(28800, $result['expires_in']);
        $this->assertSame('admin', $result['role']);
        $this->assertNotEmpty($result['access_token']);

        // CA-01.2: Invalid username and invalid password return the exact same 401 message
        $wrongUserMessage = null;
        try {
            $this->loginService->execute('inexistente', 'SecretPass123!');
        } catch (InvalidCredentialsException $e) {
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame('INVALID_CREDENTIALS', $e->errorCode());
            $wrongUserMessage = $e->getMessage();
        }

        $wrongPasswordMessage = null;
        try {
            $this->loginService->execute('admin', 'ClaveErronea');
        } catch (InvalidCredentialsException $e) {
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame('INVALID_CREDENTIALS', $e->errorCode());
            $wrongPasswordMessage = $e->getMessage();
        }

        $this->assertNotNull($wrongUserMessage);
        $this->assertSame($wrongUserMessage, $wrongPasswordMessage);
    }

    public function test_hu02_create_librarian_normalizes_username_enforces_uniqueness_and_rejects_admin_role(): void
    {
        // CA-02.1 & CA-02.2: "Ana " -> "ana"
        $created = $this->createLibrarianService->execute('Ana ', 'ClaveSegura123', 'librarian');
        $this->assertSame('ana', $created['username']);
        $this->assertSame('librarian', $created['role']);
        $this->assertArrayNotHasKey('password', $created);
        $this->assertArrayNotHasKey('password_hash', $created);

        // CA-02.2: Duplicate username -> 409 USERNAME_TAKEN
        try {
            $this->createLibrarianService->execute('  ANA', 'OtraClave123', 'librarian');
            $this->fail('Expected UsernameTakenException was not thrown.');
        } catch (UsernameTakenException $e) {
            $this->assertSame('USERNAME_TAKEN', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        // CA-02.3: Requesting role 'admin' -> 422 VALIDATION_ERROR
        try {
            $this->createLibrarianService->execute('nuevo_admin', 'OtraClave123', 'admin');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }
    }
}
