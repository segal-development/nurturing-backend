<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->unique()->after('destinatario');
            $table->unsignedInteger('total_aperturas')->default(0)->after('fecha_clickeado');
            
            $table->index('tracking_token');
        });
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropIndex(['tracking_token']);
            $table->dropColumn(['tracking_token', 'total_aperturas']);
        });
    }
};
