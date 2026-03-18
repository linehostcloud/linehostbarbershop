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
        Schema::connection('tenant')->create('appointments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUlid('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->foreignUlid('primary_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->ulid('booked_by_user_id')->nullable()->index();
            $table->string('source', 30)->default('dashboard');
            $table->string('status', 20)->default('pending');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('confirmation_status', 20)->default('not_sent');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'starts_at']);
            $table->index(['client_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('appointments');
    }
};
