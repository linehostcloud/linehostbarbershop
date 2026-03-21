<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\LandlordTenantDetailPerformanceTracker;
use RuntimeException;
use Tests\TestCase;

class LandlordTenantDetailPerformanceTrackerTest extends TestCase
{
    public function test_tracker_accumulates_durations_counts_meta_and_failures(): void
    {
        $tracker = new LandlordTenantDetailPerformanceTracker();

        $tracker->setCount('domain_count', 2);
        $tracker->increment('provisioning_validation_count');
        $tracker->increment('provisioning_validation_count', 2);
        $tracker->setMeta('route_name', 'landlord.tenants.show');
        $tracker->measure('summary_mapping_duration_ms', function (): void {
            usleep(1000);
        });
        $tracker->measure('summary_mapping_duration_ms', function (): void {
            usleep(1000);
        });

        try {
            throw new RuntimeException('Falha sintética.');
        } catch (RuntimeException $exception) {
            $tracker->recordFailure('suspension_observability.missing_tables', $exception, [
                'tenant_id' => 'tenant-1',
            ]);
        }

        $snapshot = $tracker->snapshot();

        $this->assertSame(2, $snapshot['counts']['domain_count']);
        $this->assertSame(3, $snapshot['counts']['provisioning_validation_count']);
        $this->assertSame(1, $snapshot['counts']['technical_failure_count']);
        $this->assertSame('landlord.tenants.show', $snapshot['meta']['route_name']);
        $this->assertGreaterThanOrEqual(1, $snapshot['durations_ms']['summary_mapping_duration_ms']);
        $this->assertSame('suspension_observability.missing_tables', $snapshot['failures'][0]['area']);
        $this->assertSame(RuntimeException::class, $snapshot['failures'][0]['error_class']);
        $this->assertSame('tenant-1', $snapshot['failures'][0]['tenant_id']);
    }
}
