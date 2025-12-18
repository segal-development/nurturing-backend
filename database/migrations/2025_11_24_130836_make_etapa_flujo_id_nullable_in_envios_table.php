<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            // Drop the foreign key first
            $table->dropForeign(['etapa_flujo_id']);

            // Make the column nullable
            $table->foreignId('etapa_flujo_id')
                ->nullable()
                ->change();

            // Re-add the foreign key with nullable support
            $table->foreign('etapa_flujo_id')
                ->references('id')
                ->on('etapas_flujo')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropForeign(['etapa_flujo_id']);

            $table->foreignId('etapa_flujo_id')
                ->nullable(false)
                ->change();

            $table->foreign('etapa_flujo_id')
                ->references('id')
                ->on('etapas_flujo')
                ->onDelete('cascade');
        });
    }
};
