<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration tests for query optimization indexes.
 *
 * These tests verify that performance indexes exist after migration.
 * Run after `php artisan migrate` to validate index creation.
 *
 * @see SDD Change: query-optimization
 */
class QueryOptimizationIndexesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_idx_prospectos_importacion_id_exists(): void
    {
        $indexExists = $this->indexExists('prospectos', 'idx_prospectos_importacion_id');

        $this->assertTrue($indexExists, 'Index idx_prospectos_importacion_id should exist on prospectos table');
    }

    /** @test */
    public function test_idx_envios_tracking_token_exists(): void
    {
        $indexExists = $this->indexExists('envios', 'idx_envios_tracking_token');

        $this->assertTrue($indexExists, 'Index idx_envios_tracking_token should exist on envios table');
    }

    /** @test */
    public function test_idx_email_aperturas_tracking_token_exists(): void
    {
        $indexExists = $this->indexExists('email_aperturas', 'idx_email_aperturas_tracking_token');

        $this->assertTrue($indexExists, 'Index idx_email_aperturas_tracking_token should exist on email_aperturas table');
    }

    /** @test */
    public function test_idx_envios_prospecto_id_exists(): void
    {
        $indexExists = $this->indexExists('envios', 'idx_envios_prospecto_id');

        $this->assertTrue($indexExists, 'Index idx_envios_prospecto_id should exist on envios table');
    }

    /**
     * Helper to check if an index exists in PostgreSQL.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        ) !== null;
    }
}
