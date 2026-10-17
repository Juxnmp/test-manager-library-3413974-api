<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\Outbound\BookRepository;
use App\Application\Ports\Outbound\CategoryRepository;
use App\Application\Ports\Outbound\CoverStorage;
use App\Application\Ports\Outbound\MemberRepository;
use App\Application\Ports\Outbound\PasswordHasher;
use App\Application\Ports\Outbound\TokenIssuer;
use App\Application\Ports\Outbound\UserRepository;
use App\Application\Services\CreateBookService;
use App\Domain\Entities\Book;
use App\Domain\Entities\Member;
use App\Domain\Entities\User;
use App\Domain\Exceptions\DocumentTakenException;
use App\Domain\Exceptions\IsbnTakenException;
use App\Domain\Exceptions\UsernameTakenException;
use App\Domain\Exceptions\ValidationException;
use App\Domain\ValueObjects\DocumentNumber;
use App\Domain\ValueObjects\Isbn;
use App\Domain\ValueObjects\Quantity;
use App\Domain\ValueObjects\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-12, T-14, T-15, T-18: Pruebas de integración de los adaptadores de salida
 * (persistencia PostgreSQL 16, seguridad, almacenamiento de portadas e invariantes SQL).
 */
final class PersistenceAndAdaptersIntegrationTest extends TestCase
{
    private const string CAT_NOVELA = '11111111-1111-4111-8111-111111111111';
    private const string CAT_CIENCIA = '22222222-2222-4222-8222-222222222222';

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
     * T-12: Repositorios de categoría, usuario, socio y libro contra PostgreSQL 16 real.
     */
    public function test_t12_postgres_repositories_persist_and_query_correctly(): void
    {
        /** @var CategoryRepository $categories */
        $categories = $this->app->make(CategoryRepository::class);
        $allCategories = $categories->findAll();
        $this->assertCount(5, $allCategories);
        // Orden por nombre ascendente (Ciencia, Historia, Infantil, Novela, Tecnología)
        $this->assertSame('Ciencia', $allCategories[0]['name']);

        /** @var UserRepository $users */
        $users = $this->app->make(UserRepository::class);
        /** @var PasswordHasher $hasher */
        $hasher = $this->app->make(PasswordHasher::class);

        $user = new User(
            id: Uuid::v4(),
            username: '  Ana_Biblio ',
            passwordHash: $hasher->hash('ClaveSegura123'),
            role: User::ROLE_LIBRARIAN,
        );
        $users->save($user);

        $foundUser = $users->findByUsername('ANA_BIBLIO');
        $this->assertNotNull($foundUser);
        $this->assertSame('ana_biblio', $foundUser->username());
        $this->assertTrue($hasher->verify('ClaveSegura123', $foundUser->passwordHash()));

        // Duplicado de username lanza UsernameTakenException (409)
        $duplicateUser = new User(
            id: Uuid::v4(),
            username: 'ana_biblio',
            passwordHash: $hasher->hash('OtraClave123'),
            role: User::ROLE_LIBRARIAN,
        );
        try {
            $users->save($duplicateUser);
            $this->fail('Debió lanzar UsernameTakenException por índice único uq_user_username');
        } catch (UsernameTakenException $e) {
            $this->assertSame('USERNAME_TAKEN', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        /** @var MemberRepository $members */
        $members = $this->app->make(MemberRepository::class);
        $member = new Member(
            id: Uuid::v4(),
            fullName: 'Carlos Mendoza',
            documentNumber: new DocumentNumber('CC-1098765432'),
        );
        $members->save($member);

        $this->assertTrue($members->existsByDocumentNumber('CC-1098765432'));
        $searchMembers = $members->search('Mendoza', 1, 20);
        $this->assertSame(1, $searchMembers['total']);
        $this->assertSame('Carlos Mendoza', $searchMembers['items'][0]->fullName());

        // Duplicado de documento lanza DocumentTakenException (409)
        try {
            $members->save(new Member(
                id: Uuid::v4(),
                fullName: 'Otro Socio',
                documentNumber: new DocumentNumber('CC-1098765432'),
            ));
            $this->fail('Debió lanzar DocumentTakenException');
        } catch (DocumentTakenException $e) {
            $this->assertSame('DOCUMENT_TAKEN', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        /** @var BookRepository $books */
        $books = $this->app->make(BookRepository::class);
        $book = Book::create(
            id: Uuid::v4(),
            title: 'Cien años de soledad',
            author: 'Gabriel García Márquez',
            isbn: new Isbn('9780307474728'),
            categoryId: self::CAT_NOVELA,
            totalCopies: new Quantity(4),
            categoryName: 'Novela',
        );
        $books->save($book);

        $foundBook = $books->findById($book->id());
        $this->assertNotNull($foundBook);
        $this->assertSame('Novela', $foundBook->categoryName());
        $this->assertSame(4, $foundBook->availableCopies());

        $searchBooks = $books->search('García', self::CAT_NOVELA, 1, 20);
        $this->assertSame(1, $searchBooks['total']);

        // ISBN duplicado entre libros activos lanza IsbnTakenException (409)
        try {
            $books->save(Book::create(
                id: Uuid::v4(),
                title: 'Otro libro',
                author: 'Otro autor',
                isbn: new Isbn('9780307474728'),
                categoryId: self::CAT_NOVELA,
                totalCopies: new Quantity(1),
            ));
            $this->fail('Debió lanzar IsbnTakenException por índice parcial ix_book_isbn_active');
        } catch (IsbnTakenException $e) {
            $this->assertSame('ISBN_TAKEN', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }
    }

    /**
     * T-14: Adaptador de seguridad (hash de claves y token JWT de 8 horas sin datos personales).
     */
    public function test_t14_security_adapters_hash_and_jwt_token(): void
    {
        /** @var PasswordHasher $hasher */
        $hasher = $this->app->make(PasswordHasher::class);
        $hash = $hasher->hash('MiSecretoSuperSeguro99');
        $this->assertNotSame('MiSecretoSuperSeguro99', $hash);
        $this->assertTrue($hasher->verify('MiSecretoSuperSeguro99', $hash));
        $this->assertFalse($hasher->verify('Incorrecta', $hash));

        /** @var TokenIssuer $tokenIssuer */
        $tokenIssuer = $this->app->make(TokenIssuer::class);
        $user = new User(Uuid::v4(), 'bibliotecario1', $hash, User::ROLE_LIBRARIAN);

        $issued = $tokenIssuer->issue($user);
        $this->assertSame('Bearer', $issued['token_type']);
        $this->assertSame(28800, $issued['expires_in']);
        $this->assertSame('librarian', $issued['role']);
        $this->assertArrayNotHasKey('password', $issued);
        $this->assertArrayNotHasKey('password_hash', $issued);

        $claims = $tokenIssuer->validate($issued['access_token']);
        $this->assertNotNull($claims);
        $this->assertSame($user->id(), $claims['sub']);
        $this->assertSame('librarian', $claims['role']);
    }

    /**
     * T-15: Adaptador de portadas en disco (PNG de hasta 2 MB ok; 3 MB -> 422; sin portada -> cover_key null).
     */
    public function test_t15_cover_storage_adapter_handles_2mb_png_and_rejects_3mb(): void
    {
        /** @var CreateBookService $createBookService */
        $createBookService = $this->app->make(CreateBookService::class);
        /** @var CoverStorage $coverStorage */
        $coverStorage = $this->app->make(CoverStorage::class);

        $book = $createBookService->execute(
            title: 'El amor en los tiempos del cólera',
            author: 'Gabriel García Márquez',
            rawIsbn: '9780307387264',
            categoryId: self::CAT_NOVELA,
            totalCopies: 2,
        );

        // Sin portada -> cover_key es null
        $this->assertNull($book->coverKey());

        // Subir PNG exacto de 2 MB (2,097,152 bytes) -> OK
        $pngHeader = "\x89PNG\r\n\x1a\n";
        $twoMbPng = $pngHeader . str_repeat("\x00", CreateBookService::MAX_COVER_BYTES - strlen($pngHeader));
        $this->assertSame(2097152, strlen($twoMbPng));

        $updatedBook = $createBookService->uploadCover($book->id(), $twoMbPng);
        $coverKey = $updatedBook->coverKey();
        $this->assertNotNull($coverKey);
        $this->assertStringNotContainsString('/', $coverKey);
        $this->assertStringNotContainsString('\\', $coverKey);
        $this->assertStringEndsWith('.png', $coverKey);

        $stored = $coverStorage->get($coverKey);
        $this->assertNotNull($stored);
        $this->assertSame('image/png', $stored['mime_type']);
        $this->assertSame(2097152, strlen($stored['content']));

        // Subir PNG de 3 MB -> 422 VALIDATION_ERROR
        $threeMbPng = $pngHeader . str_repeat("\x00", (3 * 1024 * 1024) - strlen($pngHeader));
        try {
            $createBookService->uploadCover($book->id(), $threeMbPng);
            $this->fail('Debió rechazar portada de 3 MB con 422 VALIDATION_ERROR');
        } catch (ValidationException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }

        // Quitar portada -> primero anula cover_key en DB y luego borra el binario
        $clearedBook = $createBookService->deleteCover($book->id());
        $this->assertNull($clearedBook->coverKey());
        $this->assertNull($coverStorage->get($coverKey));
    }

    /**
     * T-18: Las invariantes ck_book_total_positive y ck_user_role están impuestas en el motor PostgreSQL.
     */
    public function test_t18_database_check_constraints_reject_invalid_sql_inserts(): void
    {
        // Violación de ck_book_total_positive (total_copies = 0)
        try {
            DB::statement(
                "INSERT INTO library.book (id, title, author, isbn, category_id, total_copies, available_copies, cover_key, deleted_at)
                 VALUES (:id, 'Libro Inválido', 'Autor', '9780307474728', :cat, 0, 0, NULL, NULL)",
                ['id' => Uuid::v4(), 'cat' => self::CAT_CIENCIA]
            );
            $this->fail('PostgreSQL debió rechazar total_copies = 0 por ck_book_total_positive');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) ($e->errorInfo[0] ?? $e->getCode()));
            $this->assertStringContainsString('ck_book_total_positive', $e->getMessage());
        }

        // Violación de ck_user_role (role = 'superadmin')
        try {
            DB::statement(
                'INSERT INTO library."user" (id, username, password_hash, role)
                 VALUES (:id, :username, :hash, :role)',
                [
                    'id' => Uuid::v4(),
                    'username' => 'hacker',
                    'hash' => 'hash_valido',
                    'role' => 'superadmin',
                ]
            );
            $this->fail('PostgreSQL debió rechazar role = superadmin por ck_user_role');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) ($e->errorInfo[0] ?? $e->getCode()));
            $this->assertStringContainsString('ck_user_role', $e->getMessage());
        }
    }
}
