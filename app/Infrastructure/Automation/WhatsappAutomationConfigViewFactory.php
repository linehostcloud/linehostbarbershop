<?php

namespace App\Infrastructure\Automation;

use App\Domain\Automation\Models\Automation;

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
}
