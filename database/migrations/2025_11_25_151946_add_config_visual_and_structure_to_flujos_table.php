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
            $table->json('config_visual')->nullable()->after('metadata');
            $table->json('config_structure')->nullable()->after('config_visual');
            $table->string('origen_id')->nullable()->after('tipo_prospecto_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->dropColumn(['config_visual', 'config_structure', 'origen_id']);
        });
    }
};
