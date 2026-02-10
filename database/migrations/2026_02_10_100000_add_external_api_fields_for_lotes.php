<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para integrar APIs externas con el sistema de lotes.
 *
 * Cambios:
 * 1. external_api_sources: agregar campos para clasificación y sync
 * 2. lotes: agregar FK a external_api_sources para saber si viene de API
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar campos a external_api_sources para clasificación
        Schema::table('external_api_sources', function (Blueprint $table) {
            // Campo de la API que se usa para clasificar (ej: "status", "product")
            $table->string('clasificacion_field')->nullable()->after('field_mapping');

            // Valores posibles del campo de clasificación (ej: ["form", "iniciado", "pagado"])
            // Esto se auto-detecta en el primer sync o se configura manualmente
            $table->json('clasificacion_values')->nullable()->after('clasificacion_field');

            // Filtros a aplicar al llamar la API (ej: {"status": "!pagado"})
            $table->json('sync_filters')->nullable()->after('clasificacion_values');

            // Frecuencia de sync: manual, hourly, daily, weekly
            $table->string('sync_frequency')->default('manual')->after('sync_filters');

            // Prefijo para nombres de lotes (ej: "IC" -> "IC_form_2026-02-10")
            $table->string('lote_prefix')->nullable()->after('sync_frequency');
        });

        // 2. Agregar FK a lotes para vincular con external_api_sources
        Schema::table('lotes', function (Blueprint $table) {
            $table->foreignId('external_api_source_id')
                ->nullable()
                ->after('user_id')
                ->constrained('external_api_sources')
                ->nullOnDelete();

            // Valor de clasificación de este lote (ej: "form", "iniciado")
            $table->string('clasificacion_value')->nullable()->after('external_api_source_id');

            // Índice para buscar lotes por fuente
            $table->index('external_api_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropForeign(['external_api_source_id']);
            $table->dropIndex(['external_api_source_id']);
            $table->dropColumn(['external_api_source_id', 'clasificacion_value']);
        });

        Schema::table('external_api_sources', function (Blueprint $table) {
            $table->dropColumn([
                'clasificacion_field',
                'clasificacion_values',
                'sync_filters',
                'sync_frequency',
                'lote_prefix',
            ]);
        });
    }
};
