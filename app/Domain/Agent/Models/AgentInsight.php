<?php

namespace App\Domain\Agent\Models;

use App\Domain\Automation\Models\Automation;
use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentInsight extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_run_id',
        'channel',
        'insight_key',
        'type',
        'recommendation_type',
        'status',
        'severity',
        'priority',
        'title',
        'summary',
        'target_type',
        'target_id',
        'target_label',
        'provider',
        'slot',
        'automation_id',
        'evidence_json',
        'suggested_action',
        'action_payload_json',
        'execution_mode',
        'execution_result_json',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'ignored_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_json' => 'array',
            'action_payload_json' => 'array',
            'execution_result_json' => 'array',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ignored_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
