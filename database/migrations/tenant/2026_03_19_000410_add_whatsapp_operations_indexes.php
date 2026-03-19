<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('messages', function (Blueprint $table) {
            $table->index(['channel', 'status', 'updated_at'], 'messages_channel_status_updated_at_index');
            $table->index(['channel', 'provider', 'updated_at'], 'messages_channel_provider_updated_at_index');
        });

        Schema::connection('tenant')->table('event_logs', function (Blueprint $table) {
            $table->index(['event_name', 'occurred_at'], 'event_logs_event_name_occurred_at_index');
        });

        Schema::connection('tenant')->table('outbox_events', function (Blueprint $table) {
            $table->index(['event_name', 'status', 'updated_at'], 'outbox_events_event_status_updated_at_index');
            $table->index(['status', 'last_reclaimed_at'], 'outbox_events_status_last_reclaimed_at_index');
        });

        Schema::connection('tenant')->table('integration_attempts', function (Blueprint $table) {
            $table->index(['channel', 'operation', 'created_at'], 'integration_attempts_channel_operation_created_at_index');
            $table->index(['channel', 'provider', 'created_at'], 'integration_attempts_channel_provider_created_at_index');
            $table->index(['channel', 'status', 'created_at'], 'integration_attempts_channel_status_created_at_index');
            $table->index(['channel', 'normalized_error_code', 'created_at'], 'integration_attempts_channel_error_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('integration_attempts', function (Blueprint $table) {
            $table->dropIndex('integration_attempts_channel_operation_created_at_index');
            $table->dropIndex('integration_attempts_channel_provider_created_at_index');
            $table->dropIndex('integration_attempts_channel_status_created_at_index');
            $table->dropIndex('integration_attempts_channel_error_created_at_index');
        });

        Schema::connection('tenant')->table('outbox_events', function (Blueprint $table) {
            $table->dropIndex('outbox_events_event_status_updated_at_index');
            $table->dropIndex('outbox_events_status_last_reclaimed_at_index');
        });

        Schema::connection('tenant')->table('event_logs', function (Blueprint $table) {
            $table->dropIndex('event_logs_event_name_occurred_at_index');
        });

        Schema::connection('tenant')->table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_channel_status_updated_at_index');
            $table->dropIndex('messages_channel_provider_updated_at_index');
        });
    }
};
