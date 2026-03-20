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
            $table->json('nivel_deuda_target')->nullable()->after('lotes_ids')
                ->comment('Target nivel_deuda values for auto-assignment filtering (e.g. ["alta","media"])');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->dropColumn('nivel_deuda_target');
        });
    }
};
