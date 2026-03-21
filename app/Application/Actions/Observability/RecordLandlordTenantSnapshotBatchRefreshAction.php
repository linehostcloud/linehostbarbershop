<?php

namespace App\Application\Actions\Observability;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordLandlordTenantSnapshotBatchRefreshAction
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function started(User $actor, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::info('Landlord tenant snapshot batch refresh started.', $this->context(
            actor: $actor,
            event: 'landlord.tenants.snapshots.batch_refresh_started',
            context: $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function completed(User $actor, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::info('Landlord tenant snapshot batch refresh completed.', $this->context(
            actor: $actor,
            event: 'landlord.tenants.snapshots.batch_refresh_completed',
            context: $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function partiallyCompleted(User $actor, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::warning('Landlord tenant snapshot batch refresh partially completed.', $this->context(
            actor: $actor,
            event: 'landlord.tenants.snapshots.batch_refresh_partially_completed',
            context: $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function failed(User $actor, Throwable $throwable, array $context = []): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        Log::warning('Landlord tenant snapshot batch refresh failed.', array_merge(
            $this->context(
                actor: $actor,
                event: 'landlord.tenants.snapshots.batch_refresh_failed',
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
     * @return array<string, mixed>
     */
    private function context(User $actor, string $event, array $context = []): array
    {
        return array_merge([
            'event' => $event,
            'actor_user_id' => (string) $actor->getKey(),
            'actor_email' => (string) $actor->email,
        ], $context);
    }

    private function loggingEnabled(): bool
    {
        return (bool) config('observability.landlord_tenants_detail_snapshot.batch_refresh_logging_enabled', true);
    }
}
