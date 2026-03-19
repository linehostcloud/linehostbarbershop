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
        Schema::connection('tenant')->create('outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_log_id')->constrained('event_logs')->cascadeOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('event_name', 120);
            $table->string('topic', 120);
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->unsignedInteger('retry_backoff_seconds')->default(60);
            $table->json('payload_json');
            $table->json('context_json')->nullable();
            $table->dateTime('available_at');
            $table->dateTime('reserved_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['event_name', 'status']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('outbox_events');
    }
};
