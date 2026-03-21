<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Support\Observability\LandlordTenantIndexPerformanceTracker;
use Illuminate\Support\Collection;

class BuildLandlordDashboardDataAction
{
    private const RECENT_ACTIVITY_LIMIT = 6;

    private const PENDING_TENANTS_LIMIT = 5;

    private const SUSPENDED_PRESSURE_LIMIT = 5;

    private const ATTENTION_LIMIT = 6;

    public function __construct(
        private readonly BuildLandlordTenantIndexReadContextAction $buildReadContext,
        private readonly MapLandlordAuditLogActivityAction $mapAuditActivity,
        private readonly LandlordTenantIndexPerformanceTracker $performanceTracker,
    ) {}

    /**
     * @return array{
     *     headline:array{
     *         total_tenants:int,
     *         status_totals:list<array{code:string,label:string,count:int}>,
     *         onboarding_totals:list<array{code:string,label:string,count:int}>
     *     },
     *     operational:array{
     *         pending_tenants_count:int,
     *         suspended_with_pressure_count:int,
     *         pressure_window_label:string
     *     },
     *     pending_tenants:list<array{
     *         id:string,
     *         trade_name:string,
     *         slug:string,
     *         status:array{code:string,label:string},
     *         onboarding_stage:array{code:string,label:string},
     *         provisioning:array{code:string,label:string,detail:string}
     *     }>,
     *     suspended_pressure:list<array{
     *         id:string,
     *         trade_name:string,
     *         slug:string,
     *         total_blocks:int,
     *         affected_channels_count:int,
     *         last_blocked_at:string|null,
     *         channels:list<string>
     *     }>,
     *     recent_activity:list<array{
     *         id:string,
     *         action:string,
     *         label:string,
     *         detail:string,
     *         occurred_at:string|null,
     *         actor:array{name:string|null,email:string|null,label:string},
     *         tenant:array{id:string|null,trade_name:string|null,slug:string|null,label:string}
     *     }>,
     *     attention_items:list<array{
     *         type:string,
     *         label:string,
     *         detail:string,
     *         tenant:array{id:string,trade_name:string,slug:string}
     *     }>
     * }
     */
    public function execute(?LandlordTenantIndexReadContext $readContext = null): array
    {
        return $this->performanceTracker->measure('dashboard_data_duration_ms', function () use ($readContext): array {
            $readContext ??= $this->buildReadContext->execute();
            $tenantSummaries = $readContext->tenantSummaries;
            $pendingTenants = $this->buildPendingTenants($tenantSummaries);
            $suspendedPressure = $readContext->suspendedPressure;
            $recentActivity = $this->performanceTracker->measure('dashboard_recent_activity_duration_ms', fn () => AuditLog::query()
                ->with(['actor', 'tenant'])
                ->latest('created_at')
                ->latest('id')
                ->limit(self::RECENT_ACTIVITY_LIMIT)
                ->get()
                ->map(fn (AuditLog $auditLog): array => $this->mapAuditActivity->execute($auditLog))
                ->values()
                ->all());

            return [
                'headline' => [
                    'total_tenants' => $tenantSummaries->count(),
                    'status_totals' => $this->buildBreakdown(
                        $tenantSummaries->countBy(fn (array $tenant): string => (string) data_get($tenant, 'status.code')),
                        [
                            'trial' => 'Trial',
                            'active' => 'Ativo',
                            'suspended' => 'Suspenso',
                        ],
                    ),
                    'onboarding_totals' => $this->buildBreakdown(
                        $tenantSummaries->countBy(fn (array $tenant): string => (string) data_get($tenant, 'onboarding_stage.code')),
                        [
                            'created' => 'Criado',
                            'provisioned' => 'Provisionado',
                            'completed' => 'Concluído',
                        ],
                    ),
                ],
                'operational' => [
                    'pending_tenants_count' => $pendingTenants->count(),
                    'suspended_with_pressure_count' => $suspendedPressure->count(),
                    'pressure_window_label' => 'Últimos 7 dias',
                ],
                'pending_tenants' => $pendingTenants
                    ->take(self::PENDING_TENANTS_LIMIT)
                    ->values()
                    ->all(),
                'suspended_pressure' => $suspendedPressure
                    ->take(self::SUSPENDED_PRESSURE_LIMIT)
                    ->values()
                    ->all(),
                'recent_activity' => $recentActivity,
                'attention_items' => $this->buildAttentionItems($pendingTenants, $suspendedPressure),
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tenantSummaries
     * @return Collection<int, array{
     *     id:string,
     *     trade_name:string,
     *     slug:string,
     *     status:array{code:string,label:string},
     *     onboarding_stage:array{code:string,label:string},
     *     provisioning:array{code:string,label:string,detail:string}
     * }>
     */
    private function buildPendingTenants(Collection $tenantSummaries): Collection
    {
        return $tenantSummaries
            ->filter(fn (array $tenant): bool => data_get($tenant, 'provisioning.code') !== 'provisioned')
            ->map(fn (array $tenant): array => [
                'id' => (string) $tenant['id'],
                'trade_name' => (string) $tenant['trade_name'],
                'slug' => (string) $tenant['slug'],
                'status' => $tenant['status'],
                'onboarding_stage' => $tenant['onboarding_stage'],
                'provisioning' => [
                    'code' => (string) data_get($tenant, 'provisioning.code'),
                    'label' => (string) data_get($tenant, 'provisioning.label'),
                    'detail' => (string) data_get($tenant, 'provisioning.detail'),
                ],
                'severity' => $this->pendingSeverity((string) data_get($tenant, 'provisioning.code')),
            ])
            ->sort(fn (array $left, array $right): int => [
                $left['severity'],
                $left['trade_name'],
            ] <=> [
                $right['severity'],
                $right['trade_name'],
            ])
            ->values()
            ->map(function (array $tenant): array {
                unset($tenant['severity']);

                return $tenant;
            });
    }

    /**
     * @param  Collection<int, array{
     *     id:string,
     *     trade_name:string,
     *     slug:string,
     *     status:array{code:string,label:string},
     *     onboarding_stage:array{code:string,label:string},
     *     provisioning:array{code:string,label:string,detail:string}
     * }>  $pendingTenants
     * @param  Collection<int, array{
     *     id:string,
     *     trade_name:string,
     *     slug:string,
     *     total_blocks:int,
     *     affected_channels_count:int,
     *     last_blocked_at:string|null,
     *     channels:list<string>
     * }>  $suspendedPressure
     * @return list<array{
     *     type:string,
     *     label:string,
     *     detail:string,
     *     tenant:array{id:string,trade_name:string,slug:string}
     * }>
     */
    private function buildAttentionItems(Collection $pendingTenants, Collection $suspendedPressure): array
    {
        $pressureItems = $suspendedPressure
            ->take(3)
            ->map(fn (array $tenant): array => [
                'type' => 'suspension_pressure',
                'label' => 'Suspensão com pressão recente',
                'detail' => sprintf(
                    '%d bloqueio(s) recente(s) em %d canal(is).',
                    $tenant['total_blocks'],
                    $tenant['affected_channels_count'],
                ),
                'tenant' => [
                    'id' => $tenant['id'],
                    'trade_name' => $tenant['trade_name'],
                    'slug' => $tenant['slug'],
                ],
                'priority' => 0,
            ]);

        $pendingItems = $pendingTenants
            ->take(3)
            ->map(fn (array $tenant): array => [
                'type' => 'operational_pending',
                'label' => $tenant['provisioning']['label'],
                'detail' => $tenant['provisioning']['detail'],
                'tenant' => [
                    'id' => $tenant['id'],
                    'trade_name' => $tenant['trade_name'],
                    'slug' => $tenant['slug'],
                ],
                'priority' => 1,
            ]);

        return $pressureItems
            ->merge($pendingItems)
            ->sortBy('priority')
            ->take(self::ATTENTION_LIMIT)
            ->map(function (array $item): array {
                unset($item['priority']);

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, int>  $counts
     * @param  array<string, string>  $labels
     * @return list<array{code:string,label:string,count:int}>
     */
    private function buildBreakdown(Collection $counts, array $labels): array
    {
        $items = collect($labels)
            ->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
                'count' => (int) $counts->get($code, 0),
            ]);

        $extraItems = $counts
            ->reject(fn (int $count, string $code): bool => array_key_exists($code, $labels))
            ->map(fn (int $count, string $code): array => [
                'code' => $code,
                'label' => ucfirst(str_replace('_', ' ', $code)),
                'count' => $count,
            ]);

        return $items
            ->merge($extraItems)
            ->values()
            ->all();
    }

    private function pendingSeverity(string $code): int
    {
        return match ($code) {
            'database_missing' => 0,
            'connection_failed' => 1,
            'schema_pending' => 2,
            'domain_missing' => 3,
            'owner_missing' => 4,
            default => 5,
        };
    }
}
