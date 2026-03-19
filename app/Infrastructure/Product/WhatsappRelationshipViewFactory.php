<?php

namespace App\Infrastructure\Product;

use App\Application\DTOs\WhatsappAutomationCandidate;
use App\Domain\Appointment\Models\Appointment;
use App\Domain\Automation\Models\Automation;
use App\Domain\Client\Models\Client;
use App\Domain\Communication\Models\Message;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;

class WhatsappRelationshipViewFactory
{
    public function appointmentItem(
        Appointment $appointment,
        WhatsappAutomationCandidate $automaticCandidate,
        WhatsappAutomationCandidate $manualCandidate,
        Automation $automation,
        ?Message $latestReminderMessage,
        ?Message $latestConfirmationMessage,
        array $confirmationState,
        CarbonImmutable $now,
        Tenant $tenant,
        bool $canSendManualReminder,
        bool $canSendManualConfirmation,
    ): array {
        $timezone = $tenant->timezone ?: config('app.timezone', 'UTC');
        $startsAt = $appointment->starts_at !== null
            ? CarbonImmutable::instance($appointment->starts_at)->setTimezone($timezone)
            : null;
        $reminderStatus = $this->appointmentReminderStatus($appointment, $automaticCandidate, $automation, $latestReminderMessage);
        $confirmationStatus = $this->appointmentConfirmationStatus(
            status: (string) $appointment->confirmation_status,
            latestConfirmationMessage: $latestConfirmationMessage,
            latestReminderMessage: $latestReminderMessage,
        );
        $manualReminder = $this->manualReminderActionState($manualCandidate, $canSendManualReminder);
        $manualConfirmation = $this->manualConfirmationActionState($confirmationState, $canSendManualConfirmation);

        return [
            'id' => $appointment->id,
            'client_name' => $appointment->client?->full_name ?? 'Cliente não informado',
            'client_phone' => $appointment->client?->phone_e164,
            'professional_name' => $appointment->professional?->full_name,
            'service_name' => $appointment->primaryService?->name,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'starts_at_local' => $startsAt?->format('d/m/Y H:i'),
            'starts_in_minutes' => $startsAt !== null ? $now->diffInMinutes($startsAt, false) : null,
            'appointment_status' => $appointment->status,
            'automation_enabled' => $automation->isActive(),
            'whatsapp_eligible' => ! in_array($manualCandidate->skipReason, ['missing_client', 'missing_phone', 'whatsapp_opt_out'], true),
            'reminder' => [
                'label' => $reminderStatus['label'],
                'tone' => $reminderStatus['tone'],
                'help' => $reminderStatus['help'],
            ],
            'confirmation' => [
                'label' => $confirmationStatus['label'],
                'tone' => $confirmationStatus['tone'],
                'help' => $confirmationStatus['help'],
                'raw_status' => (string) $appointment->confirmation_status,
                'latest_message' => $this->messageSummary($latestConfirmationMessage, $tenant, 'Nenhuma confirmação enviada ainda.'),
            ],
            'latest_message' => $this->messageSummary($latestReminderMessage, $tenant, 'Nenhum lembrete relacionado ainda.'),
            'manual_actions' => [
                'reminder' => $manualReminder,
                'confirmation' => $manualConfirmation,
            ],
        ];
    }

    public function reactivationClientItem(
        WhatsappAutomationCandidate $candidate,
        Automation $automation,
        ?Message $latestMessage,
        Tenant $tenant,
        bool $canTriggerManual,
    ): array {
        $client = $candidate->client;
        $reactivation = (array) data_get($candidate->context, 'reactivation', []);

        return [
            'id' => $client?->id,
            'client_name' => $client?->full_name ?? 'Cliente não informado',
            'client_phone' => $client?->phone_e164,
            'automation_enabled' => $automation->isActive(),
            'eligible' => $candidate->isEligible(),
            'status' => [
                'label' => $this->clientReactivationLabel($candidate),
                'tone' => $this->clientReactivationTone($candidate),
                'help' => $this->clientReactivationHelp($candidate),
            ],
            'last_visit_at' => data_get($reactivation, 'last_visit_at'),
            'last_visit_at_local' => data_get($reactivation, 'last_visit_at_local'),
            'inactive_days' => data_get($reactivation, 'inactive_days'),
            'completed_visits' => data_get($reactivation, 'completed_visits'),
            'snoozed_until' => data_get($reactivation, 'snoozed_until'),
            'snoozed_until_local' => data_get($reactivation, 'snoozed_until_local'),
            'retention_status' => $client?->retention_status,
            'latest_message' => $this->messageSummary($latestMessage, $tenant, 'Nenhuma reativação enviada ainda.'),
            'manual_actions' => [
                'can_trigger_reactivation' => $canTriggerManual && $candidate->isEligible(),
                'can_snooze_reactivation' => $canTriggerManual && $candidate->isEligible(),
            ],
        ];
    }

