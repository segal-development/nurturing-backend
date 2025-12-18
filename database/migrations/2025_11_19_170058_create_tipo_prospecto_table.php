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
        Schema::create('tipo_prospecto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->text('descripcion')->nullable();
            $table->decimal('monto_min', 15, 2)->nullable();
            $table->decimal('monto_max', 15, 2)->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();

            $table->index(['monto_min', 'monto_max']);
            $table->index('orden');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipo_prospecto');
    }
};
