<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make origen and origen_id nullable in flujos table.
 *
 * Flujos can now be created without an origin (template/empty flows)
 * and prospects assigned later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->string('origen')->nullable()->change();
            $table->string('origen_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->string('origen')->nullable(false)->change();
            $table->string('origen_id')->nullable(false)->change();
        });
    }
};
