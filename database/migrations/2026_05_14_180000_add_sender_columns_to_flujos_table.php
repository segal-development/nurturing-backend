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
            $table->string('sender_email')->nullable()
                ->after('canal_envio')
                ->comment('Custom sender email for this flow. Falls back to config default if null.');

            $table->string('sender_name')->nullable()
                ->after('sender_email')
                ->comment('Custom sender name for this flow. Falls back to config default if null.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujos', function (Blueprint $table) {
            $table->dropColumn(['sender_email', 'sender_name']);
        });
    }
};
