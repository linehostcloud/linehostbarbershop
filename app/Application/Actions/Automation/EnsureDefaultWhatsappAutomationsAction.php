<?php

namespace App\Application\Actions\Automation;

use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use Illuminate\Support\Collection;

class EnsureDefaultWhatsappAutomationsAction
{
    /**
     * @return Collection<int, Automation>
     */
    public function execute(): Collection
    {
        foreach (WhatsappAutomationType::cases() as $type) {
            $automation = Automation::query()->firstOrNew([
                'channel' => 'whatsapp',
                'trigger_event' => $type->value,
            ]);

            if (! $automation->exists) {
                $automation->fill($this->defaults($type));
                $automation->save();

                continue;
            }

            $dirty = false;

            foreach ($this->defaults($type) as $key => $value) {
                $current = $automation->getAttribute($key);

                if ($current !== null) {
                    if (is_array($current) && $current !== []) {
                        continue;
                    }

                    if (! is_array($current) && $current !== '') {
                        continue;
                    }
                }

                $automation->setAttribute($key, $value);
                $dirty = true;
            }

            if ($dirty) {
                $automation->save();
            }
        }

        return Automation::query()
            ->where('channel', 'whatsapp')
            ->whereIn('trigger_event', WhatsappAutomationType::values())
            ->orderBy('priority')
            ->orderBy('trigger_event')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(WhatsappAutomationType $type): array
    {
        return match ($type) {
            WhatsappAutomationType::AppointmentReminder => [
                'name' => $type->defaultName(),
                'description' => $type->defaultDescription(),
                'trigger_type' => 'scheduled',
                'trigger_event' => $type->value,
                'status' => (string) config('communication.whatsapp.automations.appointment_reminder.default_status', 'inactive'),
                'channel' => 'whatsapp',
                'conditions_json' => [
                    'lead_time_minutes' => (int) config('communication.whatsapp.automations.appointment_reminder.lead_time_minutes', 1440),
                    'selection_tolerance_minutes' => (int) config('communication.whatsapp.automations.selection_tolerance_minutes', 10),
                    'excluded_statuses' => ['canceled', 'no_show', 'completed'],
                ],
                'action_type' => 'whatsapp_message',
                'action_payload_json' => (array) config('communication.whatsapp.automations.appointment_reminder.message', []),
                'delay_minutes' => 0,
                'cooldown_hours' => (int) config('communication.whatsapp.automations.appointment_reminder.cooldown_hours', 24),
                'stop_on_response' => false,
                'priority' => 10,
            ],
            WhatsappAutomationType::InactiveClientReactivation => [
                'name' => $type->defaultName(),
                'description' => $type->defaultDescription(),
                'trigger_type' => 'scheduled',
                'trigger_event' => $type->value,
                'status' => (string) config('communication.whatsapp.automations.inactive_client_reactivation.default_status', 'inactive'),
                'channel' => 'whatsapp',
                'conditions_json' => [
                    'inactivity_days' => (int) config('communication.whatsapp.automations.inactive_client_reactivation.inactivity_days', 45),
                    'minimum_completed_visits' => (int) config('communication.whatsapp.automations.inactive_client_reactivation.minimum_completed_visits', 1),
                    'require_marketing_opt_in' => true,
                    'exclude_with_future_appointments' => true,
                ],
                'action_type' => 'whatsapp_message',
                'action_payload_json' => (array) config('communication.whatsapp.automations.inactive_client_reactivation.message', []),
                'delay_minutes' => 0,
                'cooldown_hours' => (int) config('communication.whatsapp.automations.inactive_client_reactivation.cooldown_hours', 720),
                'stop_on_response' => false,
                'priority' => 20,
            ],
        };
    }
}
