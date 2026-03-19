<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('agent_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('status', 20)->default('running');
            $table->dateTime('window_started_at');
            $table->dateTime('window_ended_at');
            $table->unsignedInteger('insights_created')->default(0);
            $table->unsignedInteger('insights_refreshed')->default(0);
            $table->unsignedInteger('insights_resolved')->default(0);
            $table->unsignedInteger('insights_ignored')->default(0);
            $table->unsignedInteger('safe_actions_executed')->default(0);
            $table->json('run_context_json')->nullable();
            $table->json('result_json')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status'], 'agent_runs_channel_status_index');
            $table->index(['status', 'started_at'], 'agent_runs_status_started_index');
        });

        Schema::connection('tenant')->create('agent_insights', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('insight_key', 191);
            $table->string('type', 80);
            $table->string('recommendation_type', 80);
            $table->string('status', 20)->default('active');
            $table->string('severity', 20)->default('medium');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('title', 160);
            $table->text('summary');
            $table->string('target_type', 40)->nullable();
            $table->string('target_id', 40)->nullable();
            $table->string('target_label', 160)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('slot', 20)->nullable();
            $table->foreignUlid('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->json('evidence_json')->nullable();
            $table->string('suggested_action', 80)->nullable();
            $table->json('action_payload_json')->nullable();
            $table->string('execution_mode', 40)->default('recommend_only');
            $table->json('execution_result_json')->nullable();
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('ignored_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status', 'severity'], 'agent_insights_channel_status_severity_index');
            $table->index(['insight_key', 'status'], 'agent_insights_key_status_index');
            $table->index(['type', 'status'], 'agent_insights_type_status_index');
            $table->index(['provider', 'slot'], 'agent_insights_provider_slot_index');
            $table->index(['automation_id', 'status'], 'agent_insights_automation_status_index');
            $table->index(['last_detected_at', 'status'], 'agent_insights_last_detected_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('agent_insights');
        Schema::connection('tenant')->dropIfExists('agent_runs');
    }
};
