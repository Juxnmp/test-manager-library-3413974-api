# test-manager-library-3413974-api — Backend (PHP 8.3 / Laravel 11)

> **Prueba técnica · Ficha ADSO 3413974 — Manager Library**

Backend de **Manager Library** implementado en **PHP 8.3 con Laravel 11**, siguiendo **Desarrollo Guiado por Especificaciones (SDD)** y **Arquitectura Hexagonal (Puertos y Adaptadores)** conforme a [`constitution.md`](https://github.com/Juxnmp/test-manager-library-3413974-docs) y [`architecture.md`](https://github.com/Juxnmp/test-manager-library-3413974-docs).

---

## Arquitectura Hexagonal (Capas y Reglas de Dependencia)

```text
app/
├── Domain/                          # Artículo I: Puro PHP, sin dependencias de framework ni ORM
│   ├── Entities/                    # Agregados: Book, Loan, LoanItem, Member, User
│   ├── ValueObjects/                # Objetos de valor: Isbn, Quantity, DateRange, DocumentNumber, Uuid
│   └── Exceptions/                  # Errores de dominio mapeados a los 14 códigos de api-contract.md §2
├── Application/                     # Artículo II: Solo depende de Domain; declara sus propios puertos
│   ├── Ports/Outbound/              # Interfaces: BookRepository, LoanRepository, MemberRepository,
│   │                                # UserRepository, CategoryRepository, ReportReader,
│   │                                # Clock, PasswordHasher, TokenIssuer, CoverStorage
│   └── Services/                    # Casos de uso: LoginService, CreateLibrarianService,
│                                    # CreateBookService, SearchBooksService, MemberService,
│                                    # LendBooksService, ReturnItemService, OverdueService,
│                                    # MostBorrowedReportService
├── Bootstrap/Composition/           # Artículo III: PortBindings (único punto de composición DI)
├── Http/                            # Adaptador de entrada HTTP (Controladores, Middleware JWT, ApiResponse)
├── Models/                          # Modelos de persistencia Eloquent sobre el esquema library.*
└── Console/Commands/                # ProvisionAdminCommand (app:provision-admin)
```

---

## Decisiones Técnicas Documentadas

1. **Propiedad del esquema (`ADR-001`, `data-model.md` §3 y §10):**
   - El esquema `library` en PostgreSQL 16 contiene exclusivamente las 6 tablas del modelo físico (`category`, `book`, `member`, `loan`, `loan_item`, `"user"`), sin valores `DEFAULT` ni columnas de auditoría (`created_at`/`updated_at`).
   - La tabla de control `migrations` de Laravel se aloja en el esquema `public` (`DB_SCHEMA=public,library`) para que las consultas de verificación de `data-model.md` §10 devuelvan exactamente las 6 tablas del dominio.
   - Las 5 categorías fijas (`Novela`, `Ciencia`, `Tecnología`, `Historia`, `Infantil`) se siembran en la migración inicial con sus UUID literales documentados en `data-model.md` §9.
2. **Invariantes en el motor (`constitution.md` Art. VI y X):**
   - Se imponen en PostgreSQL `ck_book_available_range` (`0 <= available_copies <= total_copies`), `ck_book_total_positive` (`total_copies >= 1`), `ck_user_role` (`role IN ('admin', 'librarian')`), el índice único parcial `ix_book_isbn_active` (`WHERE deleted_at IS NULL`) y las claves foráneas `RESTRICT` / `CASCADE`.
3. **Valores congelados y estado calculado (`constitution.md` Art. VII y VIII, `ADR-004`):**
   - `LoanItem` congela `book_title`, `isbn` y `category_name` en el instante del préstamo (`due_at = loaned_at + 14 días`).
   - El estado del préstamo (`open`, `overdue`, `closed`) y los días de retraso (`days_late`) se calculan dinámicamente usando el puerto `Clock` en UTC y nunca se almacenan en base de datos.

---

## Variables de Entorno Principales (`.env`)

Ver [`.env.example`](.env.example):

| Variable | Descripción |
|---|---|
| `DB_CONNECTION=pgsql` | Motor PostgreSQL 16 |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a PostgreSQL (`library`) |
| `DB_SCHEMA=public,library` | Esquema `public` para `migrations` y `library` para las 6 tablas del dominio |
| `JWT_SECRET` | Clave secreta para firma JWT HS256 (vigencia de 8 horas / 28800 s) |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD` | Credenciales para aprovisionar el usuario `admin` inicial |
| `CORS_ORIGIN` / `FRONTEND_URL` | Origen permitido del frontend React (`http://localhost:5173`) |
| `COVERS_DIR` | Directorio de almacenamiento de portadas opacas |

---

## Ejecución Local y Pruebas

```bash
# 1. Ejecutar migraciones en PostgreSQL 16
php artisan migrate

# 2. Aprovisionar administrador inicial desde variables de entorno
php artisan app:provision-admin

# 3. Ejecutar suite de pruebas (Dominio, Aplicación con Fakes y Esquema/Health)
./vendor/bin/phpunit
```
