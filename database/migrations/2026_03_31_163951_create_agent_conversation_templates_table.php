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
        Schema::create('agent_conversation_templates', function (Blueprint $table) {
            $table->string('conversation_id', 36)->primary();
            $table->json('current_template')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('agent_conversations')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_templates');
    }
};
