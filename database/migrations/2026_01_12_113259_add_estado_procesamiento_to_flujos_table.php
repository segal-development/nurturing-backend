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
            $table->enum('estado_procesamiento', ['pendiente', 'procesando', 'completado', 'fallido'])
                ->default('pendiente')
                ->after('activo')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->dropColumn('estado_procesamiento');
        });
    }
};
