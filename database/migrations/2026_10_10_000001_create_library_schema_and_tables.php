<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create schema library
        DB::statement('CREATE SCHEMA IF NOT EXISTS library;');

        // 2. Table library.category
        DB::statement('
            CREATE TABLE library.category (
                id uuid NOT NULL,
                name varchar(120) NOT NULL,
                CONSTRAINT pk_category PRIMARY KEY (id),
                CONSTRAINT uq_category_name UNIQUE (name)
            );
        ');

        // 3. Table library."user"
        DB::statement('
            CREATE TABLE library."user" (
                id uuid NOT NULL,
                username varchar(120) NOT NULL,
                password_hash varchar(512) NOT NULL,
                role varchar(40) NOT NULL,
                CONSTRAINT pk_user PRIMARY KEY (id),
                CONSTRAINT uq_user_username UNIQUE (username),
                CONSTRAINT ck_user_role CHECK (role IN (\'admin\', \'librarian\'))
            );
        ');

        // 4. Table library.member
        DB::statement('
            CREATE TABLE library.member (
                id uuid NOT NULL,
                full_name varchar(200) NOT NULL,
                document_number varchar(30) NOT NULL,
                CONSTRAINT pk_member PRIMARY KEY (id),
                CONSTRAINT uq_member_document_number UNIQUE (document_number)
            );
        ');

        // 5. Table library.book
        DB::statement('
            CREATE TABLE library.book (
                id uuid NOT NULL,
                title varchar(250) NOT NULL,
                author varchar(200) NOT NULL,
                isbn char(13) NOT NULL,
                category_id uuid NOT NULL,
                total_copies integer NOT NULL,
                available_copies integer NOT NULL,
                cover_key varchar(512) NULL,
                deleted_at timestamptz NULL,
                CONSTRAINT pk_book PRIMARY KEY (id),
                CONSTRAINT fk_book_category FOREIGN KEY (category_id) REFERENCES library.category(id) ON DELETE RESTRICT,
                CONSTRAINT ck_book_available_range CHECK (available_copies >= 0 AND available_copies <= total_copies),
                CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1)
            );
        ');

        DB::statement('
            CREATE UNIQUE INDEX ix_book_isbn_active ON library.book (isbn) WHERE deleted_at IS NULL;
        ');
        DB::statement('
            CREATE INDEX ix_book_category_title ON library.book (category_id, title) WHERE deleted_at IS NULL;
        ');

        // 6. Table library.loan
        DB::statement('
            CREATE TABLE library.loan (
                id uuid NOT NULL,
                member_id uuid NOT NULL,
                loaned_by_user_id uuid NOT NULL,
                loaned_at timestamptz NOT NULL,
                CONSTRAINT pk_loan PRIMARY KEY (id),
                CONSTRAINT fk_loan_member FOREIGN KEY (member_id) REFERENCES library.member(id) ON DELETE RESTRICT,
                CONSTRAINT fk_loan_user FOREIGN KEY (loaned_by_user_id) REFERENCES library."user"(id) ON DELETE RESTRICT
            );
        ');

        DB::statement('
            CREATE INDEX ix_loan_loaned_at ON library.loan (loaned_at);
        ');

        // 7. Table library.loan_item
        DB::statement('
            CREATE TABLE library.loan_item (
                id uuid NOT NULL,
                loan_id uuid NOT NULL,
                book_id uuid NOT NULL,
                book_title varchar(250) NOT NULL,
                isbn char(13) NOT NULL,
                category_name varchar(120) NOT NULL,
                due_at timestamptz NOT NULL,
                returned_at timestamptz NULL,
                CONSTRAINT pk_loan_item PRIMARY KEY (id),
                CONSTRAINT fk_loan_item_loan FOREIGN KEY (loan_id) REFERENCES library.loan(id) ON DELETE CASCADE,
                CONSTRAINT fk_loan_item_book FOREIGN KEY (book_id) REFERENCES library.book(id) ON DELETE RESTRICT
            );
        ');

        DB::statement('
            CREATE UNIQUE INDEX ix_loan_item_loan_book ON library.loan_item (loan_id, book_id);
        ');
        DB::statement('
            CREATE INDEX ix_loan_item_book_id ON library.loan_item (book_id);
        ');
        DB::statement('
            CREATE INDEX ix_loan_item_overdue ON library.loan_item (due_at) WHERE returned_at IS NULL;
        ');

        // 8. Seed 5 fixed categories per spec data-model.md §9
        DB::statement("
            INSERT INTO library.category (id, name) VALUES
            ('11111111-1111-4111-8111-111111111111', 'Novela'),
            ('22222222-2222-4222-8222-222222222222', 'Ciencia'),
            ('33333333-3333-4333-8333-333333333333', 'Tecnología'),
            ('44444444-4444-4444-8444-444444444444', 'Historia'),
            ('55555555-5555-4555-8555-555555555555', 'Infantil');
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS library.loan_item CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.loan CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.book CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.member CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library."user" CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.category CASCADE;');
        DB::statement('DROP SCHEMA IF EXISTS library CASCADE;');
    }
};
