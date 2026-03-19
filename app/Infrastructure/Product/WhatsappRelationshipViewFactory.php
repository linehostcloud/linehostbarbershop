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
        WhatsappAutomationCandidate $candidate,
        Automation $automation,
        ?Message $latestMessage,
        CarbonImmutable $now,
        Tenant $tenant,
        bool $canSendManualReminder,
    ): array {
        $timezone = $tenant->timezone ?: config('app.timezone', 'UTC');
        $startsAt = $appointment->starts_at !== null
            ? CarbonImmutable::instance($appointment->starts_at)->setTimezone($timezone)
            : null;
        $reminderStatus = $this->appointmentReminderStatus($appointment, $candidate, $automation, $now);

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
            'whatsapp_eligible' => ! in_array($candidate->skipReason, ['missing_client', 'missing_phone', 'whatsapp_opt_out'], true),
            'reminder' => [
                'label' => $reminderStatus['label'],
                'tone' => $reminderStatus['tone'],
                'help' => $reminderStatus['help'],
            ],
            'confirmation' => [
                'label' => $this->appointmentConfirmationLabel((string) $appointment->confirmation_status),
                'raw_status' => (string) $appointment->confirmation_status,
            ],
            'latest_message' => $this->messageSummary($latestMessage, 'Nenhum envio relacionado ainda.'),
            'manual_actions' => [
                'can_send_reminder' => $canSendManualReminder,
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
            ],
            'last_visit_at' => data_get($reactivation, 'last_visit_at'),
            'last_visit_at_local' => data_get($reactivation, 'last_visit_at_local'),
            'inactive_days' => data_get($reactivation, 'inactive_days'),
            'completed_visits' => data_get($reactivation, 'completed_visits'),
            'retention_status' => $client?->retention_status,
            'latest_message' => $this->messageSummary($latestMessage, 'Nenhuma reativação enviada ainda.'),
            'manual_actions' => [
                'can_trigger_reactivation' => $canTriggerManual && $candidate->isEligible(),
            ],
        ];
    }

    public function dashboardSummary(
        Automation $appointmentAutomation,
        array $appointmentSummary,
        int $remindersSentToday,
        Automation $reactivationAutomation,
        array $reactivationSummary,
    ): array {
        return [
            [
                'label' => 'Lembretes elegíveis agora',
                'value' => (int) ($appointmentSummary['eligible_total'] ?? 0),
                'help' => 'Agendamentos que podem receber lembrete neste momento.',
                'tone' => 'default',
            ],
            [
                'label' => 'Lembretes enviados hoje',
                'value' => $remindersSentToday,
                'help' => $appointmentAutomation->isActive()
                    ? 'Mensagens de lembrete já enfileiradas hoje.'
                    : 'A automação está desativada; os envios podem ter sido manuais.',
                'tone' => $appointmentAutomation->isActive() ? 'positive' : 'warning',
            ],
            [
                'label' => 'Clientes para reativar',
                'value' => (int) ($reactivationSummary['eligible_total'] ?? 0),
                'help' => 'Clientes com histórico e inatividade suficiente para reativação.',
                'tone' => 'default',
            ],
            [
                'label' => 'Automações WhatsApp',
                'value' => sprintf(
                    'Lembrete %s • Reativação %s',
                    $appointmentAutomation->isActive() ? 'ativa' : 'inativa',
                    $reactivationAutomation->isActive() ? 'ativa' : 'inativa',
                ),
                'help' => 'Estado atual das automações de relacionamento.',
                'tone' => $appointmentAutomation->isActive() || $reactivationAutomation->isActive() ? 'positive' : 'warning',
            ],
        ];
    }

    /**
     * @return array{label:string,tone:string,help:string}
     */
    private function appointmentReminderStatus(
        Appointment $appointment,
        WhatsappAutomationCandidate $candidate,
        Automation $automation,
        CarbonImmutable $now,
    ): array {
        if (! $automation->isActive()) {
            return [
                'label' => 'Automação desativada',
                'tone' => 'warning',
                'help' => 'O lembrete automático está desligado para este tenant.',
            ];
        }

        if ($appointment->reminder_sent_at !== null) {
            return [
                'label' => 'Lembrete enviado',
                'tone' => 'positive',
                'help' => 'Já houve envio relacionado a este agendamento.',
            ];
        }

        return match ($candidate->skipReason) {
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
                'label' => 'Lembrete enviado',
                'tone' => 'positive',
                'help' => 'Já houve envio automático para este agendamento.',
            ],
            default => [
                'label' => 'Acompanhar manualmente',
                'tone' => 'warning',
                'help' => 'Esse agendamento precisa de revisão antes do próximo envio.',
            ],
        };
    }

    private function appointmentConfirmationLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Confirmado',
            'declined' => 'Cliente informou ausência',
            'reminder_queued', 'manual_confirmation_requested' => 'Confirmação pendente',
            'not_sent', '' => 'Ainda não disparado',
            default => str_replace('_', ' ', ucfirst($status)),
        };
    }

    private function clientReactivationLabel(WhatsappAutomationCandidate $candidate): string
    {
        return match ($candidate->skipReason) {
            null => 'Elegível para reativação',
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
            'missing_phone', 'whatsapp_opt_out', 'marketing_opt_out' => 'danger',
            'cooldown_active', 'future_appointment_exists', 'not_inactive_enough', 'insufficient_history', 'no_visit_history' => 'muted',
            default => 'warning',
        };
    }

    /**
     * @return array{label:string,at:?string,body:?string}
     */
    private function messageSummary(?Message $message, string $emptyLabel): array
    {
        if (! $message instanceof Message) {
            return [
                'label' => $emptyLabel,
                'at' => null,
                'body' => null,
            ];
        }

        $label = match ($message->status) {
            'read' => 'Última mensagem lida',
            'delivered' => 'Última mensagem entregue',
            'sent', 'dispatched', 'queued' => 'Última mensagem em andamento',
            'failed' => 'Última mensagem falhou',
            default => 'Última mensagem relacionada',
        };

        return [
            'label' => $label,
            'at' => $message->created_at?->toIso8601String(),
            'body' => $message->body_text,
        ];
    }
}
