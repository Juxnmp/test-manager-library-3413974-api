<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HealthAndSchemaTest extends TestCase
{
    /**
     * T-01: GET /health and GET /api/v1/health respond 200 {"status":"ok"}.
     */
    public function test_health_endpoints_return_ok(): void
    {
        $responseV1 = $this->getJson('/api/v1/health');
        $responseV1->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $responseRoot = $this->getJson('/health');
        $responseRoot->assertStatus(200)->assertExactJson(['status' => 'ok']);
    }

    /**
     * T-02: Verify data-model.md §10 Query 1 — exact 6 tables in library schema and zero DEFAULTs.
     */
    public function test_schema_columns_have_six_tables_and_no_defaults(): void
    {
        $columns = DB::select("
            SELECT table_name, ordinal_position, column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = 'library'
            ORDER BY 1, 2
        ");

        $this->assertNotEmpty($columns);

        $tables = array_values(array_unique(array_map(static fn ($col) => $col->table_name, $columns)));
        sort($tables);

        $this->assertSame(['book', 'category', 'loan', 'loan_item', 'member', 'user'], $tables);

        foreach ($columns as $col) {
            $this->assertNull(
                $col->column_default,
                "Column {$col->table_name}.{$col->column_name} must not have a DEFAULT value."
            );
            $this->assertNotContains(
                $col->column_name,
                ['created_at', 'updated_at', 'status', 'days_late'],
                "Forbidden column {$col->table_name}.{$col->column_name} found in schema."
            );
        }
    }

    /**
     * T-02: Verify data-model.md §10 Query 2 & 3 and §9 fixed 5 categories seed.
     */
    public function test_schema_constraints_indexes_and_seeded_categories(): void
    {
        $constraints = DB::select("
            SELECT c.conname, c.contype
            FROM pg_constraint c
            JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = 'library'
        ");

        $constraintNames = array_map(static fn ($c) => $c->conname, $constraints);

        $expectedConstraints = [
            'pk_category',
            'uq_category_name',
            'pk_user',
            'uq_user_username',
            'ck_user_role',
            'pk_member',
            'uq_member_document_number',
            'pk_book',
            'fk_book_category',
            'ck_book_available_range',
            'ck_book_total_positive',
            'pk_loan',
            'fk_loan_member',
            'fk_loan_user',
            'pk_loan_item',
            'fk_loan_item_loan',
            'fk_loan_item_book',
        ];

        foreach ($expectedConstraints as $expected) {
            $this->assertContains($expected, $constraintNames, "Missing constraint: {$expected}");
        }

        $indexes = DB::select("
            SELECT indexname FROM pg_indexes WHERE schemaname = 'library'
        ");
        $indexNames = array_map(static fn ($i) => $i->indexname, $indexes);

        foreach (['ix_book_isbn_active', 'ix_book_category_title', 'ix_loan_loaned_at', 'ix_loan_item_loan_book', 'ix_loan_item_book_id', 'ix_loan_item_overdue'] as $idx) {
            $this->assertContains($idx, $indexNames, "Missing index: {$idx}");
        }

        $categories = DB::select('SELECT id, name FROM library.category ORDER BY id');
        $actualCategories = array_map(static fn ($c) => ['id' => $c->id, 'name' => $c->name], $categories);

        $this->assertSame([
            ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Novela'],
            ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Ciencia'],
            ['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Tecnología'],
            ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Historia'],
            ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Infantil'],
        ], $actualCategories);
    }
}
