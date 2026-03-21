<?php

namespace App\Application\Actions\Observability;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordLandlordTenantDetailSnapshotRefreshAction
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function started(Tenant $tenant, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::info('Landlord tenant detail snapshot refresh started.', $this->context(
            tenant: $tenant,
            event: 'landlord.tenants.show.snapshot_refresh_started',
            context: $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function completed(Tenant $tenant, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::info('Landlord tenant detail snapshot refresh completed.', $this->context(
            tenant: $tenant,
            event: 'landlord.tenants.show.snapshot_refresh_completed',
            context: $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function failed(Tenant $tenant, Throwable $throwable, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::warning('Landlord tenant detail snapshot refresh failed.', array_merge(
            $this->context(
                tenant: $tenant,
                event: 'landlord.tenants.show.snapshot_refresh_failed',
                context: $context,
            ),
            [
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function skippedDueToLock(Tenant $tenant, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::warning('Landlord tenant detail snapshot refresh skipped due to lock.', $this->context(
            tenant: $tenant,
            event: 'landlord.tenants.show.snapshot_refresh_skipped_due_to_lock',
            context: $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function context(Tenant $tenant, string $event, array $context = []): array
    {
        return array_merge([
            'event' => $event,
            'tenant_id' => (string) $tenant->getKey(),
            'tenant_slug' => (string) $tenant->slug,
        ], $context);
    }

    private function loggingEnabled(): bool
    {
        return (bool) config('observability.landlord_tenants_detail_snapshot.refresh_logging_enabled', true);
    }
}
