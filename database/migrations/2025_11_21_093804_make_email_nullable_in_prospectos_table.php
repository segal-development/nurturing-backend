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
        Schema::table('prospectos', function (Blueprint $table) {
            // Eliminar el índice unique del email
            $table->dropUnique(['email']);

            // Hacer el email nullable
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospectos', function (Blueprint $table) {
            // Revertir: hacer email required y agregar unique constraint
            $table->string('email')->nullable(false)->unique()->change();
        });
    }
};