    public function dashboardSummary(
        CarbonImmutable $selectedDate,
        Automation $appointmentAutomation,
        array $appointmentItems,
        int $remindersSentOnDate,
        Automation $reactivationAutomation,
        array $reactivationItems,
        bool $canViewAppointments,
        bool $canViewReactivation,
    ): array {
        $cards = [];

        if ($canViewAppointments) {
            $cards[] = [
                'label' => 'Agendamentos no período',
                'value' => count($appointmentItems),
                'help' => sprintf('Leitura da agenda a partir de %s, considerando os próximos 3 dias.', $selectedDate->format('d/m/Y')),
                'tone' => 'default',
            ];
            $cards[] = [
                'label' => 'Lembretes enviados na data-base',
                'value' => $remindersSentOnDate,
                'help' => sprintf('Conta só os lembretes que realmente saíram do estado enfileirado em %s.', $selectedDate->format('d/m/Y')),
                'tone' => $remindersSentOnDate > 0 ? 'positive' : 'default',
            ];
            $cards[] = [
                'label' => 'Automação de lembrete',
                'value' => $appointmentAutomation->isActive() ? 'Ativa' : 'Inativa',
                'help' => 'Estado atual do lembrete automático da agenda.',
                'tone' => $appointmentAutomation->isActive() ? 'positive' : 'warning',
            ];
        }

        if ($canViewReactivation) {
            $eligibleCount = count(array_filter(
                $reactivationItems,
                static fn (array $item): bool => (bool) ($item['eligible'] ?? false),
            ));

            $cards[] = [
                'label' => 'Clientes elegíveis nesta leitura',
                'value' => $eligibleCount,
                'help' => 'Clientes que já podem receber reativação com a regra atual.',
                'tone' => $eligibleCount > 0 ? 'default' : 'muted',
            ];
            $cards[] = [
                'label' => 'Clientes avaliados nesta leitura',
                'value' => count($reactivationItems),
                'help' => 'Recorte atual usado para a lista de reativação.',
                'tone' => 'default',
            ];
            $cards[] = [
                'label' => 'Automação de reativação',
                'value' => $reactivationAutomation->isActive() ? 'Ativa' : 'Inativa',
                'help' => 'Estado atual da automação de retorno de clientes.',
                'tone' => $reactivationAutomation->isActive() ? 'positive' : 'warning',
            ];
        }

        return $cards;
    }

    /**
     * @return array{label:string,tone:string,help:string}
     */
    private function appointmentReminderStatus(
        Appointment $appointment,
        WhatsappAutomationCandidate $automaticCandidate,
        Automation $automation,
        ?Message $latestMessage,
    ): array {
        if ($latestMessage instanceof Message) {
            return match ($latestMessage->status) {
                'queued' => [
                    'label' => 'Lembrete enfileirado',
                    'tone' => 'muted',
                    'help' => 'O lembrete já entrou na fila e aguarda processamento.',
                ],
                'dispatched', 'sent' => [
                    'label' => 'Lembrete enviado',
                    'tone' => 'positive',
                    'help' => 'O provider já aceitou o envio do lembrete.',
                ],
                'delivered', 'read' => [
                    'label' => 'Lembrete entregue',
                    'tone' => 'positive',
                    'help' => 'O cliente já recebeu a mensagem de lembrete.',
                ],
                'failed' => [
                    'label' => 'Falha no lembrete',
                    'tone' => 'danger',
                    'help' => 'A última tentativa de envio falhou.',
                ],
                'duplicate_prevented' => [
                    'label' => 'Envio evitado',
                    'tone' => 'muted',
                    'help' => 'O sistema evitou duplicidade de lembrete para este agendamento.',
                ],
                default => [
                    'label' => 'Lembrete em acompanhamento',
                    'tone' => 'warning',
                    'help' => 'Existe atividade recente de lembrete para este agendamento.',
                ],
            };
        }

        if (! $automation->isActive()) {
            return [
                'label' => 'Automação desativada',
                'tone' => 'warning',
                'help' => 'O lembrete automático está desligado para este tenant.',
            ];
        }

        return match ($automaticCandidate->skipReason) {
            null => [
                'label' => 'Lembrete pendente',
                'tone' => 'default',
                'help' => 'O agendamento já está elegível para receber lembrete.',
            ],
            'outside_reminder_window' => [
                'label' => 'Fora da janela do lembrete',
                'tone' => 'muted',
                'help' => 'O horário ainda não chegou na janela configurada para envio.',
            ],
            'cooldown_active' => [
                'label' => 'Aguardar nova janela',
                'tone' => 'muted',
                'help' => 'Já existe um lembrete recente para este agendamento.',
            ],
            'appointment_not_eligible' => [
                'label' => 'Agendamento não elegível',
                'tone' => 'muted',
                'help' => 'Agendamentos cancelados, concluídos ou expirados não recebem lembrete.',
            ],
            'missing_phone', 'whatsapp_opt_out', 'missing_client' => [
                'label' => 'Cliente sem WhatsApp elegível',
                'tone' => 'danger',
                'help' => 'Falta telefone válido ou autorização para contato por WhatsApp.',
            ],
            'reminder_already_sent' => [
                'label' => 'Lembrete já enviado',
                'tone' => 'positive',
                'help' => 'Este agendamento já teve um lembrete enviado anteriormente.',
            ],
            default => [
                'label' => 'Acompanhar manualmente',
                'tone' => 'warning',
                'help' => 'Esse agendamento precisa de revisão antes do próximo envio.',
            ],
        };
    }

