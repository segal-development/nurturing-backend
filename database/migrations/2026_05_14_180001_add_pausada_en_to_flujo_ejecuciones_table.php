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
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->timestamp('pausada_en')->nullable()
                ->after('estado')
                ->comment('Timestamp when execution was paused. Used for date recalculation on resume.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropColumn('pausada_en');
        });
    }
};
