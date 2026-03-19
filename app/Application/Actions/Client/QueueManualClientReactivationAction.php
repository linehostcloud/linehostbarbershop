<?php

namespace App\Application\Actions\Client;

use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Automation\QueueManualWhatsappAutomationMessageAction;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class QueueManualClientReactivationAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverCandidates,
        private readonly QueueManualWhatsappAutomationMessageAction $queueManualMessage,
    ) {
    }

    /**
     * @return array{automation:Automation,message:Message,run_id:string}
     */
    public function execute(Client $client, ?string $actorUserId = null): array
    {
        $automation = $this->automation();
        $now = CarbonImmutable::now();
        $client = $this->discoverCandidates->loadClientForReactivation($client, $now);
        $candidate = $this->discoverCandidates->inspectInactiveClientReactivation(
            automation: $automation,
            client: $client,
            now: $now,
        );

        if (! $candidate->isEligible()) {
            throw ValidationException::withMessages([
                'client' => $this->manualBlockMessage($candidate->skipReason),
            ]);
        }

        $result = $this->queueManualMessage->execute(
            automation: $automation,
            targetType: 'client',
            targetId: (string) $client->id,
            triggerReason: 'manual_client_reactivation',
            client: $client,
            appointment: null,
            context: $candidate->context,
            runContext: [
                'actor_user_id' => $actorUserId,
                'client_id' => $client->id,
            ],
            messageMetadata: [
                'product' => [
                    'surface' => 'manager_relationship_panel',
                    'manual_action' => 'client_reactivation',
                    'actor_user_id' => $actorUserId,
                ],
            ],
        );

        if (! $result['queued'] || ! $result['message'] instanceof Message) {
            throw ValidationException::withMessages([
                'client' => $result['failure_reason'] !== ''
                    ? $result['failure_reason']
                    : 'Não foi possível acionar a reativação agora.',
            ]);
        }

        return [
            'automation' => $automation,
            'message' => $result['message'],
            'run_id' => $result['run']->id,
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

    private function manualBlockMessage(?string $skipReason): string
    {
        return match ($skipReason) {
            'no_visit_history' => 'Esse cliente ainda não tem histórico suficiente para reativação.',
            'missing_phone' => 'O cliente ainda não possui telefone válido para WhatsApp.',
            'whatsapp_opt_out' => 'O cliente não autorizou contato por WhatsApp.',
            'marketing_opt_out' => 'O cliente não autorizou comunicações de reativação.',
            'insufficient_history' => 'Esse cliente ainda não tem histórico suficiente para reativação.',
            'future_appointment_exists' => 'Esse cliente já tem um novo agendamento e não precisa de reativação agora.',
            'not_inactive_enough' => 'Esse cliente ainda não atingiu a janela de inatividade configurada.',
            'cooldown_active' => 'Já existe uma reativação recente para esse cliente. Aguarde a próxima janela.',
            default => 'Esse cliente não está elegível para reativação manual agora.',
        };
    }
}