    /**
     * @return array{label:string,tone:string,help:string}
     */
    private function appointmentConfirmationStatus(
        string $status,
        ?Message $latestConfirmationMessage,
        ?Message $latestReminderMessage,
    ): array
    {
        if ($latestConfirmationMessage instanceof Message) {
            return match ($latestConfirmationMessage->status) {
                'queued' => [
                    'label' => 'Confirmação em preparo',
                    'tone' => 'muted',
                    'help' => 'A solicitação entrou na fila e aguarda processamento.',
                ],
                'dispatched', 'sent' => [
                    'label' => 'Confirmação enviada',
                    'tone' => 'positive',
                    'help' => 'O provider já aceitou o envio da confirmação.',
                ],
                'delivered', 'read' => [
                    'label' => 'Aguardando resposta do cliente',
                    'tone' => 'positive',
                    'help' => 'O cliente já recebeu a solicitação de confirmação.',
                ],
                'failed' => [
                    'label' => 'Falha na confirmação',
                    'tone' => 'danger',
                    'help' => 'A última tentativa de confirmação falhou.',
                ],
                'duplicate_prevented' => [
                    'label' => 'Confirmação não reenviada',
                    'tone' => 'muted',
                    'help' => 'O sistema evitou uma duplicidade recente de confirmação.',
                ],
                default => [
                    'label' => 'Confirmação em acompanhamento',
                    'tone' => 'warning',
                    'help' => 'Existe atividade recente de confirmação para este agendamento.',
                ],
            };
        }

        if ($latestReminderMessage instanceof Message) {
            return match ($latestReminderMessage->status) {
                'queued' => [
                    'label' => 'Solicitação em preparo',
                    'tone' => 'muted',
                    'help' => 'Existe um lembrete recente sendo preparado para esse agendamento.',
                ],
                'dispatched', 'sent', 'delivered', 'read' => [
                    'label' => 'Aguardando resposta do cliente',
                    'tone' => 'default',
                    'help' => 'Há uma solicitação recente aguardando retorno do cliente.',
                ],
                'failed' => [
                    'label' => 'Último envio falhou',
                    'tone' => 'danger',
                    'help' => 'A última tentativa relacionada à confirmação falhou.',
                ],
                default => [
                    'label' => 'Acompanhar confirmação',
                    'tone' => 'warning',
                    'help' => 'Existe atividade recente de contato para este agendamento.',
                ],
            };
        }

        return match ($status) {
            'confirmed' => [
                'label' => 'Confirmado',
                'tone' => 'positive',
                'help' => 'O cliente já confirmou presença para este agendamento.',
            ],
            'declined' => [
                'label' => 'Cliente informou ausência',
                'tone' => 'warning',
                'help' => 'O cliente indicou que não poderá comparecer.',
            ],
            'awaiting_customer', 'manual_confirmation_requested' => [
                'label' => 'Aguardando resposta do cliente',
                'tone' => 'default',
                'help' => 'Já existe uma solicitação de confirmação aguardando retorno.',
            ],
            'confirm_queued' => [
                'label' => 'Confirmação em preparo',
                'tone' => 'muted',
                'help' => 'A solicitação já entrou na fila e ainda não foi despachada.',
            ],
            'confirm_failed' => [
                'label' => 'Falha na confirmação',
                'tone' => 'danger',
                'help' => 'A última tentativa conhecida de confirmação falhou.',
            ],
            default => [
                'label' => 'Nenhuma solicitação enviada',
                'tone' => 'muted',
                'help' => 'Ainda não existe confirmação manual registrada para este agendamento.',
            ],
        };
    }

