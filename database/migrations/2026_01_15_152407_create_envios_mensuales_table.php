<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de agregación mensual de envíos.
 *
 * En lugar de hacer COUNT(*) sobre millones de registros en la tabla `envios`,
 * pre-calculamos los totales mensuales para reportes rápidos.
 *
 * Beneficios:
 * - Queries de reportes: O(meses) en vez de O(envíos)
 * - Permite archivar/eliminar envíos viejos sin perder estadísticas
 * - Dashboard instantáneo incluso con millones de envíos históricos
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios_mensuales', function (Blueprint $table) {
            $table->id();

            // Período
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');

            // Opcional: desglose por flujo (null = totales globales)
            $table->foreignId('flujo_id')->nullable()->constrained('flujos')->onDelete('cascade');

            // Opcional: desglose por origen
            $table->string('origen')->nullable();

            // Contadores de envíos
            $table->unsignedBigInteger('total_envios')->default(0);
            $table->unsignedBigInteger('total_emails')->default(0);
            $table->unsignedBigInteger('total_sms')->default(0);

            // Contadores por estado
            $table->unsignedBigInteger('enviados_exitosos')->default(0);
            $table->unsignedBigInteger('enviados_fallidos')->default(0);
            $table->unsignedBigInteger('emails_abiertos')->default(0);
            $table->unsignedBigInteger('emails_clickeados')->default(0);

            // Costos (para reportes financieros)
            $table->decimal('costo_total_emails', 12, 2)->default(0);
            $table->decimal('costo_total_sms', 12, 2)->default(0);
            $table->decimal('costo_total', 12, 2)->default(0);

            // Metadata para auditoría
            $table->timestamp('agregado_en')->nullable();
            $table->timestamps();

            // Índices para queries rápidas
            $table->unique(['anio', 'mes', 'flujo_id', 'origen'], 'envios_mensuales_unique');
            $table->index(['anio', 'mes']);
            $table->index('flujo_id');
            $table->index('origen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_mensuales');
    }
};
