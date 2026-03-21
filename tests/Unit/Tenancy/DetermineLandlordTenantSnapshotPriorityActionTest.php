<?php

namespace Tests\Unit\Tenancy;

use App\Application\Actions\Tenancy\DetermineLandlordTenantSnapshotPriorityAction;
use Tests\TestCase;

class DetermineLandlordTenantSnapshotPriorityActionTest extends TestCase
{
    public function test_missing_snapshot_is_high_priority(): void
    {
        $priority = app(DetermineLandlordTenantSnapshotPriorityAction::class)
            ->execute('missing', false, null);

        $this->assertSame('high', $priority['code']);
        $this->assertSame(300, $priority['rank']);
    }

    public function test_failed_snapshot_without_payload_is_high_priority(): void
    {
        $priority = app(DetermineLandlordTenantSnapshotPriorityAction::class)
            ->execute('failed', false, null);

        $this->assertSame('high', $priority['code']);
        $this->assertSame('Alta', $priority['label']);
    }

    public function test_recent_stale_snapshot_is_medium_priority(): void
    {
        $priority = app(DetermineLandlordTenantSnapshotPriorityAction::class)
            ->execute('stale', true, now()->subMinutes(20));

        $this->assertSame('medium', $priority['code']);
        $this->assertSame(200, $priority['rank']);
    }

    public function test_healthy_snapshot_is_low_priority(): void
    {
        $priority = app(DetermineLandlordTenantSnapshotPriorityAction::class)
            ->execute('ready', true, now()->subMinutes(2));

        $this->assertSame('low', $priority['code']);
        $this->assertSame(100, $priority['rank']);
    }
}
