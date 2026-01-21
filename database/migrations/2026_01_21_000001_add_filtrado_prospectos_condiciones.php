<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para soportar filtrado de prospectos por condición.
 * 
 * Permite que cada nodo condicional evalúe a cada prospecto individualmente
 * y los dirija a la rama correspondiente (Sí/No).
 * 
 * Ejemplo:
 * - 100 prospectos reciben email
 * - Condición: ¿Abrió email?
 * - 20 abrieron → van a rama Sí (prospectos_rama_si)
 * - 80 no abrieron → van a rama No (prospectos_rama_no)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar tiempo de evaluación a flujo_condiciones
        Schema::table('flujo_condiciones', function (Blueprint $table) {
            $table->integer('tiempo_evaluacion')->default(1)->after('check_value');
            $table->string('tiempo_evaluacion_unidad', 20)->default('days')->after('tiempo_evaluacion');
        });

        // 2. Agregar campos de resultado por prospecto a flujo_ejecucion_condiciones
        Schema::table('flujo_ejecucion_condiciones', function (Blueprint $table) {
            $table->json('prospectos_rama_si')->nullable()->after('resultado');
            $table->json('prospectos_rama_no')->nullable()->after('prospectos_rama_si');
            $table->integer('total_evaluados')->default(0)->after('prospectos_rama_no');
            $table->integer('total_rama_si')->default(0)->after('total_evaluados');
            $table->integer('total_rama_no')->default(0)->after('total_rama_si');
        });

        // 3. Agregar prospectos_ids a flujo_ejecucion_etapas
        // Esto permite que cada etapa sepa qué prospectos debe procesar
        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->json('prospectos_ids')->nullable()->after('node_id');
        });
    }

    public function down(): void
    {
        Schema::table('flujo_condiciones', function (Blueprint $table) {
            $table->dropColumn(['tiempo_evaluacion', 'tiempo_evaluacion_unidad']);
        });

        Schema::table('flujo_ejecucion_condiciones', function (Blueprint $table) {
            $table->dropColumn([
                'prospectos_rama_si',
                'prospectos_rama_no',
                'total_evaluados',
                'total_rama_si',
                'total_rama_no',
            ]);
        });

        Schema::table('flujo_ejecucion_etapas', function (Blueprint $table) {
            $table->dropColumn('prospectos_ids');
        });
    }
};
