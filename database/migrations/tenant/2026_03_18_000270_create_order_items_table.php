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
        Schema::connection('tenant')->create('order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('professional_id')->nullable()->constrained('professionals')->nullOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('type', 30)->default('service');
            $table->string('description', 190);
            $table->decimal('quantity', 10, 3)->default(1);
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('total_price_cents');
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('order_items');
    }
};
