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
        Schema::connection('tenant')->create('event_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 40)->nullable();
            $table->string('event_name', 120);
            $table->string('trigger_source', 30);
            $table->string('status', 20)->default('recorded');
            $table->string('correlation_id', 36)->nullable();
            $table->string('causation_id', 36)->nullable();
            $table->json('payload_json');
            $table->json('context_json')->nullable();
            $table->json('result_json')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['event_name', 'status']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['trigger_source', 'occurred_at']);
            $table->index('correlation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('event_logs');
    }
};
