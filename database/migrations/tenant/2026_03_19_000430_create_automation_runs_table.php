<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('automations', function (Blueprint $table): void {
            $table->index(['channel', 'trigger_event', 'status'], 'automations_channel_trigger_status_index');
        });

        Schema::connection('tenant')->create('automation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('automation_type', 80);
            $table->string('channel', 20)->default('whatsapp');
            $table->string('status', 20)->default('running');
            $table->dateTime('window_started_at');
            $table->dateTime('window_ended_at');
            $table->unsignedInteger('candidates_found')->default(0);
            $table->unsignedInteger('messages_queued')->default(0);
            $table->unsignedInteger('skipped_total')->default(0);
            $table->unsignedInteger('failed_total')->default(0);
            $table->json('run_context_json')->nullable();
            $table->json('result_json')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'started_at'], 'automation_runs_automation_started_index');
            $table->index(['automation_type', 'status'], 'automation_runs_type_status_index');
            $table->index(['status', 'started_at'], 'automation_runs_status_started_index');
        });

        Schema::connection('tenant')->create('automation_run_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignUlid('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->string('target_type', 40);
            $table->string('target_id', 40);
            $table->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUlid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 20)->default('processing');
            $table->string('trigger_reason', 120)->nullable();
            $table->string('skip_reason', 120)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('cooldown_until')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'target_type', 'target_id'], 'automation_run_targets_run_target_unique');
            $table->index(['automation_id', 'target_type', 'target_id', 'status'], 'automation_run_targets_automation_target_status_index');
            $table->index(['status', 'created_at'], 'automation_run_targets_status_created_index');
            $table->index('message_id', 'automation_run_targets_message_index');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('automation_run_targets');
        Schema::connection('tenant')->dropIfExists('automation_runs');

        Schema::connection('tenant')->table('automations', function (Blueprint $table): void {
            $table->dropIndex('automations_channel_trigger_status_index');
        });
    }
};
