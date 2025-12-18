<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds cost tracking fields to flujo_ejecuciones:
     * - costo_estimado: Calculated before execution starts
     * - costo_real: Calculated after execution completes (based on actual envios)
     * - costo_emails: Breakdown of email costs
     * - costo_sms: Breakdown of SMS costs
     */
    public function up(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->decimal('costo_estimado', 12, 2)->nullable()->after('config')
                ->comment('Estimated cost before execution');
            $table->decimal('costo_real', 12, 2)->nullable()->after('costo_estimado')
                ->comment('Actual cost after execution completes');
            $table->decimal('costo_emails', 12, 2)->nullable()->after('costo_real')
                ->comment('Total cost of emails sent');
            $table->decimal('costo_sms', 12, 2)->nullable()->after('costo_emails')
                ->comment('Total cost of SMS sent');
            $table->integer('total_emails_enviados')->nullable()->after('costo_sms')
                ->comment('Count of emails actually sent');
            $table->integer('total_sms_enviados')->nullable()->after('total_emails_enviados')
                ->comment('Count of SMS actually sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flujo_ejecuciones', function (Blueprint $table) {
            $table->dropColumn([
                'costo_estimado',
                'costo_real',
                'costo_emails',
                'costo_sms',
                'total_emails_enviados',
                'total_sms_enviados',
            ]);
        });
    }
};
