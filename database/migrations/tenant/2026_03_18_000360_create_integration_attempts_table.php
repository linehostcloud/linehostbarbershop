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
        Schema::connection('tenant')->create('integration_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignUlid('event_log_id')->nullable()->constrained('event_logs')->nullOnDelete();
            $table->foreignUlid('outbox_event_id')->nullable()->constrained('outbox_events')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('provider', 50)->nullable();
            $table->string('operation', 40);
            $table->string('direction', 20);
            $table->string('status', 20)->default('pending');
            $table->string('external_reference', 191)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->json('request_payload_json')->nullable();
            $table->json('response_payload_json')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index(['provider', 'status']);
            $table->index(['direction', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('integration_attempts');
    }
};
