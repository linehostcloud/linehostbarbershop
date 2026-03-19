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
        Schema::connection('tenant')->table('integration_attempts', function (Blueprint $table) {
            $table->string('provider_message_id', 191)->nullable()->after('external_reference');
            $table->string('provider_status', 60)->nullable()->after('provider_message_id');
            $table->string('provider_error_code', 60)->nullable()->after('provider_status');
            $table->string('provider_request_id', 120)->nullable()->after('provider_error_code');
            $table->unsignedSmallInteger('http_status')->nullable()->after('provider_request_id');
            $table->unsignedInteger('latency_ms')->nullable()->after('http_status');
            $table->boolean('retryable')->nullable()->after('latency_ms');
            $table->string('normalized_status', 30)->nullable()->after('retryable');
            $table->string('normalized_error_code', 60)->nullable()->after('normalized_status');
            $table->string('idempotency_key', 191)->nullable()->after('normalized_error_code');
            $table->json('sanitized_payload_json')->nullable()->after('response_payload_json');

            $table->index(['provider_message_id', 'provider']);
            $table->index(['normalized_status', 'provider']);
            $table->index(['normalized_error_code', 'provider']);
            $table->unique('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('integration_attempts', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['provider_message_id', 'provider']);
            $table->dropIndex(['normalized_status', 'provider']);
            $table->dropIndex(['normalized_error_code', 'provider']);
            $table->dropColumn([
                'provider_message_id',
                'provider_status',
                'provider_error_code',
                'provider_request_id',
                'http_status',
                'latency_ms',
                'retryable',
                'normalized_status',
                'normalized_error_code',
                'idempotency_key',
                'sanitized_payload_json',
            ]);
        });
    }
};
