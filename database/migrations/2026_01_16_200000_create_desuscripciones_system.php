<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sistema de desuscripciones para compliance GDPR/legal.
     * 
     * - Agrega estado 'desuscrito' al enum de prospectos
     * - Crea tabla de registro de desuscripciones (auditoría)
     */
    public function up(): void
    {
        // 1. Agregar 'desuscrito' al enum de prospectos
        // Verificamos si el tipo ENUM existe (Laravel puede usar CHECK constraint en su lugar)
        $enumExists = DB::select("
            SELECT 1 FROM pg_type WHERE typname = 'prospectos_estado'
        ");

        if ($enumExists) {
            DB::statement("ALTER TYPE prospectos_estado ADD VALUE IF NOT EXISTS 'desuscrito'");
        }
        // Si no existe el ENUM, el estado se manejará a nivel de aplicación
        // ya que la migración anterior debería haber recreado la columna con los valores necesarios

        // 2. Crear tabla de desuscripciones para auditoría (si no existe)
        if (Schema::hasTable('desuscripciones')) {
            return; // Tabla ya existe, salir temprano
        }

        Schema::create('desuscripciones', function (Blueprint $table) {
            $table->id();
            
            // Referencia al prospecto
            $table->foreignId('prospecto_id')
                ->constrained('prospectos')
                ->onDelete('cascade');
            
            // Canal por el que se desuscribió (email, sms, todos)
            $table->string('canal', 20)->default('todos');
            
            // Motivo opcional (seleccionado por el usuario)
            $table->string('motivo')->nullable();
            
            // Token usado para la desuscripción (para auditoría)
            $table->string('token', 64)->nullable();
            
            // IP y user agent para registro legal
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            // Envío que originó la desuscripción (si aplica)
            $table->foreignId('envio_id')
                ->nullable()
                ->constrained('envios')
                ->onDelete('set null');
            
            // Flujo del que se desuscribió (si aplica)
            $table->foreignId('flujo_id')
                ->nullable()
                ->constrained('flujos')
                ->onDelete('set null');
            
            $table->timestamps();
            
            // Índices
            $table->index('prospecto_id');
            $table->index('canal');
            $table->index('created_at');
        });

        // 3. Agregar columna para preferencias de comunicación en prospectos (si no existe)
        if (!Schema::hasColumn('prospectos', 'preferencias_comunicacion')) {
            Schema::table('prospectos', function (Blueprint $table) {
                $table->json('preferencias_comunicacion')->nullable()->after('metadata');
            });
        }

        if (!Schema::hasColumn('prospectos', 'fecha_desuscripcion')) {
            Schema::table('prospectos', function (Blueprint $table) {
                $table->timestamp('fecha_desuscripcion')->nullable()->after('preferencias_comunicacion');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospectos', function (Blueprint $table) {
            $table->dropColumn(['preferencias_comunicacion', 'fecha_desuscripcion']);
        });

        Schema::dropIfExists('desuscripciones');

        // Nota: No se puede remover un valor de un ENUM en PostgreSQL fácilmente
        // El estado 'desuscrito' quedará en el enum pero no se usará
    }
};
