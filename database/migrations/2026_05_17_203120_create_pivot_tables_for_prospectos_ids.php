<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ejecucion_prospecto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_ejecucion_id')->constrained('flujo_ejecuciones')->cascadeOnDelete();
            $table->foreignId('prospecto_id')->constrained('prospectos')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['flujo_ejecucion_id', 'prospecto_id']);
            $table->index('prospecto_id');
        });

        Schema::create('etapa_prospecto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flujo_ejecucion_etapa_id')->constrained('flujo_ejecucion_etapas')->cascadeOnDelete();
            $table->foreignId('prospecto_id')->constrained('prospectos')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['flujo_ejecucion_etapa_id', 'prospecto_id']);
            $table->index('prospecto_id');
        });

        // Migrate existing JSON data to ejecucion_prospecto
        DB::statement("
            INSERT INTO ejecucion_prospecto (flujo_ejecucion_id, prospecto_id)
            SELECT fe.id, p.prospecto_id::bigint
            FROM flujo_ejecuciones fe,
                 LATERAL jsonb_array_elements_text(fe.prospectos_ids::jsonb) AS p(prospecto_id)
            WHERE fe.prospectos_ids IS NOT NULL
              AND fe.prospectos_ids::text != '[]'
            ON CONFLICT DO NOTHING
        ");

        // Migrate existing JSON data to etapa_prospecto
        DB::statement("
            INSERT INTO etapa_prospecto (flujo_ejecucion_etapa_id, prospecto_id)
            SELECT fee.id, p.prospecto_id::bigint
            FROM flujo_ejecucion_etapas fee,
                 LATERAL jsonb_array_elements_text(fee.prospectos_ids::jsonb) AS p(prospecto_id)
            WHERE fee.prospectos_ids IS NOT NULL
              AND fee.prospectos_ids::text != '[]'
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('etapa_prospecto');
        Schema::dropIfExists('ejecucion_prospecto');
    }
};
