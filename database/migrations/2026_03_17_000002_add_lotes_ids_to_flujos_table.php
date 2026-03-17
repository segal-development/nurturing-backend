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
        Schema::table('flujos', function (Blueprint $table) {
            $table->json('lotes_ids')->nullable()->after('origen')
                ->comment('IDs de lotes específicos para filtrar prospectos (prioridad sobre origen)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->dropColumn('lotes_ids');
        });
    }
};
