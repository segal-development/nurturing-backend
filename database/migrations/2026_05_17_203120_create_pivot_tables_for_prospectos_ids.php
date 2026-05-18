<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        // Data backfill from prospectos_ids JSON is intentionally NOT done here.
        // Run `php artisan nurturing:migrate-prospectos-ids-to-pivot` manually.
        // Reason: backfilling at boot would exceed Cloud Run's startup timeout.
    }

    public function down(): void
    {
        Schema::dropIfExists('etapa_prospecto');
        Schema::dropIfExists('ejecucion_prospecto');
    }
};
