<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\TenantOperationalBlockAudit;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildLandlordTenantSuspendedPressureAction
{
    private const PRESSURE_WINDOW_DAYS = 7;

    /**
     * @param  Collection<int, Tenant>|null  $tenants
     * @return Collection<int, array{
     *     id:string,
     *     trade_name:string,
     *     slug:string,
     *     total_blocks:int,
     *     affected_channels_count:int,
     *     last_blocked_at:string|null,
     *     channels:list<string>
     * }>
     */
    public function execute(?Collection $tenants = null): Collection
    {
        $tenantCollection = $tenants ?? Tenant::query()
            ->where('status', 'suspended')
            ->orderBy('trade_name')
            ->get();
        $suspendedTenants = $tenantCollection
            ->filter(fn (Tenant $tenant): bool => (string) $tenant->status === 'suspended')
            ->keyBy(fn (Tenant $tenant): string => (string) $tenant->getKey());

        if ($suspendedTenants->isEmpty()) {
            return collect();
        }

        $windowStart = now()->subDays(self::PRESSURE_WINDOW_DAYS);
        $tenantIds = $suspendedTenants->keys()->all();
        $channelLabels = BuildLandlordTenantSuspensionObservabilityAction::channelLabels();
        $transversalAudits = TenantOperationalBlockAudit::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('reason_code', 'tenant_status_runtime_enforcement')
            ->where('occurred_at', '>=', $windowStart)
            ->get();
        $boundaryAudits = BoundaryRejectionAudit::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('code', WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value)
            ->where('occurred_at', '>=', $windowStart)
            ->get()
            ->filter(fn (BoundaryRejectionAudit $audit): bool => data_get($audit->context_json, 'tenant_status') === 'suspended')
            ->values();

        return $suspendedTenants
            ->map(function (Tenant $tenant) use ($transversalAudits, $boundaryAudits, $channelLabels): ?array {
                $tenantId = (string) $tenant->getKey();
                $tenantTransversalAudits = $transversalAudits->where('tenant_id', $tenantId)->values();
                $tenantBoundaryAudits = $boundaryAudits->where('tenant_id', $tenantId)->values();
                $channelCounts = collect();

                foreach ($tenantTransversalAudits as $audit) {
                    $channel = (string) $audit->channel;
                    $channelCounts->put($channel, ((int) $channelCounts->get($channel, 0)) + 1);
                }

                foreach ($tenantBoundaryAudits as $audit) {
                    $channel = (string) $audit->direction;
                    $channelCounts->put($channel, ((int) $channelCounts->get($channel, 0)) + 1);
                }

                $totalBlocks = (int) $channelCounts->sum();

                if ($totalBlocks === 0) {
                    return null;
                }

                $lastSeenAt = collect([
                    $tenantTransversalAudits->max('occurred_at'),
                    $tenantBoundaryAudits->max('occurred_at'),
                ])->filter()->sortDesc()->first();

                return [
                    'id' => $tenantId,
                    'trade_name' => (string) $tenant->trade_name,
                    'slug' => (string) $tenant->slug,
                    'total_blocks' => $totalBlocks,
                    'affected_channels_count' => $channelCounts->filter(fn (int $count): bool => $count > 0)->count(),
                    'last_blocked_at' => $this->formatDate($lastSeenAt),
                    'channels' => $channelCounts
                        ->sortDesc()
                        ->keys()
                        ->map(fn (string $channel): string => $channelLabels[$channel] ?? ucfirst($channel))
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->sort(fn (array $left, array $right): int => [
                -$left['total_blocks'],
                $left['trade_name'],
            ] <=> [
                -$right['total_blocks'],
                $right['trade_name'],
            ])
            ->values();
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)
            ->setTimezone(config('app.timezone', 'UTC'))
            ->format('d/m/Y H:i');
    }
}
