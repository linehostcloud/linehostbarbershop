<?php

namespace App\Application\Actions\Client;

use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Client\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class SnoozeClientReactivationAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
    ) {
    }

    /**
     * @return array{automation:Automation,snoozed_until:CarbonImmutable,days:int}
     */
    public function execute(Client $client): array
    {
        $automation = $this->automation();
        $now = CarbonImmutable::now();

        try {
            $client = $this->discoverCandidates->loadClientForReactivation($client, $now);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'client' => 'Esse cliente não precisa ser ignorado temporariamente agora.',
            ]);
        }

        $candidate = $this->discoverCandidates->inspectInactiveClientReactivation(
            automation: $automation,
            client: $client,
            now: $now,
        );

        if (! $candidate->isEligible()) {
            throw ValidationException::withMessages([
                'client' => $this->manualBlockMessage(
                    skipReason: $candidate->skipReason,
                    snoozedUntilLocal: data_get($candidate->context, 'reactivation.snoozed_until_local'),
                ),
            ]);
        }

        $days = max(1, (int) config('communication.whatsapp.product_flows.client_reactivation_snooze.days', 7));
        $snoozedUntil = $now->addDays($days);

        $client->forceFill([
            'whatsapp_reactivation_snoozed_until' => $snoozedUntil,
        ])->save();

        return [
            'automation' => $automation,
            'snoozed_until' => $snoozedUntil,
            'days' => $days,
        ];
    }

    private function automation(): Automation
    {
        return $this->ensureDefaults->execute()
            ->firstWhere('trigger_event', WhatsappAutomationType::InactiveClientReactivation->value)
            ?? Automation::query()
                ->where('channel', 'whatsapp')
                ->where('trigger_event', WhatsappAutomationType::InactiveClientReactivation->value)
                ->firstOrFail();
    }

    private function manualBlockMessage(?string $skipReason, ?string $snoozedUntilLocal = null): string
    {
        return match ($skipReason) {
            'reactivation_snoozed' => $snoozedUntilLocal !== null && $snoozedUntilLocal !== ''
                ? sprintf('Esse cliente já está ignorado temporariamente até %s.', $snoozedUntilLocal)
                : 'Esse cliente já está ignorado temporariamente para reativação.',
            'no_visit_history', 'insufficient_history' => 'Esse cliente ainda não tem histórico suficiente para reativação.',
            'missing_phone' => 'O cliente ainda não possui telefone válido para WhatsApp.',
            'whatsapp_opt_out' => 'O cliente não autorizou contato por WhatsApp.',
            'marketing_opt_out' => 'O cliente não autorizou comunicações de reativação.',
            'future_appointment_exists' => 'Esse cliente já tem um novo agendamento e não precisa de reativação agora.',
            'not_inactive_enough' => 'Esse cliente ainda não atingiu a janela de inatividade configurada.',
            'cooldown_active' => 'Já existe uma reativação recente para esse cliente. Aguarde a próxima janela.',
            default => 'Esse cliente não precisa ser ignorado temporariamente agora.',
        };
    }
}
