<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\Outbound\BookRepository;
use App\Application\Ports\Outbound\LoanRepository;
use App\Application\Services\CreateBookService;
use App\Application\Services\CreateLibrarianService;
use App\Application\Services\LendBooksService;
use App\Application\Services\MemberService;
use App\Domain\Entities\Loan;
use App\Domain\Exceptions\BookNotAvailableException;
use App\Domain\Exceptions\MemberLimitExceededException;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-13, RNF-2, CA-06.4: Pruebas de integración contra PostgreSQL 16 para:
 * 1. Concurrencia optimista al prestar el último ejemplar (uno gana con 201, el otro recibe 409 BOOK_NOT_AVAILABLE).
 * 2. Límite estricto de máximo 3 libros sin devolver por socio (CA-06.4, RN-2).
 */
final class ConcurrencyAndLoanRulesIntegrationTest extends TestCase
{
    private const string CAT_NOVELA = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanMutableTables();
    }

    protected function tearDown(): void
    {
        $this->cleanMutableTables();
        parent::tearDown();
    }

    private function cleanMutableTables(): void
    {
        DB::statement('TRUNCATE TABLE library.loan_item, library.loan, library.book, library.member, library."user" CASCADE');
    }

    /**
     * T-13, RNF-2, ADR-002: Dos préstamos simultáneos del último ejemplar:
     * ambos leen available_copies = 1 al mismo tiempo; el primero persiste (201),
     * y el segundo detecta el conflicto de versión/disponibilidad y lanza 409 BOOK_NOT_AVAILABLE.
     */
    public function test_t13_rnf2_concurrent_loans_for_last_copy_one_wins_one_gets_409(): void
    {
        /** @var CreateLibrarianService $librarians */
        $librarians = $this->app->make(CreateLibrarianService::class);
        $lib1 = $librarians->execute('biblio_uno', 'Clave1234', 'librarian');
        $lib2 = $librarians->execute('biblio_dos', 'Clave1234', 'librarian');

        /** @var MemberService $members */
        $members = $this->app->make(MemberService::class);
        $member1 = $members->create('Socio Uno', 'DOC-100001');
        $member2 = $members->create('Socio Dos', 'DOC-100002');

        /** @var CreateBookService $booksService */
        $booksService = $this->app->make(CreateBookService::class);
        $lastCopyBook = $booksService->execute(
            title: 'Rayuela',
            author: 'Julio Cortázar',
            rawIsbn: '9788420431321',
            categoryId: self::CAT_NOVELA,
            totalCopies: 1,
        );

        /** @var BookRepository $bookRepo */
        $bookRepo = $this->app->make(BookRepository::class);
        /** @var LoanRepository $loanRepo */
        $loanRepo = $this->app->make(LoanRepository::class);

        $now = new DateTimeImmutable('2026-10-17T10:00:00Z', new DateTimeZone('UTC'));

        // Ambos bibliotecarios leen el mismo estado inicial del libro (available_copies = 1) al mismo tiempo
        $bookReadByLib1 = $bookRepo->findById($lastCopyBook->id());
        $bookReadByLib2 = $bookRepo->findById($lastCopyBook->id());

        $this->assertNotNull($bookReadByLib1);
        $this->assertNotNull($bookReadByLib2);
        $this->assertSame(1, $bookReadByLib1->availableCopies());
        $this->assertSame(1, $bookReadByLib2->availableCopies());

        // Ambos preparan su agregado Loan en memoria descontando el último ejemplar
        $loan1 = Loan::open(Uuid::v4(), $member1->id(), $lib1['id'], $now);
        $loan1->addItem(Uuid::v4(), $bookReadByLib1, 'Novela');

        $loan2 = Loan::open(Uuid::v4(), $member2->id(), $lib2['id'], $now);
        $loan2->addItem(Uuid::v4(), $bookReadByLib2, 'Novela');

        // El bibliotecario 1 confirma primero en PostgreSQL -> Éxito
        $loanRepo->saveLoanWithBooks($loan1, [$bookReadByLib1], [$lastCopyBook->id() => 1]);

        // El bibliotecario 2 intenta confirmar con el testigo expectedAvailableCopies = 1 -> 409 BOOK_NOT_AVAILABLE
        try {
            $loanRepo->saveLoanWithBooks($loan2, [$bookReadByLib2], [$lastCopyBook->id() => 1]);
            $this->fail('El segundo préstamo concurrente del último ejemplar debió fallar con 409 BOOK_NOT_AVAILABLE');
        } catch (BookNotAvailableException $e) {
            $this->assertSame('BOOK_NOT_AVAILABLE', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        // En la base de datos quedan 0 disponibles y exactamente 1 préstamo registrado
        $freshBook = $bookRepo->findById($lastCopyBook->id());
        $this->assertNotNull($freshBook);
        $this->assertSame(0, $freshBook->availableCopies());

        $loanCount = (int) DB::selectOne('SELECT COUNT(*)::int AS c FROM library.loan')->c;
        $this->assertSame(1, $loanCount);
    }

    /**
     * Actividad 9, CA-06.4, RN-2: Un socio no puede tener más de 3 libros sin devolver en total (422 MEMBER_LIMIT_EXCEEDED).
     */
    public function test_ca06_4_member_cannot_exceed_3_unreturned_books_limit(): void
    {
        /** @var CreateLibrarianService $librarians */
        $librarians = $this->app->make(CreateLibrarianService::class);
        $librarian = $librarians->execute('biblio_test', 'Clave1234', 'librarian');

        /** @var MemberService $members */
        $members = $this->app->make(MemberService::class);
        $member = $members->create('Laura Gómez', 'DOC-900123');

        /** @var CreateBookService $booksService */
        $booksService = $this->app->make(CreateBookService::class);
        $b1 = $booksService->execute('Libro 1', 'Autor 1', '9780307474728', self::CAT_NOVELA, 3);
        $b2 = $booksService->execute('Libro 2', 'Autor 2', '9780307387264', self::CAT_NOVELA, 3);
        $b3 = $booksService->execute('Libro 3', 'Autor 3', '9788420431321', self::CAT_NOVELA, 3);
        $b4 = $booksService->execute('Libro 4', 'Autor 4', '9788437604947', self::CAT_NOVELA, 3);

        /** @var LendBooksService $lendBooks */
        $lendBooks = $this->app->make(LendBooksService::class);

        // Préstamo 1: 2 libros (total activos = 2 <= 3) -> OK
        $lendBooks->execute($member->id(), $librarian['id'], [$b1->id(), $b2->id()]);
        $this->assertSame(2, $members->getById($member->id())['active_loans']);

        // Préstamo 2: intenta pedir 2 libros más (2 + 2 = 4 > 3) -> 422 MEMBER_LIMIT_EXCEEDED y no registra nada
        try {
            $lendBooks->execute($member->id(), $librarian['id'], [$b3->id(), $b4->id()]);
            $this->fail('Debió lanzar MemberLimitExceededException al superar 3 libros sin devolver');
        } catch (MemberLimitExceededException $e) {
            $this->assertSame('MEMBER_LIMIT_EXCEEDED', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertStringContainsString('active_loans=2', $e->details()[0]['issue']);
            $this->assertStringContainsString('max=3', $e->details()[0]['issue']);
        }

        // El conteo sigue en 2 porque el préstamo fallido no se guardó
        $this->assertSame(2, $members->getById($member->id())['active_loans']);

        // Préstamo 3: pide 1 solo libro (2 + 1 = 3 <= 3) -> OK
        $lendBooks->execute($member->id(), $librarian['id'], [$b3->id()]);
        $this->assertSame(3, $members->getById($member->id())['active_loans']);

        // Préstamo 4: con 3 libros sin devolver, pedir 1 más (3 + 1 = 4 > 3) -> 422 MEMBER_LIMIT_EXCEEDED
        try {
            $lendBooks->execute($member->id(), $librarian['id'], [$b4->id()]);
            $this->fail('Debió rechazar un cuarto libro sin devolver con 422 MEMBER_LIMIT_EXCEEDED');
        } catch (MemberLimitExceededException $e) {
            $this->assertSame('MEMBER_LIMIT_EXCEEDED', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertStringContainsString('active_loans=3', $e->details()[0]['issue']);
        }
    }
}
