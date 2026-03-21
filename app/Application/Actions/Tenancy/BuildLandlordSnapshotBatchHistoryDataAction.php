<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\LandlordSnapshotBatchExecution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildLandlordSnapshotBatchHistoryDataAction
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(int $limit = 20): Collection
    {
        $stuckAfterSeconds = max(60, (int) config(
            'landlord.tenants.detail_snapshot.batch_stuck_after_seconds',
            900,
        ));

        return LandlordSnapshotBatchExecution::query()
            ->with('actor:id,name,email')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (LandlordSnapshotBatchExecution $batch): array => [
                'id' => (string) $batch->getKey(),
                'type' => $batch->type,
                'type_label' => $batch->type_label,
                'status' => $batch->status,
                'status_label' => $this->statusLabel($batch->status, $batch->isStuck($stuckAfterSeconds)),
                'status_tone' => $this->statusTone($batch->status, $batch->isStuck($stuckAfterSeconds)),
                'is_stuck' => $batch->isStuck($stuckAfterSeconds),
                'actor_name' => $batch->actor?->name ?? 'Sistema',
                'actor_email' => $batch->actor?->email ?? '',
                'total_target' => $batch->total_target,
                'total_queued' => $batch->total_queued,
                'total_succeeded' => $batch->total_succeeded,
                'total_failed' => $batch->total_failed,
                'total_skipped' => $batch->total_skipped,
                'progress_percentage' => $batch->progressPercentage(),
                'started_at' => $batch->started_at?->format('d/m/Y H:i:s'),
                'finished_at' => $batch->finished_at?->format('d/m/Y H:i:s'),
                'duration_label' => $this->durationLabel($batch->elapsedSeconds()),
                'metadata' => $batch->metadata_json,
            ]);
    }

    private function statusLabel(string $status, bool $isStuck): string
    {
        if ($isStuck) {
            return 'Stuck';
        }

        return match ($status) {
            'running' => 'Em execução',
            'completed' => 'Concluído',
            'partial' => 'Parcial',
            'failed' => 'Falhou',
            default => ucfirst($status),
        };
    }

    private function statusTone(string $status, bool $isStuck): string
    {
        if ($isStuck) {
            return 'rose';
        }

        return match ($status) {
            'completed' => 'emerald',
            'running' => 'sky',
            'partial' => 'amber',
            default => 'rose',
        };
    }

    private function durationLabel(?int $elapsedSeconds): ?string
    {
        if ($elapsedSeconds === null) {
            return null;
        }

        if ($elapsedSeconds < 60) {
            return sprintf('%ds', $elapsedSeconds);
        }

        $minutes = intdiv($elapsedSeconds, 60);
        $remaining = $elapsedSeconds % 60;

        return sprintf('%dm %ds', $minutes, $remaining);
    }
}
