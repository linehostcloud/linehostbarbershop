<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;

class ResolveLandlordTenantDetailSnapshotAction
{
    /**
     * @return array{
     *     model:LandlordTenantDetailSnapshot|null,
     *     payload:array<string, mixed>,
     *     has_payload:bool,
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
    public function execute(Tenant $tenant): array
    {
        $tenant->loadMissing('detailSnapshot');

        /** @var LandlordTenantDetailSnapshot|null $snapshot */
        $snapshot = $tenant->detailSnapshot;
        $payload = is_array($snapshot?->payload_json) ? $snapshot->payload_json : [];
        $hasPayload = $payload !== [];
        $staleAfterSeconds = max(60, (int) config('landlord.tenants.detail_snapshot.stale_after_seconds', 900));
        $ageSeconds = $snapshot?->generated_at instanceof \DateTimeInterface
            ? max(0, (int) Carbon::instance($snapshot->generated_at)->diffInSeconds(now()))
            : null;
        $isExpired = $ageSeconds !== null && $ageSeconds > $staleAfterSeconds;
        $status = $this->resolveStatus(
            refreshStatus: $snapshot?->refresh_status,
            hasPayload: $hasPayload,
            isExpired: $isExpired,
        );

        return [
            'model' => $snapshot,
            'payload' => $payload,
            'has_payload' => $hasPayload,
            'status' => $status,
            'label' => $this->label($status),
            'detail' => $this->detail($status, $hasPayload),
            'generated_at' => $this->formatDate($snapshot?->generated_at),
            'generated_at_iso' => $snapshot?->generated_at?->toIso8601String(),
            'age_seconds' => $ageSeconds,
            'is_stale' => $status === 'stale',
            'stale_after_seconds' => $staleAfterSeconds,
            'last_refresh_started_at' => $this->formatDate($snapshot?->last_refresh_started_at),
            'last_refresh_completed_at' => $this->formatDate($snapshot?->last_refresh_completed_at),
            'last_refresh_failed_at' => $this->formatDate($snapshot?->last_refresh_failed_at),
            'last_refresh_error' => $snapshot?->last_refresh_error,
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

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)
            ->setTimezone(config('app.timezone', 'UTC'))
            ->format('d/m/Y H:i');
    }
}
