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
        Schema::create('flujo_condiciones', function (Blueprint $table) {
            $table->string('id')->primary(); // conditional-xyz789
            $table->foreignId('flujo_id')->constrained('flujos')->onDelete('cascade');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('condition_type'); // email_opened, link_clicked, custom
            $table->string('condition_label');
            $table->string('yes_label')->default('Sí');
            $table->string('no_label')->default('No');
            $table->timestamps();

            $table->index('flujo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flujo_condiciones');
    }
};
