<?php

namespace Tests\Unit\Tenancy;

use App\Application\Actions\Tenancy\DetermineLandlordTenantSnapshotBatchRefreshEligibilityAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetermineLandlordTenantSnapshotBatchRefreshEligibilityActionTest extends TestCase
{
    #[Test]
    public function it_marks_refreshing_snapshots_as_not_dispatchable(): void
    {
        $action = app(DetermineLandlordTenantSnapshotBatchRefreshEligibilityAction::class);

        $result = $action->execute(
            snapshotStatus: 'refreshing',
            lastRefreshStartedAt: now()->subMinutes(2),
        );

        $this->assertFalse($result['dispatchable']);
        $this->assertSame('refreshing', $result['reason']);
    }

    #[Test]
    public function it_marks_healthy_snapshots_as_not_dispatchable(): void
    {
        $action = app(DetermineLandlordTenantSnapshotBatchRefreshEligibilityAction::class);

        $result = $action->execute(
            snapshotStatus: 'ready',
            lastRefreshCompletedAt: now()->subMinutes(1),
        );

        $this->assertFalse($result['dispatchable']);
        $this->assertSame('healthy', $result['reason']);
    }

    #[Test]
    public function it_honors_batch_refresh_cooldown_for_recent_attempts(): void
    {
        config()->set('landlord.tenants.detail_snapshot.batch_refresh_cooldown_seconds', 120);

        $action = app(DetermineLandlordTenantSnapshotBatchRefreshEligibilityAction::class);

        $recentFailure = $action->execute(
            snapshotStatus: 'failed',
            lastRefreshFailedAt: now()->subSeconds(30),
        );
        $olderFailure = $action->execute(
            snapshotStatus: 'failed',
            lastRefreshFailedAt: now()->subMinutes(10),
        );

        $this->assertFalse($recentFailure['dispatchable']);
        $this->assertSame('cooldown', $recentFailure['reason']);
        $this->assertTrue($olderFailure['dispatchable']);
        $this->assertNull($olderFailure['reason']);
    }
}
