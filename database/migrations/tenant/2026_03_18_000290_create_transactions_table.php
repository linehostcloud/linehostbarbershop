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
        Schema::connection('tenant')->create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignUlid('professional_id')->nullable()->constrained('professionals')->nullOnDelete();
            $table->string('source_type', 80)->nullable();
            $table->ulid('source_id')->nullable();
            $table->date('occurred_on');
            $table->string('type', 20);
            $table->string('category', 40);
            $table->string('description', 190);
            $table->bigInteger('amount_cents');
            $table->string('balance_direction', 10);
            $table->boolean('reconciled')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['occurred_on', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('transactions');
    }
};
