<?php

namespace App\Domain\Automation\Enums;

enum WhatsappAutomationType: string
{
    case AppointmentReminder = 'appointment_reminder';
    case InactiveClientReactivation = 'inactive_client_reactivation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    public function defaultName(): string
    {
        return match ($this) {
            self::AppointmentReminder => 'Lembrete de Agendamento',
            self::InactiveClientReactivation => 'Reativacao de Cliente Inativo',
        };
    }

    public function defaultDescription(): string
    {
        return match ($this) {
            self::AppointmentReminder => 'Envia lembretes determinísticos para agendamentos futuros elegíveis.',
            self::InactiveClientReactivation => 'Envia mensagens de reativacao para clientes sem visitas recentes.',
        };
    }
}
