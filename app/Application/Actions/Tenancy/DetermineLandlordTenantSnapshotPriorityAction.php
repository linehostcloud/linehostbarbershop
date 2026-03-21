<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Support\Carbon;

class DetermineLandlordTenantSnapshotPriorityAction
{
    /**
     * @return array{
     *     code:string,
     *     label:string,
     *     detail:string,
     *     rank:int,
     *     tone:string
     * }
     */
    public function execute(string $snapshotStatus, bool $hasPayload, mixed $generatedAt): array
    {
        $generatedAtDate = $this->asCarbon($generatedAt);
        $highAgeThresholdSeconds = $this->highAgeThresholdSeconds();
        $ageSeconds = $generatedAtDate !== null
            ? max(0, (int) $generatedAtDate->diffInSeconds(now()))
            : null;

        if ($snapshotStatus === 'missing') {
            return $this->priority('high', 'Alta', 'Tenant sem snapshot utilizável e em fallback conservador.', 300, 'rose');
        }

        if ($snapshotStatus === 'failed' && (! $hasPayload || $generatedAtDate === null || ($ageSeconds !== null && $ageSeconds >= $highAgeThresholdSeconds))) {
            return $this->priority('high', 'Alta', 'Falha recente de refresh sem snapshot confiável ou com captura muito antiga.', 300, 'rose');
        }

        if ($snapshotStatus === 'stale' && $ageSeconds !== null && $ageSeconds >= $highAgeThresholdSeconds) {
            return $this->priority('high', 'Alta', 'Snapshot stale há muito tempo e com risco operacional crescente.', 300, 'rose');
        }

        if ($snapshotStatus === 'failed') {
            return $this->priority('medium', 'Média', 'Refresh falhou, mas ainda existe uma captura persistida recente para leitura.', 200, 'amber');
        }

        if ($snapshotStatus === 'refreshing') {
            return $this->priority('medium', 'Média', 'Refresh em andamento; acompanhar para garantir conclusão sem lock recorrente.', 200, 'sky');
        }

        if ($snapshotStatus === 'stale') {
            return $this->priority('medium', 'Média', 'Snapshot stale aguardando refresh controlado.', 200, 'amber');
        }

        return $this->priority('low', 'Baixa', 'Snapshot saudável e disponível para a leitura administrativa padrão.', 100, 'emerald');
    }

    public function highAgeThresholdSeconds(): int
    {
        $staleAfterSeconds = max(60, (int) config('landlord.tenants.detail_snapshot.stale_after_seconds', 900));

        return max($staleAfterSeconds * 4, 3600);
    }

    /**
     * @return array{
     *     code:string,
     *     label:string,
     *     detail:string,
     *     rank:int,
     *     tone:string
     * }
     */
    private function priority(string $code, string $label, string $detail, int $rank, string $tone): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'detail' => $detail,
            'rank' => $rank,
            'tone' => $tone,
        ];
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
}
