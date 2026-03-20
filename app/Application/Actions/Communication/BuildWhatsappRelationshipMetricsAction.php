<?php

namespace App\Application\Actions\Communication;

use App\Application\Support\WhatsappRelationshipMetricsPeriod;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Automation\Models\Automation;
use App\Domain\Communication\Models\Message;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildWhatsappRelationshipMetricsAction
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{
     *     appointments:array{read:bool,write:bool},
     *     clients:array{read:bool,write:bool}
     * }  $visibility
     * @return array{
     *     period:array{
     *         selected:string,
     *         label:string,
     *         help:string,
     *         timezone:string,
     *         from:string,
     *         to:string,
     *         options:list<array{value:string,label:string}>
     *     },
     *     cards:list<array{
     *         key:string,
     *         label:string,
     *         value:int,
     *         help:string,
     *         tone:string,
     *         source:string
     *     }>,
     *     has_inferred_cards:bool
     * }
     */
    public function execute(
        Tenant $tenant,
        Automation $appointmentAutomation,
        Automation $reactivationAutomation,
        array $filters,
        array $visibility,
    ): array {
        $timezone = $tenant->timezone ?: config('app.timezone', 'UTC');
        $period = WhatsappRelationshipMetricsPeriod::fromInput($filters['period'] ?? null)->window($timezone);
        $cards = [];
        $hasInferredCards = false;
        $relevantFailures = 0;

        if (($visibility['appointments']['read'] ?? false) === true) {
            $appointmentMessages = $this->appointmentMessagesForWindow(
                automationId: $this->automationId($appointmentAutomation),
                fromStorage: $period['from_storage'],
                toStorage: $period['to_storage'],
            );
            $reminderMessages = $appointmentMessages->reject(fn (Message $message): bool => $this->isConfirmationMessage($message))->values();
            $confirmationMessages = $appointmentMessages->filter(fn (Message $message): bool => $this->isConfirmationMessage($message))->values();

            $remindersQueued = $reminderMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->created_at, $period))
                ->count();
            $remindersSent = $reminderMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->sent_at, $period))
                ->count();
            $manualConfirmationsSent = $confirmationMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->sent_at, $period))
                ->count();
            $relevantFailures += $reminderMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->failed_at, $period))
                ->count();
            $relevantFailures += $confirmationMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->failed_at, $period))
                ->count();

            $cards[] = $this->directCard(
                key: 'reminders_queued',
                label: 'Lembretes enfileirados',
                value: $remindersQueued,
                help: sprintf('Mensagens de lembrete colocadas na fila em %s.', $period['label']),
                tone: $remindersQueued > 0 ? 'default' : 'muted',
            );
            $cards[] = $this->directCard(
                key: 'reminders_sent',
                label: 'Lembretes enviados',
                value: $remindersSent,
                help: sprintf('Lembretes que saíram da fila e tiveram envio aceito pelo provider em %s.', $period['label']),
                tone: $remindersSent > 0 ? 'positive' : 'muted',
            );
            $cards[] = $this->directCard(
                key: 'manual_confirmations_sent',
                label: 'Confirmações manuais enviadas',
                value: $manualConfirmationsSent,
                help: sprintf('Pedidos manuais de confirmação despachados pelo provider em %s.', $period['label']),
                tone: $manualConfirmationsSent > 0 ? 'positive' : 'muted',
            );

            if ($remindersSent > 0) {
                $confirmedAfterReminder = $this->confirmedAppointmentsAfterReminder($reminderMessages, $period);
                $cards[] = $this->inferredCard(
                    key: 'reminder_confirmation_conversion',
                    label: 'Lembretes com confirmação registrada',
                    value: $confirmedAfterReminder,
                    help: sprintf('Leitura inferida: agendamentos com lembrete enviado em %s e status atual confirmado.', $period['label']),
                    tone: $confirmedAfterReminder > 0 ? 'positive' : 'default',
                );
                $hasInferredCards = true;
            }
        }

        if (($visibility['clients']['read'] ?? false) === true) {
            $reactivationMessages = $this->reactivationMessagesForWindow(
                automationId: $this->automationId($reactivationAutomation),
                fromStorage: $period['from_storage'],
                toStorage: $period['to_storage'],
            );
            $reactivationsTriggered = $reactivationMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->created_at, $period))
                ->count();
            $relevantFailures += $reactivationMessages
                ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->failed_at, $period))
                ->count();

            $cards[] = $this->directCard(
                key: 'reactivations_triggered',
                label: 'Reativações acionadas',
                value: $reactivationsTriggered,
                help: sprintf('Mensagens de reativação colocadas na fila em %s.', $period['label']),
                tone: $reactivationsTriggered > 0 ? 'default' : 'muted',
            );
            $cards[] = $this->directCard(
                key: 'reactivation_snoozes',
                label: 'Clientes ignorados no período',
                value: $this->reactivationSnoozesInPeriod($tenant, $period),
                help: sprintf('Ações de ignorar reativação por 7 dias registradas pelo gestor em %s.', $period['label']),
                tone: 'muted',
            );

            if ($reactivationsTriggered > 0) {
                $convertedClients = $this->clientsWithNewAppointmentAfterReactivation(
                    reactivationMessages: $reactivationMessages
                        ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->created_at, $period))
                        ->values(),
                    toStorage: $period['to_storage'],
                );
                $cards[] = $this->inferredCard(
                    key: 'reactivation_appointment_conversion',
                    label: 'Reativações com novo agendamento',
                    value: $convertedClients,
                    help: sprintf('Leitura inferida: clientes com reativação acionada em %s e novo agendamento criado depois da mensagem, ainda dentro do período.', $period['label']),
                    tone: $convertedClients > 0 ? 'positive' : 'default',
                );
                $hasInferredCards = true;
            }
        }

        if ($cards !== []) {
            $failureTone = $relevantFailures > 0 ? 'danger' : 'muted';
            array_splice($cards, min(3, count($cards)), 0, [[
                'key' => 'delivery_failures',
                'label' => 'Falhas de envio',
                'value' => $relevantFailures,
                'help' => sprintf('Falhas registradas nos envios mostrados nesta tela em %s.', $period['label']),
                'tone' => $failureTone,
                'source' => 'direct',
            ]]);
        }

        return [
            'period' => [
                'selected' => $period['selected'],
                'label' => $period['label'],
                'help' => $period['help'],
                'timezone' => $period['timezone'],
                'from' => $period['from_local']->format('d/m/Y H:i'),
                'to' => $period['to_local']->format('d/m/Y H:i'),
                'options' => WhatsappRelationshipMetricsPeriod::options(),
            ],
            'cards' => $cards,
            'has_inferred_cards' => $hasInferredCards,
        ];
    }

    /**
     * @return Collection<int, Message>
     */
    private function appointmentMessagesForWindow(
        ?string $automationId,
        CarbonImmutable $fromStorage,
        CarbonImmutable $toStorage,
    ): Collection
    {
        if (! is_string($automationId) || $automationId === '') {
            return collect();
        }

        return Message::query()
            ->select([
                'id',
                'appointment_id',
                'client_id',
                'status',
                'payload_json',
                'created_at',
                'sent_at',
                'failed_at',
            ])
            ->where('automation_id', $automationId)
            ->whereNotNull('appointment_id')
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->where(function ($query) use ($fromStorage, $toStorage): void {
                $query
                    ->whereBetween('created_at', [$fromStorage, $toStorage])
                    ->orWhereBetween('sent_at', [$fromStorage, $toStorage])
                    ->orWhereBetween('failed_at', [$fromStorage, $toStorage]);
            })
            ->get();
    }

    /**
     * @return Collection<int, Message>
     */
    private function reactivationMessagesForWindow(
        ?string $automationId,
        CarbonImmutable $fromStorage,
        CarbonImmutable $toStorage,
    ): Collection
    {
        if (! is_string($automationId) || $automationId === '') {
            return collect();
        }

        return Message::query()
            ->select([
                'id',
                'client_id',
                'status',
                'payload_json',
                'created_at',
                'sent_at',
                'failed_at',
            ])
            ->where('automation_id', $automationId)
            ->whereNotNull('client_id')
            ->whereNull('appointment_id')
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->where(function ($query) use ($fromStorage, $toStorage): void {
                $query
                    ->whereBetween('created_at', [$fromStorage, $toStorage])
                    ->orWhereBetween('sent_at', [$fromStorage, $toStorage])
                    ->orWhereBetween('failed_at', [$fromStorage, $toStorage]);
            })
            ->get();
    }

    private function confirmedAppointmentsAfterReminder(
        Collection $reminderMessages,
        array $period,
    ): int {
        $appointmentIds = $reminderMessages
            ->filter(fn (Message $message): bool => $this->isWithinStorageWindow($message->sent_at, $period))
            ->pluck('appointment_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($appointmentIds === []) {
            return 0;
        }

        return Appointment::query()
            ->whereIn('id', $appointmentIds)
            ->where('confirmation_status', 'confirmed')
            ->count();
    }

    private function clientsWithNewAppointmentAfterReactivation(Collection $reactivationMessages, CarbonImmutable $toStorage): int
    {
        /** @var Collection<string, Message> $latestByClient */
        $latestByClient = $reactivationMessages
            ->filter(fn (Message $message): bool => $message->client_id !== null)
            ->sortByDesc(fn (Message $message): int => $message->created_at?->getTimestamp() ?? 0)
            ->unique('client_id')
            ->keyBy(fn (Message $message): string => (string) $message->client_id);

        if ($latestByClient->isEmpty()) {
            return 0;
        }

        $appointments = Appointment::query()
            ->whereIn('client_id', $latestByClient->keys()->all())
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->where('created_at', '<=', $toStorage)
            ->orderBy('created_at')
            ->get(['id', 'client_id', 'created_at']);

        return $latestByClient
            ->filter(function (Message $message) use ($appointments): bool {
                if ($message->client_id === null || $message->created_at === null) {
                    return false;
                }

                return $appointments
                    ->where('client_id', $message->client_id)
                    ->contains(fn (Appointment $appointment): bool => $appointment->created_at !== null
                        && $appointment->created_at->greaterThan($message->created_at));
            })
            ->count();
    }

    private function reactivationSnoozesInPeriod(Tenant $tenant, array $period): int
    {
        return AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'whatsapp_product.client_reactivation.snoozed')
            ->whereBetween('created_at', [$period['from_storage'], $period['to_storage']])
            ->count();
    }

    private function isConfirmationMessage(Message $message): bool
    {
        return data_get($message->payload_json, 'product.manual_action') === 'appointment_confirmation'
            || data_get($message->payload_json, 'automation.trigger_reason') === 'manual_appointment_confirmation';
    }

    private function isWithinStorageWindow(mixed $value, array $period): bool
    {
        if ($value === null) {
            return false;
        }

        $timestamp = $value instanceof CarbonImmutable
            ? $value
            : CarbonImmutable::instance($value);

        return $timestamp->betweenIncluded($period['from_storage'], $period['to_storage']);
    }

    private function automationId(Automation $automation): ?string
    {
        return is_string($automation->id) && $automation->id !== ''
            ? $automation->id
            : null;
    }

    /**
     * @return array{key:string,label:string,value:int,help:string,tone:string,source:string}
     */
    private function directCard(string $key, string $label, int $value, string $help, string $tone = 'default'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'help' => $help,
            'tone' => $tone,
            'source' => 'direct',
        ];
    }

    /**
     * @return array{key:string,label:string,value:int,help:string,tone:string,source:string}
     */
    private function inferredCard(string $key, string $label, int $value, string $help, string $tone = 'default'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'help' => $help,
            'tone' => $tone,
            'source' => 'inferred',
        ];
    }
}
