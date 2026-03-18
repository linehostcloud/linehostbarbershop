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
        Schema::connection('tenant')->create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('payable_type', 80);
            $table->ulid('payable_id');
            $table->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('gateway', 50)->nullable();
            $table->string('external_reference', 191)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('BRL');
            $table->unsignedTinyInteger('installment_count')->default(1);
            $table->string('status', 30)->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('payments');
    }
};
