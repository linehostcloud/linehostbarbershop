<?php

namespace App\Domain\Tenant\Models;

use App\Infrastructure\Persistence\LandlordModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordSnapshotBatchExecution extends LandlordModel
{
    use HasUlids;

    protected $table = 'landlord_snapshot_batch_executions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'type_label',
        'actor_id',
        'status',
        'total_target',
        'total_queued',
        'total_succeeded',
        'total_failed',
        'total_skipped',
        'metadata_json',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'total_target' => 'integer',
            'total_queued' => 'integer',
            'total_succeeded' => 'integer',
            'total_failed' => 'integer',
            'total_skipped' => 'integer',
            'metadata_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function totalReported(): int
    {
        return $this->total_succeeded + $this->total_failed + $this->total_skipped;
    }

    public function dispatchSkippedCount(): int
    {
        return (int) data_get($this->metadata_json, 'dispatch_skipped_count', 0);
    }

    public function totalJobsReported(): int
    {
        return $this->total_succeeded + $this->total_failed + max(0, $this->total_skipped - $this->dispatchSkippedCount());
    }

    public function progressPercentage(): int
    {
        if ($this->total_queued <= 0) {
            return 100;
        }

        return min(100, (int) round($this->totalJobsReported() / $this->total_queued * 100));
    }

    public function isStuck(int $stuckAfterSeconds = 900): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        if ($this->started_at === null) {
            return false;
        }

        return $this->started_at->diffInSeconds(now()) >= $stuckAfterSeconds;
    }

    public function elapsedSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->finished_at ?? now();

        return max(0, (int) $this->started_at->diffInSeconds($end));
    }
}
