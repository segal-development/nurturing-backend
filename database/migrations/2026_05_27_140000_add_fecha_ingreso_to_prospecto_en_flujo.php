<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `fecha_ingreso` a prospecto_en_flujo: la fecha de ingreso REAL del cliente (la que usa
 * SYSGAL), guardada al asignar al flujo de onboarding Clientes por Fecha Ingreso. El embudo se ancla
 * en ELLA (no en fecha_inicio, que es cuándo entró al flujo) para que "Entraron" cuadre con SYSGAL
 * por día de ingreso, inmune a catch-ups o re-syncs. Nullable: solo onboarding la setea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecto_en_flujo', 'fecha_ingreso')) {
            Schema::table('prospecto_en_flujo', function (Blueprint $table) {
                $table->date('fecha_ingreso')->nullable()->after('fecha_inicio');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospecto_en_flujo', 'fecha_ingreso')) {
            Schema::table('prospecto_en_flujo', function (Blueprint $table) {
                $table->dropColumn('fecha_ingreso');
            });
        }
    }
};
