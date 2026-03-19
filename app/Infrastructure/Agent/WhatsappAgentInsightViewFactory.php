<?php

namespace App\Infrastructure\Agent;

use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;

class WhatsappAgentInsightViewFactory
{
    /**
     * @return array<string, mixed>
     */
    public function summary(AgentInsight $insight): array
    {
        return [
            'id' => $insight->id,
            'type' => $insight->type,
            'recommendation_type' => $insight->recommendation_type,
            'status' => $insight->status,
            'severity' => $insight->severity,
            'priority' => (int) $insight->priority,
            'title' => $insight->title,
            'summary' => $insight->summary,
            'target_type' => $insight->target_type,
            'target_id' => $insight->target_id,
            'target_label' => $insight->target_label,
            'provider' => $insight->provider,
            'slot' => $insight->slot,
            'automation_id' => $insight->automation_id,
            'suggested_action' => $insight->suggested_action,
            'execution_mode' => $insight->execution_mode,
            'first_detected_at' => $insight->first_detected_at?->toIso8601String(),
            'last_detected_at' => $insight->last_detected_at?->toIso8601String(),
            'resolved_at' => $insight->resolved_at?->toIso8601String(),
            'ignored_at' => $insight->ignored_at?->toIso8601String(),
            'executed_at' => $insight->executed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AgentInsight $insight): array
    {
        return array_merge($this->summary($insight), [
            'insight_key' => $insight->insight_key,
            'evidence' => $insight->evidence_json ?? [],
            'action_payload' => $insight->action_payload_json ?? [],
            'execution_result' => $insight->execution_result_json ?? [],
            'agent_run_id' => $insight->agent_run_id,
            'created_at' => $insight->created_at?->toIso8601String(),
            'updated_at' => $insight->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(AgentInsight $insight): array
    {
        return [
            'type' => $insight->type,
            'recommendation_type' => $insight->recommendation_type,
            'status' => $insight->status,
            'severity' => $insight->severity,
            'priority' => (int) $insight->priority,
            'title' => $insight->title,
            'summary' => $insight->summary,
            'target_type' => $insight->target_type,
            'target_id' => $insight->target_id,
            'target_label' => $insight->target_label,
            'provider' => $insight->provider,
            'slot' => $insight->slot,
            'automation_id' => $insight->automation_id,
            'suggested_action' => $insight->suggested_action,
            'execution_mode' => $insight->execution_mode,
            'evidence' => $insight->evidence_json ?? [],
            'action_payload' => $insight->action_payload_json ?? [],
            'execution_result' => $insight->execution_result_json ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runSummary(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'channel' => $run->channel,
            'status' => $run->status,
            'window_started_at' => $run->window_started_at?->toIso8601String(),
            'window_ended_at' => $run->window_ended_at?->toIso8601String(),
            'insights_created' => (int) $run->insights_created,
            'insights_refreshed' => (int) $run->insights_refreshed,
            'insights_resolved' => (int) $run->insights_resolved,
            'insights_ignored' => (int) $run->insights_ignored,
            'safe_actions_executed' => (int) $run->safe_actions_executed,
            'failure_reason' => $run->failure_reason,
            'run_context' => $run->run_context_json ?? [],
            'result' => $run->result_json ?? [],
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
