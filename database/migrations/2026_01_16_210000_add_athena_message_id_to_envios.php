<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega campo para almacenar el messageID de Athena Campaign.
     * 
     * Esto permite sincronizar estadísticas (aperturas, clicks, bounces, unsubscribes)
     * desde la API de Athena.
     */
    public function up(): void
    {
        if (Schema::hasColumn('envios', 'athena_message_id')) {
            return; // Columna ya existe
        }

        Schema::table('envios', function (Blueprint $table) {
            $table->string('athena_message_id', 50)->nullable()->after('tracking_token');
            $table->timestamp('athena_synced_at')->nullable()->after('athena_message_id');
            
            $table->index('athena_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropIndex(['athena_message_id']);
            $table->dropColumn(['athena_message_id', 'athena_synced_at']);
        });
    }
};
