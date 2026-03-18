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
        Schema::connection('tenant')->create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('plan_type', 20)->default('monthly');
            $table->unsignedSmallInteger('billing_cycle_days');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedSmallInteger('included_sessions')->nullable();
            $table->json('included_services_json')->nullable();
            $table->unsignedSmallInteger('remaining_sessions')->nullable();
            $table->string('renewal_mode', 20)->default('manual');
            $table->string('payment_method_token', 191)->nullable();
            $table->string('status', 20)->default('trial');
            $table->dateTime('started_at');
            $table->dateTime('renews_at')->nullable();
            $table->dateTime('last_billed_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('subscriptions');
    }
};
