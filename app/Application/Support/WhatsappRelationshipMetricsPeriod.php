<?php

namespace App\Application\Support;

use Carbon\CarbonImmutable;

enum WhatsappRelationshipMetricsPeriod: string
{
    case Today = 'today';
    case Last7Days = '7d';
    case Last30Days = '30d';

    public static function fromInput(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom($value) ?? self::Last7Days
            : self::Last7Days;
    }

    public function label(): string
    {
        return match ($this) {
            self::Today => 'hoje',
            self::Last7Days => 'últimos 7 dias',
            self::Last30Days => 'últimos 30 dias',
        };
    }

    public function help(string $timezone): string
    {
        return match ($this) {
            self::Today => sprintf('Indicadores acumulados no dia atual, considerando o fuso %s.', $timezone),
            self::Last7Days => sprintf('Indicadores acumulados nos últimos 7 dias corridos, considerando o fuso %s.', $timezone),
            self::Last30Days => sprintf('Indicadores acumulados nos últimos 30 dias corridos, considerando o fuso %s.', $timezone),
        };
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $period): array => [
                'value' => $period->value,
                'label' => match ($period) {
                    self::Today => 'Hoje',
                    self::Last7Days => 'Últimos 7 dias',
                    self::Last30Days => 'Últimos 30 dias',
                },
            ],
            self::cases(),
        );
    }

    /**
     * @return array{
     *     selected:string,
     *     label:string,
     *     help:string,
     *     timezone:string,
     *     from_local:CarbonImmutable,
     *     to_local:CarbonImmutable,
     *     from_storage:CarbonImmutable,
     *     to_storage:CarbonImmutable
     * }
     */
    public function window(string $tenantTimezone): array
    {
        $storageTimezone = config('app.timezone', 'UTC');
        $nowLocal = CarbonImmutable::now($tenantTimezone);

        [$fromLocal, $toLocal] = match ($this) {
            self::Today => [$nowLocal->startOfDay(), $nowLocal],
            self::Last30Days => [$nowLocal->subDays(29)->startOfDay(), $nowLocal],
            self::Last7Days => [$nowLocal->subDays(6)->startOfDay(), $nowLocal],
        };

        return [
            'selected' => $this->value,
            'label' => $this->label(),
            'help' => $this->help($tenantTimezone),
            'timezone' => $tenantTimezone,
            'from_local' => $fromLocal,
            'to_local' => $toLocal,
            'from_storage' => $fromLocal->setTimezone($storageTimezone),
            'to_storage' => $toLocal->setTimezone($storageTimezone),
        ];
    }
}
