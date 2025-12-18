<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de evaluación a flujo_condiciones.
 * 
 * Estos campos son CRÍTICOS para que VerificarCondicionJob pueda evaluar
 * las condiciones correctamente usando datos de AthenaCampaign.
 * 
 * check_param: Métrica a verificar (Views, Clicks, Bounces, Unsubscribes)
 * check_operator: Operador de comparación (>, >=, ==, !=, <, <=)
 * check_value: Valor esperado para la comparación
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flujo_condiciones', function (Blueprint $table) {
            // Métrica a verificar (del response de AthenaCampaign)
            $table->string('check_param')->default('Views')->after('no_label');
            
            // Operador de comparación
            $table->string('check_operator')->default('>')->after('check_param');
            
            // Valor esperado (string para soportar múltiples valores con 'in')
            $table->string('check_value')->default('0')->after('check_operator');
        });
    }

    public function down(): void
    {
        Schema::table('flujo_condiciones', function (Blueprint $table) {
            $table->dropColumn(['check_param', 'check_operator', 'check_value']);
        });
    }
};
