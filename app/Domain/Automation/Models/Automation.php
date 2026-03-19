<?php

namespace App\Domain\Automation\Models;

use App\Infrastructure\Persistence\TenantModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Automation extends TenantModel
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'created_by_user_id',
        'name',
        'description',
        'trigger_type',
        'trigger_event',
        'status',
        'channel',
        'conditions_json',
        'action_type',
        'action_payload_json',
        'delay_minutes',
        'cooldown_hours',
        'stop_on_response',
        'priority',
        'last_executed_at',
    ];

    protected function casts(): array
    {
        return [
            'conditions_json' => 'array',
            'action_payload_json' => 'array',
            'stop_on_response' => 'boolean',
            'last_executed_at' => 'datetime',
        ];
    }
}
