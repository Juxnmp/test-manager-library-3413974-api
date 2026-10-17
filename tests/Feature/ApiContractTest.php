<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\Outbound\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-16, T-17, RNF-3, RNF-4, ADR-004:
 * Prueba de contrato HTTP que recorre todos los endpoints de api-contract.md §3
 * y verifica todos los 14 códigos de error de negocio de §2 con el sobre único {code, message, details}.
 */
final class ApiContractTest extends TestCase
{
    private const string CAT_NOVELA = '11111111-1111-4111-8111-111111111111';
    private const string CAT_CIENCIA = '22222222-2222-4222-8222-222222222222';
    private const string CAT_HISTORIA = '44444444-4444-4444-8444-444444444444';

    private object $testClock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanMutableTables();

        $initial = new DateTimeImmutable('2026-10-14T14:15:00Z', new DateTimeZone('UTC'));
        $this->testClock = new class($initial) implements Clock {
            public function __construct(public DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };

        $this->app->singleton(Clock::class, fn (): Clock => $this->testClock);
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

    private function advanceDays(int $days): void
    {
        $this->testClock->now = $this->testClock->now->modify("+{$days} days");
    }

    /**
     * T-16 & T-17: Recorrido completo del contrato HTTP y verificación de los 14 códigos de error de §2.
     */
    public function test_full_api_contract_routes_and_all_14_error_codes(): void
    {
        // 1. T-17 & HU-01: Base vacía -> login con credenciales de entorno provisiona admin inicial y devuelve JWT 8h
        $loginFail = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'ClaveIncorrecta',
        ]);
        $loginFail->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS')
            ->assertJsonStructure(['code', 'message', 'details']);

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'AdminTest123*!',
        ]);
        $adminLogin->assertStatus(200)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 28800)
            ->assertJsonPath('role', 'admin');
        $adminToken = (string) $adminLogin->json('access_token');
        $this->assertNotEmpty($adminToken);

        // Sin token -> 401 INVALID_CREDENTIALS
        $this->getJson('/api/v1/categories')
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        // 2. HU-02: POST /api/v1/users (admin crea bibliotecario)
        // CA-02.3: Pedir rol admin por la API -> 422 VALIDATION_ERROR
        $this->withToken($adminToken)->postJson('/api/v1/users', [
            'username' => 'otro_admin',
            'password' => 'Clave1234',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        // CA-02.1 & CA-02.2: Crear bibliotecario normaliza "Ana " -> "ana" y no expone password ni password_hash
        $createLib = $this->withToken($adminToken)->postJson('/api/v1/users', [
            'username' => 'Ana ',
            'password' => 'Biblio1234',
            'role' => 'librarian',
        ]);
        $createLib->assertStatus(201)
            ->assertJsonPath('username', 'ana')
            ->assertJsonPath('role', 'librarian');
        $this->assertArrayNotHasKey('password', $createLib->json());
        $this->assertArrayNotHasKey('password_hash', $createLib->json());

        // CA-02.2: Usuario repetido -> 409 USERNAME_TAKEN
        $this->withToken($adminToken)->postJson('/api/v1/users', [
            'username' => 'ANA',
            'password' => 'Biblio1234',
            'role' => 'librarian',
        ])->assertStatus(409)->assertJsonPath('code', 'USERNAME_TAKEN');

        // Login como bibliotecaria "ana"
        $libLogin = $this->postJson('/api/v1/auth/login', [
            'username' => 'ana',
            'password' => 'Biblio1234',
        ]);
        $libLogin->assertStatus(200)->assertJsonPath('role', 'librarian');
        $libToken = (string) $libLogin->json('access_token');

        // CA-01.3: Bibliotecaria intentando crear usuario o libro -> 403 FORBIDDEN_ROLE
        $this->withToken($libToken)->postJson('/api/v1/users', [
            'username' => 'nuevo',
            'password' => 'Clave1234',
            'role' => 'librarian',
        ])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN_ROLE');

        // 3. Categorías: GET /api/v1/categories
        $cats = $this->withToken($libToken)->getJson('/api/v1/categories');
        $cats->assertStatus(200)->assertJsonCount(5);

        // 4. HU-03 & HU-04: Libros
        $book1Resp = $this->withToken($adminToken)->postJson('/api/v1/books', [
            'title' => 'Cien años de soledad',
            'author' => 'Gabriel García Márquez',
            'isbn' => '9780307474728',
            'category_id' => self::CAT_NOVELA,
            'total_copies' => 1,
        ]);
        $book1Resp->assertStatus(201)
            ->assertJsonPath('title', 'Cien años de soledad')
            ->assertJsonPath('available_copies', 1)
            ->assertJsonPath('cover_key', null)
            ->assertJsonPath('category.name', 'Novela');
        $book1Id = (string) $book1Resp->json('id');

        // CA-03.2: ISBN repetido -> 409 ISBN_TAKEN
        $this->withToken($adminToken)->postJson('/api/v1/books', [
            'title' => 'Duplicado',
            'author' => 'Autor',
            'isbn' => '9780307474728',
            'category_id' => self::CAT_NOVELA,
            'total_copies' => 2,
        ])->assertStatus(409)->assertJsonPath('code', 'ISBN_TAKEN');

        // Crear 3 libros adicionales para pruebas de préstamos
        $book2Id = (string) $this->withToken($adminToken)->postJson('/api/v1/books', [
            'title' => 'El coronel no tiene quien le escriba',
            'author' => 'Gabriel García Márquez',
            'isbn' => '9780307387264',
            'category_id' => self::CAT_NOVELA,
            'total_copies' => 2,
        ])->assertStatus(201)->json('id');

        $book3Id = (string) $this->withToken($adminToken)->postJson('/api/v1/books', [
            'title' => 'Breve historia del tiempo',
            'author' => 'Stephen Hawking',
            'isbn' => '9788420431321',
            'category_id' => self::CAT_CIENCIA,
            'total_copies' => 2,
        ])->assertStatus(201)->json('id');

        $book4Id = (string) $this->withToken($adminToken)->postJson('/api/v1/books', [
            'title' => 'Sapiens',
            'author' => 'Yuval Noah Harari',
            'isbn' => '9788437604947',
            'category_id' => self::CAT_HISTORIA,
            'total_copies' => 2,
        ])->assertStatus(201)->json('id');

        // Subir portada PNG válida (PUT /api/v1/books/{id}/cover)
        $pngBytes = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 128);
        $coverFile = UploadedFile::fake()->createWithContent('portada.png', $pngBytes);
        $uploadResp = $this->withToken($adminToken)->put("/api/v1/books/{$book1Id}/cover", [
            'file' => $coverFile,
        ]);
        $uploadResp->assertStatus(200);
        $this->assertNotNull($uploadResp->json('cover_key'));

        // Descargar portada binaria (GET /api/v1/books/{id}/cover)
        $getCoverResp = $this->withToken($libToken)->get("/api/v1/books/{$book1Id}/cover");
        $getCoverResp->assertStatus(200)->assertHeader('Content-Type', 'image/png');

        // Quitar portada (DELETE /api/v1/books/{id}/cover)
        $this->withToken($adminToken)->deleteJson("/api/v1/books/{$book1Id}/cover")
            ->assertStatus(204);
        $this->withToken($libToken)->getJson("/api/v1/books/{$book1Id}/cover")
            ->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');

        // Búsqueda paginada de libros (GET /api/v1/books)
        $searchResp = $this->withToken($libToken)->getJson('/api/v1/books?q=García&page=1&size=10');
        $searchResp->assertStatus(200)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('size', 10);

        // 5. HU-05: Socios
        $member1Resp = $this->withToken($libToken)->postJson('/api/v1/members', [
            'full_name' => 'María Fernanda López',
            'document_number' => 'CC-52111222',
        ]);
        $member1Resp->assertStatus(201)->assertJsonPath('document_number', 'CC-52111222');
        $member1Id = (string) $member1Resp->json('id');

        $member2Id = (string) $this->withToken($libToken)->postJson('/api/v1/members', [
            'full_name' => 'Pedro Pérez',
            'document_number' => 'CC-80333444',
        ])->assertStatus(201)->json('id');

        // CA-05.1: Documento repetido -> 409 DOCUMENT_TAKEN
        $this->withToken($libToken)->postJson('/api/v1/members', [
            'full_name' => 'Otra Persona',
            'document_number' => 'CC-52111222',
        ])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_TAKEN');

        // Listar socios y ver detalle con active_loans
        $this->withToken($libToken)->getJson('/api/v1/members?q=María')
            ->assertStatus(200)->assertJsonPath('total', 1);
        $this->withToken($libToken)->getJson("/api/v1/members/{$member1Id}")
            ->assertStatus(200)->assertJsonPath('active_loans', 0);

        // 6. HU-06: Préstamos y códigos de error
        // CA-06.2: Libro repetido en el mismo préstamo -> 422 DUPLICATE_BOOK_IN_LOAN
        $this->withToken($libToken)->postJson('/api/v1/loans', [
            'member_id' => $member1Id,
            'items' => [
                ['book_id' => $book1Id],
                ['book_id' => $book1Id],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_BOOK_IN_LOAN');

        // Registrar préstamo válido de 3 libros para member1 (book1 tiene 1 ejemplar y pasa a 0)
        $loan1Resp = $this->withToken($libToken)->postJson('/api/v1/loans', [
            'member_id' => $member1Id,
            'items' => [
                ['book_id' => $book1Id],
                ['book_id' => $book2Id],
                ['book_id' => $book3Id],
            ],
        ]);
        $loan1Resp->assertStatus(201)
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('loaned_at', '2026-10-14T14:15:00Z')
            ->assertJsonPath('items.0.due_at', '2026-10-28T14:15:00Z');
        $loan1Id = (string) $loan1Resp->json('id');
        $loan1Item1Id = (string) $loan1Resp->json('items.0.id');

        // CA-06.3: Sin ejemplares disponibles de book1 -> 409 BOOK_NOT_AVAILABLE
        $this->withToken($libToken)->postJson('/api/v1/loans', [
            'member_id' => $member2Id,
            'items' => [
                ['book_id' => $book1Id],
            ],
        ])->assertStatus(409)->assertJsonPath('code', 'BOOK_NOT_AVAILABLE');

        // CA-06.4: member1 ya tiene 3 libros sin devolver; pedir un 4to -> 422 MEMBER_LIMIT_EXCEEDED
        $this->withToken($libToken)->postJson('/api/v1/loans', [
            'member_id' => $member1Id,
            'items' => [
                ['book_id' => $book4Id],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'MEMBER_LIMIT_EXCEEDED');

        // CA-03.5: Eliminar libro con préstamos abiertos -> 409 BOOK_HAS_OPEN_LOANS
        $this->withToken($adminToken)->deleteJson("/api/v1/books/{$book1Id}")
            ->assertStatus(409)->assertJsonPath('code', 'BOOK_HAS_OPEN_LOANS');

        // 7. HU-07: Devolver primera línea (book1)
        $returnResp = $this->withToken($libToken)->postJson("/api/v1/loans/{$loan1Id}/items/{$loan1Item1Id}/return");
        $returnResp->assertStatus(200)
            ->assertJsonPath('id', $loan1Item1Id)
            ->assertJsonPath('returned_at', '2026-10-14T14:15:00Z');

        // CA-07.2: Devolver dos veces la misma línea -> 409 ITEM_ALREADY_RETURNED
        $this->withToken($libToken)->postJson("/api/v1/loans/{$loan1Id}/items/{$loan1Item1Id}/return")
            ->assertStatus(409)->assertJsonPath('code', 'ITEM_ALREADY_RETURNED');

        // 8. HU-08 & HU-09: Avanzar reloj 20 días -> líneas restantes quedan vencidas (6 días de retraso)
        $this->advanceDays(20);

        // CA-01.1 & CA-01.3: Tras 20 días (> 8 horas), el token anterior ha expirado -> 401 INVALID_CREDENTIALS
        $this->withToken($libToken)->getJson("/api/v1/loans/{$loan1Id}")
            ->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

        // Re-autenticar en el día +20 para obtener tokens vigentes
        $adminToken = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'AdminTest123*!',
        ])->assertStatus(200)->json('access_token');

        $libToken = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'ana',
            'password' => 'Biblio1234',
        ])->assertStatus(200)->json('access_token');

        // GET /api/v1/loans/{id} muestra status calculado = overdue
        $this->withToken($libToken)->getJson("/api/v1/loans/{$loan1Id}")
            ->assertStatus(200)->assertJsonPath('status', 'overdue');

        // GET /api/v1/loans?status=overdue
        $this->withToken($libToken)->getJson('/api/v1/loans?status=overdue')
            ->assertStatus(200)->assertJsonPath('total', 1);

        // GET /api/v1/loans/overdue devuelve las 2 líneas vencidas con member_name y days_late = 6
        $overdueResp = $this->withToken($libToken)->getJson('/api/v1/loans/overdue');
        $overdueResp->assertStatus(200)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.member_name', 'María Fernanda López')
            ->assertJsonPath('items.0.days_late', 6);

        // CA-06.5: Socio con vencidos no puede pedir otro -> 409 MEMBER_HAS_OVERDUE
        $this->withToken($libToken)->postJson('/api/v1/loans', [
            'member_id' => $member1Id,
            'items' => [
                ['book_id' => $book4Id],
            ],
        ])->assertStatus(409)->assertJsonPath('code', 'MEMBER_HAS_OVERDUE');

        // 9. HU-10 & ADR-004: Renombrar book1 y cambiar su categoría en el catálogo vivo;
        // el reporte de más prestados conserva el título y categoría congelados en la línea de préstamo.
        $this->withToken($adminToken)->putJson("/api/v1/books/{$book1Id}", [
            'title' => 'Cien años de soledad (Edición Conmemorativa Renombrada)',
            'author' => 'Gabriel García Márquez',
            'category_id' => self::CAT_HISTORIA,
            'total_copies' => 2,
        ])->assertStatus(200);

        // CA-10.2: Rango invertido -> 422 INVALID_DATE_RANGE
        $this->withToken($adminToken)->getJson('/api/v1/reports/most-borrowed?from=2026-10-31&to=2026-10-01')
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_DATE_RANGE');

        // CA-10.1 & CA-10.4: El reporte devuelve el título viejo congelado ("Cien años de soledad") y categoría ("Novela")
        $reportResp = $this->withToken($adminToken)->getJson('/api/v1/reports/most-borrowed?from=2026-10-01&to=2026-10-31');
        $reportResp->assertStatus(200)
            ->assertJsonPath('from', '2026-10-01')
            ->assertJsonPath('to', '2026-10-31')
            ->assertJsonCount(3, 'rows');

        $rowsByBookId = collect($reportResp->json('rows'))->keyBy('book_id');
        $this->assertSame('Cien años de soledad', $rowsByBookId[$book1Id]['book_title']);
        $this->assertSame('Novela', $rowsByBookId[$book1Id]['category_name']);
        $this->assertSame(1, $rowsByBookId[$book1Id]['times_borrowed']);

        // Ahora que book1 no tiene préstamos abiertos (fue devuelto en el paso 7), puede darse de baja lógica (204)
        $this->withToken($adminToken)->deleteJson("/api/v1/books/{$book1Id}")
            ->assertStatus(204);

        // CA-04.3: Consultar libro dado de baja -> 404 NOT_FOUND
        $this->withToken($libToken)->getJson("/api/v1/books/{$book1Id}")
            ->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
    }
}
