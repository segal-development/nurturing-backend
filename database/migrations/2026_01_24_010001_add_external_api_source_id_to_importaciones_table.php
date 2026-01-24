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
        Schema::table('importaciones', function (Blueprint $table) {
            $table->foreignId('external_api_source_id')
                ->nullable()
                ->after('metadata')
                ->constrained('external_api_sources')
                ->onDelete('set null');
            
            $table->index('external_api_source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('importaciones', function (Blueprint $table) {
            $table->dropForeign(['external_api_source_id']);
            $table->dropColumn('external_api_source_id');
        });
    }
};
