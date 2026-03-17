<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add external_message_id and email_provider columns to envios table.
     *
     * external_message_id: Stores the message ID from external providers (e.g., Certificada's IdMensaje)
     * email_provider: Indicates which provider sent the email ('smtp', 'certificada', etc.)
     */
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->string('external_message_id')->nullable()->after('estado');
            $table->string('email_provider')->nullable()->after('external_message_id');
            $table->index('external_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropIndex(['external_message_id']);
            $table->dropColumn(['external_message_id', 'email_provider']);
        });
    }
};
