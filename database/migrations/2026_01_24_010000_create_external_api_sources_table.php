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
        Schema::create('external_api_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();           // identificador: 'banco_xyz'
            $table->string('display_name');             // 'Clientes Banco XYZ'
            $table->string('endpoint_url');
            $table->string('auth_type')->default('bearer'); // 'bearer', 'api_key', 'basic'
            $table->text('auth_token')->nullable();     // encriptado
            $table->json('headers')->nullable();        // headers adicionales
            $table->json('field_mapping')->nullable();  // mapeo de campos API -> prospecto
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->integer('last_sync_count')->default(0);
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_api_sources');
    }
};
