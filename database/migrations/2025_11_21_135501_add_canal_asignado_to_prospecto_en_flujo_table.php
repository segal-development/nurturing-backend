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
        Schema::table('prospecto_en_flujo', function (Blueprint $table) {
            // Canal asignado para este prospecto (email, sms, ambos)
            $table->enum('canal_asignado', ['email', 'sms'])->default('email')->after('flujo_id')->index();

            // Estado del prospecto en el flujo
            $table->enum('estado', ['pendiente', 'en_proceso', 'completado', 'cancelado'])->default('pendiente')->after('canal_asignado')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospecto_en_flujo', function (Blueprint $table) {
            $table->dropColumn(['canal_asignado', 'estado']);
        });
    }
};
