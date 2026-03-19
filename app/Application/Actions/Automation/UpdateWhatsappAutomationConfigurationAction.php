<?php

namespace App\Application\Actions\Automation;

use App\Domain\Automation\Models\Automation;

class UpdateWhatsappAutomationConfigurationAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Automation $automation, array $validated): Automation
    {
        $automation->fill([
            'name' => $validated['name'] ?? $automation->name,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $automation->description,
            'status' => $validated['status'] ?? $automation->status,
            'conditions_json' => array_key_exists('conditions', $validated)
                ? array_replace_recursive($automation->conditions_json ?? [], $validated['conditions'])
                : $automation->conditions_json,
            'action_payload_json' => array_key_exists('message', $validated)
                ? array_replace_recursive($automation->action_payload_json ?? [], $validated['message'])
                : $automation->action_payload_json,
            'cooldown_hours' => $validated['cooldown_hours'] ?? $automation->cooldown_hours,
            'priority' => $validated['priority'] ?? $automation->priority,
        ])->save();

        return $automation->refresh();
    }
}
