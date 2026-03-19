<?php

namespace App\Domain\Automation\Models;

use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'automation_id',
        'automation_type',
        'channel',
        'status',
        'window_started_at',
        'window_ended_at',
        'candidates_found',
        'messages_queued',
        'skipped_total',
        'failed_total',
        'run_context_json',
        'result_json',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'window_ended_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'run_context_json' => 'array',
            'result_json' => 'array',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AutomationRunTarget::class);
    }
}
