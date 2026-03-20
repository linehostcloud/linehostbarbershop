<?php

namespace App\Application\Actions\Automation;

use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use Illuminate\Support\Collection;

class EnsureDefaultWhatsappAutomationsAction
{
    public function __construct(
        private readonly BuildWhatsappAutomationDefaultAttributesAction $buildDefaults,
    ) {
    }

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
                $automation->fill($this->buildDefaults->execute($type));
                $automation->save();

                continue;
            }

            $dirty = false;

            foreach ($this->buildDefaults->execute($type) as $key => $value) {
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
}
