<?php

namespace App\Infrastructure\Automation;

use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;

class WhatsappAutomationConfigViewFactory
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'type' => $automation->trigger_event,
            'name' => $automation->name,
            'description' => $automation->description,
            'status' => $automation->status,
            'channel' => $automation->channel,
            'conditions' => $automation->conditions_json ?? [],
            'message' => $automation->action_payload_json ?? [],
            'cooldown_hours' => (int) $automation->cooldown_hours,
            'priority' => (int) $automation->priority,
            'last_executed_at' => $automation->last_executed_at?->toIso8601String(),
            'updated_at' => $automation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Automation $automation): array
    {
        return array_merge($this->summary($automation), [
            'trigger_type' => $automation->trigger_type,
            'action_type' => $automation->action_type,
            'delay_minutes' => (int) $automation->delay_minutes,
            'stop_on_response' => (bool) $automation->stop_on_response,
            'created_at' => $automation->created_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Automation $automation): array
    {
        return [
            'type' => $automation->trigger_event,
            'name' => $automation->name,
            'description' => $automation->description,
            'status' => $automation->status,
            'channel' => $automation->channel,
            'conditions' => $automation->conditions_json ?? [],
            'message' => $automation->action_payload_json ?? [],
            'cooldown_hours' => (int) $automation->cooldown_hours,
            'priority' => (int) $automation->priority,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function governanceSummary(Automation $automation, array $metrics = [], ?AutomationRun $latestRun = null): array
    {
        return array_merge($this->detail($automation), [
            'metrics' => array_merge([
                'runs_total' => 0,
                'messages_queued_total' => 0,
                'skipped_total' => 0,
                'failed_total' => 0,
                'cooldown_hits_total' => 0,
                'skip_reason_totals' => [],
            ], $metrics),
            'latest_run' => $latestRun !== null ? $this->runSummary($latestRun) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function runSummary(AutomationRun $run): array
    {
        $durationSeconds = null;

        if ($run->started_at !== null && $run->completed_at !== null) {
            $durationSeconds = max(0, $run->completed_at->diffInSeconds($run->started_at));
        }

        return [
            'id' => $run->id,
            'automation_id' => $run->automation_id,
            'automation_type' => $run->automation_type,
            'status' => $run->status,
            'candidates_found' => (int) $run->candidates_found,
            'messages_queued' => (int) $run->messages_queued,
            'skipped_total' => (int) $run->skipped_total,
            'failed_total' => (int) $run->failed_total,
            'failure_reason' => $run->failure_reason,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'window_started_at' => $run->window_started_at?->toIso8601String(),
            'window_ended_at' => $run->window_ended_at?->toIso8601String(),
            'duration_seconds' => $durationSeconds,
            'result' => $run->result_json ?? [],
        ];
    }
}
