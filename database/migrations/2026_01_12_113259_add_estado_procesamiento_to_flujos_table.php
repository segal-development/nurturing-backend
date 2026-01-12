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
        if (Schema::hasColumn('flujos', 'estado_procesamiento')) {
            return;
        }

        Schema::table('flujos', function (Blueprint $table) {
            $table->string('estado_procesamiento', 20)
                ->default('pendiente')
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
