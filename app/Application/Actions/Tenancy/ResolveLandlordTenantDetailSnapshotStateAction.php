<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Support\Carbon;

class ResolveLandlordTenantDetailSnapshotStateAction
{
    /**
     * @return array{
     *     status:string,
     *     label:string,
     *     detail:string,
     *     generated_at:string|null,
     *     generated_at_iso:string|null,
     *     age_seconds:int|null,
     *     is_stale:bool,
     *     stale_after_seconds:int,
     *     last_refresh_started_at:string|null,
     *     last_refresh_completed_at:string|null,
     *     last_refresh_failed_at:string|null,
     *     last_refresh_error:string|null
     * }
     */
    public function execute(
        ?string $refreshStatus,
        bool $hasPayload,
        mixed $generatedAt,
        mixed $lastRefreshStartedAt = null,
        mixed $lastRefreshCompletedAt = null,
        mixed $lastRefreshFailedAt = null,
        ?string $lastRefreshError = null,
    ): array {
        $generatedAtDate = $this->asCarbon($generatedAt);
        $staleAfterSeconds = max(60, (int) config('landlord.tenants.detail_snapshot.stale_after_seconds', 900));
        $ageSeconds = $generatedAtDate !== null
            ? max(0, (int) $generatedAtDate->diffInSeconds(now()))
            : null;
        $isExpired = $ageSeconds !== null && $ageSeconds > $staleAfterSeconds;
        $status = $this->resolveStatus(
            refreshStatus: $refreshStatus,
            hasPayload: $hasPayload,
            isExpired: $isExpired,
        );

        return [
            'status' => $status,
            'label' => $this->label($status),
            'detail' => $this->detail($status, $hasPayload),
            'generated_at' => $this->formatDate($generatedAtDate),
            'generated_at_iso' => $generatedAtDate?->toIso8601String(),
            'age_seconds' => $ageSeconds,
            'is_stale' => $status === 'stale',
            'stale_after_seconds' => $staleAfterSeconds,
            'last_refresh_started_at' => $this->formatDate($this->asCarbon($lastRefreshStartedAt)),
            'last_refresh_completed_at' => $this->formatDate($this->asCarbon($lastRefreshCompletedAt)),
            'last_refresh_failed_at' => $this->formatDate($this->asCarbon($lastRefreshFailedAt)),
            'last_refresh_error' => $lastRefreshError,
        ];
    }

    private function resolveStatus(?string $refreshStatus, bool $hasPayload, bool $isExpired): string
    {
        if (! $hasPayload && $refreshStatus === null) {
            return 'missing';
        }

        return match ($refreshStatus) {
            'refreshing' => 'refreshing',
            'failed' => 'failed',
            'stale' => 'stale',
            'ready' => $isExpired ? 'stale' : 'ready',
            default => $hasPayload
                ? ($isExpired ? 'stale' : 'ready')
                : 'missing',
        };
    }

    private function label(string $status): string
    {
        return match ($status) {
            'ready' => 'Snapshot atualizado',
            'stale' => 'Snapshot stale',
            'refreshing' => 'Snapshot em atualização',
            'failed' => 'Snapshot com falha recente',
            default => 'Snapshot administrativo pendente',
        };
    }

    private function detail(string $status, bool $hasPayload): string
    {
        return match ($status) {
            'ready' => 'Provisioning, saúde operacional e hardening da suspensão estão sendo lidos do snapshot administrativo persistido.',
            'stale' => 'A leitura pesada está usando um snapshot antigo até o próximo refresh controlado.',
            'refreshing' => $hasPayload
                ? 'Um refresh está em andamento e a tela está usando a última captura persistida.'
                : 'Um refresh está em andamento e a leitura pesada ainda não possui snapshot utilizável.',
            'failed' => $hasPayload
                ? 'A última atualização falhou e a tela está usando a última captura persistida disponível.'
                : 'A última atualização falhou e a leitura pesada ainda está sem snapshot utilizável.',
            default => 'A leitura pesada ainda não foi materializada em snapshot persistido para este tenant.',
        };
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function formatDate(?Carbon $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value
            ->setTimezone(config('app.timezone', 'UTC'))
            ->format('d/m/Y H:i');
    }
}
