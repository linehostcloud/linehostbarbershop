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
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->foreignUlid('cash_register_session_id')
                ->nullable()
                ->after('professional_id')
                ->constrained('cash_register_sessions')
                ->nullOnDelete();

            $table->index(['cash_register_session_id', 'occurred_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropIndex(['cash_register_session_id', 'occurred_on']);
            $table->dropConstrainedForeignId('cash_register_session_id');
        });
    }
};
