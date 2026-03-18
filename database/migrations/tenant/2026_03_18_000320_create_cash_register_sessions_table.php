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
        Schema::connection('tenant')->create('cash_register_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('label', 80)->default('caixa-principal');
            $table->ulid('opened_by_user_id')->nullable()->index();
            $table->ulid('closed_by_user_id')->nullable()->index();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('opening_balance_cents')->default(0);
            $table->bigInteger('expected_balance_cents')->default(0);
            $table->unsignedBigInteger('counted_cash_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'opened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cash_register_sessions');
    }
};
