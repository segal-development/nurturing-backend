<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega referencias a plantillas para permitir usar templates guardados
     * en lugar de solo texto plano en las etapas del flujo.
     */
    public function up(): void
    {
        Schema::table('flujo_etapas', function (Blueprint $table) {
            // ID de plantilla SMS (o plantilla principal si tipo_mensaje es 'sms' o 'email')
            $table->unsignedBigInteger('plantilla_id')->nullable()->after('plantilla_mensaje');
            
            // ID de plantilla Email adicional (solo cuando tipo_mensaje es 'ambos')
            $table->unsignedBigInteger('plantilla_id_email')->nullable()->after('plantilla_id');
            
            // Tipo de contenido: 'reference' (usa plantilla) o 'inline' (usa plantilla_mensaje)
            $table->string('plantilla_type', 20)->default('inline')->after('plantilla_id_email');

            // Foreign keys
            $table->foreign('plantilla_id')
                ->references('id')
                ->on('plantillas')
                ->onDelete('set null');
            
            $table->foreign('plantilla_id_email')
                ->references('id')
                ->on('plantillas')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_etapas', function (Blueprint $table) {
            $table->dropForeign(['plantilla_id']);
            $table->dropForeign(['plantilla_id_email']);
            $table->dropColumn(['plantilla_id', 'plantilla_id_email', 'plantilla_type']);
        });
    }
};
