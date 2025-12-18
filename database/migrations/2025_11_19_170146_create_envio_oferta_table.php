<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envio_oferta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->onDelete('cascade');
            $table->foreignId('oferta_infocom_id')->constrained('ofertas_infocom')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['envio_id', 'oferta_infocom_id']);
            $table->index('envio_id');
            $table->index('oferta_infocom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envio_oferta');
    }
};
