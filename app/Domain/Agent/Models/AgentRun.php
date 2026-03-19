<?php

namespace App\Domain\Agent\Models;

use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'status',
        'window_started_at',
        'window_ended_at',
        'insights_created',
        'insights_refreshed',
        'insights_resolved',
        'insights_ignored',
        'safe_actions_executed',
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

    public function insights(): HasMany
    {
        return $this->hasMany(AgentInsight::class);
    }
}