    private function clientReactivationLabel(WhatsappAutomationCandidate $candidate): string
    {
        return match ($candidate->skipReason) {
            null => 'Elegível para reativação',
            'reactivation_snoozed' => 'Ignorado temporariamente',
            'cooldown_active' => 'Reativação recente',
            'future_appointment_exists' => 'Já tem novo agendamento',
            'missing_phone', 'whatsapp_opt_out', 'marketing_opt_out' => 'Sem WhatsApp elegível',
            'not_inactive_enough' => 'Ainda não está inativo o suficiente',
            'insufficient_history', 'no_visit_history' => 'Histórico insuficiente',
            default => 'Revisar cliente',
        };
    }

    private function clientReactivationTone(WhatsappAutomationCandidate $candidate): string
    {
        return match ($candidate->skipReason) {
            null => 'default',
            'reactivation_snoozed' => 'warning',
            'missing_phone', 'whatsapp_opt_out', 'marketing_opt_out' => 'danger',
            'cooldown_active', 'future_appointment_exists', 'not_inactive_enough', 'insufficient_history', 'no_visit_history' => 'muted',
            default => 'warning',
        };
    }

    private function clientReactivationHelp(WhatsappAutomationCandidate $candidate): ?string
    {
        return match ($candidate->skipReason) {
            'reactivation_snoozed' => data_get($candidate->context, 'reactivation.snoozed_until_local') !== null
                ? sprintf('Ignorado até %s.', (string) data_get($candidate->context, 'reactivation.snoozed_until_local'))
                : 'Ignorado temporariamente pelo gestor.',
            'cooldown_active' => 'Já houve uma reativação recente para esse cliente.',
            'future_appointment_exists' => 'O cliente já tem um novo agendamento futuro.',
            'missing_phone', 'whatsapp_opt_out', 'marketing_opt_out' => 'O cliente não está disponível para contato por WhatsApp.',
            'not_inactive_enough' => 'A janela mínima de inatividade ainda não foi atingida.',
            'insufficient_history', 'no_visit_history' => 'Ainda não há histórico suficiente para essa abordagem.',
            default => null,
        };
    }

    private function manualReminderActionState(WhatsappAutomationCandidate $manualCandidate, bool $canSendManualReminder): array
    {
        return [
            'can_send_reminder' => $canSendManualReminder && $manualCandidate->isEligible(),
            'hint' => $canSendManualReminder && ! $manualCandidate->isEligible()
                ? $this->manualReminderUnavailableHint($manualCandidate->skipReason)
                : null,
        ];
    }

    private function manualReminderUnavailableHint(?string $skipReason): string
    {
        return match ($skipReason) {
            'cooldown_active' => 'Já existe um lembrete recente para este agendamento.',
            'appointment_not_eligible' => 'Esse agendamento não está apto para um novo lembrete.',
            'missing_client' => 'O agendamento ainda não tem cliente vinculado.',
            'missing_phone' => 'O cliente ainda não possui telefone válido.',
            'whatsapp_opt_out' => 'O cliente não autorizou contato por WhatsApp.',
            default => 'A ação manual não está disponível agora.',
        };
    }

    /**
     * @param  array{eligible:bool,message:string}  $confirmationState
     * @return array{can_send_confirmation:bool,hint:?string}
     */
    private function manualConfirmationActionState(array $confirmationState, bool $canSendManualConfirmation): array
    {
        return [
            'can_send_confirmation' => $canSendManualConfirmation && (bool) ($confirmationState['eligible'] ?? false),
            'hint' => $canSendManualConfirmation && ! (bool) ($confirmationState['eligible'] ?? false)
                ? (string) ($confirmationState['message'] ?? 'A confirmação manual não está disponível agora.')
                : null,
        ];
    }

    private function messageSummary(?Message $message, Tenant $tenant, string $emptyLabel): array
    {
        if (! $message instanceof Message) {
            return [
                'label' => $emptyLabel,
                'at' => null,
                'at_local' => null,
                'body' => null,
            ];
        }

        $timezone = $tenant->timezone ?: config('app.timezone', 'UTC');
        $createdAt = $message->created_at !== null
            ? CarbonImmutable::instance($message->created_at)->setTimezone($timezone)
            : null;

        $label = match ($message->status) {
            'read' => 'Última mensagem lida',
            'delivered' => 'Última mensagem entregue',
            'sent', 'dispatched' => 'Último envio confirmado',
            'queued' => 'Último envio enfileirado',
            'failed' => 'Última mensagem falhou',
            'duplicate_prevented' => 'Último envio evitado por duplicidade',
            default => 'Última mensagem relacionada',
        };

        return [
            'label' => $label,
            'at' => $message->created_at?->toIso8601String(),
            'at_local' => $createdAt?->format('d/m/Y H:i'),
            'body' => $message->body_text,
        ];
    }
}
