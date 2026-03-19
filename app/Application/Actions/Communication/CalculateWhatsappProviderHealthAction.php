<?php

namespace App\Application\Actions\Communication;

use App\Application\DTOs\OperationalWindow;
use App\Application\DTOs\WhatsappProviderHealthSnapshot;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CalculateWhatsappProviderHealthAction
{
    public function execute(
        WhatsappProviderConfig $configuration,
        ?OperationalWindow $window = null,
    ): WhatsappProviderHealthSnapshot {
        $window ??= $this->defaultWindow();

        $statusTotals = IntegrationAttempt::query()
            ->where('channel', 'whatsapp')
            ->where('operation', 'send_message')
            ->where('direction', 'outbound')
            ->where('provider', $configuration->provider)
            ->whereBetween('created_at', [$window->startedAt, $window->endedAt])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        $errorTotalsCollection = IntegrationAttempt::query()
            ->where('channel', 'whatsapp')
            ->where('operation', 'send_message')
            ->where('direction', 'outbound')
            ->where('provider', $configuration->provider)
            ->whereBetween('created_at', [$window->startedAt, $window->endedAt])
            ->whereNotNull('normalized_error_code')
            ->selectRaw('normalized_error_code, COUNT(*) as total')
            ->groupBy('normalized_error_code')
            ->orderByDesc('total')
            ->get()
            ->map(fn (IntegrationAttempt $attempt): array => [
                'code' => (string) $attempt->normalized_error_code,
                'total' => (int) $attempt->total,
            ]);

        $lastAttemptAt = IntegrationAttempt::query()
            ->where('channel', 'whatsapp')
            ->where('operation', 'send_message')
            ->where('direction', 'outbound')
            ->where('provider', $configuration->provider)
            ->whereBetween('created_at', [$window->startedAt, $window->endedAt])
            ->max('created_at');

        $fallbackEvents = EventLog::query()
            ->whereIn('event_name', [
                'whatsapp.message.fallback.scheduled',
                'whatsapp.message.fallback.executed',
            ])
            ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt])
            ->get();

        $fallbackScheduledTotal = 0;
        $fallbackExecutedTotal = 0;

        foreach ($fallbackEvents as $event) {
            if (! $this->providerInvolvedInFallback($event, $configuration->provider)) {
                continue;
            }

            if ($event->event_name === 'whatsapp.message.fallback.scheduled') {
                $fallbackScheduledTotal++;
            }

            if ($event->event_name === 'whatsapp.message.fallback.executed') {
                $fallbackExecutedTotal++;
            }
        }

        $sendAttemptsTotal = array_sum(array_values($statusTotals));
        $successesRecent = (int) ($statusTotals['succeeded'] ?? 0);
        $retriesRecent = (int) ($statusTotals['retry_scheduled'] ?? 0);
        $scheduledFallbackAttempts = (int) ($statusTotals['fallback_scheduled'] ?? 0);
        $failuresRecent = (int) ($statusTotals['failed'] ?? 0) + $retriesRecent + $scheduledFallbackAttempts;
        $fallbacksRecent = $fallbackScheduledTotal + $fallbackExecutedTotal;
        $timeoutRecent = $this->errorTotal($errorTotalsCollection, 'timeout_error');
        $rateLimitRecent = $this->errorTotal($errorTotalsCollection, 'rate_limit');
        $unavailableRecent = $this->errorTotal($errorTotalsCollection, 'provider_unavailable');
        $transientRecent = $this->errorTotal($errorTotalsCollection, 'transient_network_error');
        [$stateLabel, $stateReason] = $this->stateFor(
            enabled: (bool) $configuration->enabled,
            sendAttemptsTotal: $sendAttemptsTotal,
            successesRecent: $successesRecent,
            failuresRecent: $failuresRecent,
            retriesRecent: $retriesRecent,
            fallbacksRecent: $fallbacksRecent,
            timeoutRecent: $timeoutRecent,
            rateLimitRecent: $rateLimitRecent,
            unavailableRecent: $unavailableRecent,
            transientRecent: $transientRecent,
        );

        return new WhatsappProviderHealthSnapshot(
            window: $window,
            provider: (string) $configuration->provider,
            slot: (string) $configuration->slot,
            enabled: (bool) $configuration->enabled,
            sendAttemptsTotal: $sendAttemptsTotal,
            successesRecent: $successesRecent,
            failuresRecent: $failuresRecent,
            retriesRecent: $retriesRecent,
            fallbacksRecent: $fallbacksRecent,
            fallbackScheduledTotal: $fallbackScheduledTotal,
            fallbackExecutedTotal: $fallbackExecutedTotal,
            timeoutRecent: $timeoutRecent,
            rateLimitRecent: $rateLimitRecent,
            unavailableRecent: $unavailableRecent,
            transientRecent: $transientRecent,
            signalTotals: $errorTotalsCollection
                ->filter(fn (array $row): bool => in_array($row['code'], [
                    'timeout_error',
                    'rate_limit',
                    'provider_unavailable',
                    'transient_network_error',
                ], true))
                ->values()
                ->all(),
            topErrorCodes: $errorTotalsCollection
                ->take((int) config('observability.whatsapp_operations.default_top_error_codes_limit', 5))
                ->values()
                ->all(),
            lastAttemptAt: is_string($lastAttemptAt) ? CarbonImmutable::parse($lastAttemptAt)->toIso8601String() : null,
            stateLabel: $stateLabel,
            stateReason: $stateReason,
        );
    }

    private function defaultWindow(): OperationalWindow
    {
        $timezone = config('app.timezone', 'UTC');
        $endedAt = CarbonImmutable::now($timezone);
        $minutes = max(1, (int) config('communication.whatsapp.health.window_minutes', 30));

        return new OperationalWindow(
            label: sprintf('%dm', $minutes),
            startedAt: $endedAt->subMinutes($minutes),
            endedAt: $endedAt,
        );
    }

    /**
     * @param  Collection<int, array{code:string,total:int}>  $errorTotals
     */
    private function errorTotal(Collection $errorTotals, string $code): int
    {
        $row = $errorTotals->first(fn (array $row): bool => $row['code'] === $code);

        return (int) ($row['total'] ?? 0);
    }

    private function providerInvolvedInFallback(EventLog $eventLog, string $provider): bool
    {
        return in_array($provider, array_filter([
            is_string(data_get($eventLog->payload_json, 'provider')) ? (string) data_get($eventLog->payload_json, 'provider') : null,
            is_string(data_get($eventLog->context_json, 'provider')) ? (string) data_get($eventLog->context_json, 'provider') : null,
            is_string(data_get($eventLog->payload_json, 'fallback.from_provider')) ? (string) data_get($eventLog->payload_json, 'fallback.from_provider') : null,
            is_string(data_get($eventLog->payload_json, 'fallback.to_provider')) ? (string) data_get($eventLog->payload_json, 'fallback.to_provider') : null,
        ], static fn (?string $value): bool => $value !== null && $value !== ''), true);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function stateFor(
        bool $enabled,
        int $sendAttemptsTotal,
        int $successesRecent,
        int $failuresRecent,
        int $retriesRecent,
        int $fallbacksRecent,
        int $timeoutRecent,
        int $rateLimitRecent,
        int $unavailableRecent,
        int $transientRecent,
    ): array {
        if (! $enabled) {
            return ['unavailable', 'Provider desabilitado administrativamente.'];
        }

        if ($unavailableRecent > 0 && $successesRecent === 0) {
            return ['unavailable', 'Provider sem sucesso recente e com sinais de indisponibilidade na janela.'];
        }

        $failureRate = $sendAttemptsTotal > 0
            ? ($failuresRecent / $sendAttemptsTotal) * 100
            : 0.0;
        $signalPressure = $timeoutRecent + $rateLimitRecent + $unavailableRecent + $transientRecent;

        if (
            $fallbacksRecent > 0
            || $failureRate >= (float) config('communication.whatsapp.health.unstable_failure_rate', 50)
            || $retriesRecent >= (int) config('communication.whatsapp.health.unstable_retry_threshold', 3)
            || $signalPressure >= (int) config('communication.whatsapp.health.unstable_signal_threshold', 3)
        ) {
            return ['unstable', 'Ha retries, fallback ou volume recente de falhas suficiente para tornar o provider instavel.'];
        }

        if ($failuresRecent > 0 || $retriesRecent > 0 || $signalPressure > 0) {
            return ['degraded', 'Ha sinais recentes de falha, mas o provider ainda apresenta operacao parcial na janela.'];
        }

        return ['healthy', 'Sem sinais relevantes de falha na janela observada.'];
    }
}
