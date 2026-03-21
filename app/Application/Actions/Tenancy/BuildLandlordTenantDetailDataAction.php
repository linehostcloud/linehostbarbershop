<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantMembership;
use App\Support\Observability\LandlordTenantDetailPerformanceTracker;
use Illuminate\Support\Carbon;

class BuildLandlordTenantDetailDataAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
        private readonly BuildLandlordTenantRecentActivityAction $buildRecentActivity,
        private readonly BuildLandlordTenantStateGovernanceAction $buildStateGovernance,
        private readonly ResolveLandlordTenantDetailSnapshotAction $resolveSnapshot,
        private readonly LandlordTenantDetailPerformanceTracker $performanceTracker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant): array
    {
        return $this->performanceTracker->measure('detail_data_duration_ms', function () use ($tenant): array {
            $this->performanceTracker->measure('tenant_relations_load_duration_ms', function () use ($tenant): void {
                $tenant->loadMissing([
                    'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
                    'memberships.user' => fn ($query) => $query->orderBy('name'),
                    'detailSnapshot',
                ]);
            });

            $this->performanceTracker->setCount('domain_count', $tenant->domains->count());
            $this->performanceTracker->setCount('membership_count', $tenant->memberships->count());
            $snapshot = $this->performanceTracker->measure(
                'snapshot_resolve_duration_ms',
                fn (): array => $this->resolveSnapshot->execute($tenant),
            );
            $this->performanceTracker->setCount('snapshot_hit_count', $snapshot['has_payload'] ? 1 : 0);
            $this->performanceTracker->setCount('snapshot_miss_count', $snapshot['has_payload'] ? 0 : 1);
            $this->performanceTracker->setCount('snapshot_stale_count', $snapshot['is_stale'] ? 1 : 0);
            $this->performanceTracker->setCount('snapshot_failed_count', $snapshot['status'] === 'failed' ? 1 : 0);
            $this->performanceTracker->setCount('snapshot_refreshing_count', $snapshot['status'] === 'refreshing' ? 1 : 0);
            $this->performanceTracker->setMeta('snapshot_status', $snapshot['status']);
            $this->performanceTracker->setMeta('snapshot_generated_at', $snapshot['generated_at_iso']);

            $provisioning = $this->resolveProvisioning($tenant, $snapshot);
            $summary = $this->performanceTracker->measure(
                'summary_mapping_duration_ms',
                fn (): array => $this->mapTenantSummary->execute($tenant, $provisioning),
            );
            $ownerMembership = $tenant->memberships
                ->filter(fn (TenantMembership $membership) => $membership->role === 'owner' && $membership->isActive())
                ->sortByDesc(fn (TenantMembership $membership) => $membership->is_primary)
                ->first();
            $operational = $this->performanceTracker->measure(
                'operational_health_duration_ms',
                fn (): array => $this->resolveOperational($tenant, $snapshot),
            );
            $recentActivity = $this->performanceTracker->measure(
                'recent_activity_duration_ms',
                fn (): array => $this->buildRecentActivity->execute($tenant),
            );
            $stateGovernance = $this->performanceTracker->measure(
                'state_governance_duration_ms',
                fn (): array => $this->buildStateGovernance->execute($tenant, $summary, $operational),
            );
            $suspensionObservability = $this->performanceTracker->measure(
                'suspension_observability_duration_ms',
                fn (): array => $this->resolveSuspensionObservability($tenant, $snapshot),
            );

            $this->performanceTracker->setCount('recent_activity_count', count($recentActivity));
            $this->performanceTracker->setCount('operational_pending_count', (int) data_get($operational, 'summary.pending_count', 0));
            $this->performanceTracker->setCount('suspension_recent_block_count', count(data_get($suspensionObservability, 'recent_blocks', [])));
            $this->performanceTracker->setCount('suspension_affected_channels_count', (int) data_get($suspensionObservability, 'summary.affected_channels_count', 0));
            $this->performanceTracker->setMeta('tenant_status', (string) data_get($summary, 'status.code'));
            $this->performanceTracker->setMeta('tenant_onboarding_stage', (string) data_get($summary, 'onboarding_stage.code'));
            $this->performanceTracker->setMeta('provisioning_code', (string) data_get($summary, 'provisioning.code'));
            $this->performanceTracker->setMeta('suspension_observability_available', (bool) data_get($suspensionObservability, 'availability.available', true));

            return array_merge($summary, [
                'database_name' => $tenant->database_name,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'plan_code' => $tenant->plan_code,
                'activated_at' => $tenant->activated_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
                'domains' => $tenant->domains->map(fn ($domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'type' => $domain->type,
                    'is_primary' => $domain->is_primary,
                    'ssl_status' => $domain->ssl_status,
                    'verified_at' => $domain->verified_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
                ])->values()->all(),
                'owner' => [
                    'name' => $ownerMembership?->user?->name,
                    'email' => $ownerMembership?->user?->email,
                    'role' => $ownerMembership?->role,
                    'accepted_at' => $ownerMembership?->accepted_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
                ],
                'operational' => $operational,
                'recent_activity' => $recentActivity,
                'state_governance' => $stateGovernance,
                'suspension_observability' => $suspensionObservability,
                'snapshot' => $this->buildSnapshotViewMeta($snapshot, [
                    'provisioning' => (string) data_get($provisioning, 'data_source', 'fallback'),
                    'operational' => (string) data_get($operational, 'data_source', 'fallback'),
                    'recent_activity' => 'live',
                    'state_governance' => 'live_derived',
                    'suspension_observability' => (string) data_get($suspensionObservability, 'data_source', 'fallback'),
                ]),
                'snapshot_generated_at' => $snapshot['generated_at'],
                'snapshot_age_seconds' => $snapshot['age_seconds'],
                'snapshot_status' => $snapshot['status'],
                'snapshot_is_stale' => $snapshot['is_stale'],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function resolveProvisioning(Tenant $tenant, array $snapshot): array
    {
        $snapshotProvisioning = data_get($snapshot, 'payload.provisioning');

        if (is_array($snapshotProvisioning) && $snapshotProvisioning !== []) {
            return array_merge($snapshotProvisioning, [
                'data_source' => 'snapshot',
            ]);
        }

        return [
            'code' => sprintf('snapshot_%s', $snapshot['status']),
            'label' => match ($snapshot['status']) {
                'refreshing' => 'Snapshot em atualização',
                'failed' => 'Snapshot indisponível',
                default => 'Snapshot pendente',
            },
            'detail' => $snapshot['detail'],
            'schema_ok' => false,
            'database_exists' => false,
            'owner_ready' => $tenant->memberships->contains(
                fn (TenantMembership $membership) => $membership->role === 'owner' && $membership->isActive()
            ),
            'domain_ready' => $tenant->domains->contains(fn ($domain) => $domain->is_primary),
            'data_source' => 'fallback',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function resolveOperational(Tenant $tenant, array $snapshot): array
    {
        $snapshotOperational = data_get($snapshot, 'payload.operational');

        if (is_array($snapshotOperational) && $snapshotOperational !== []) {
            return array_merge($snapshotOperational, [
                'data_source' => 'snapshot',
            ]);
        }

        $domainReady = $tenant->domains->contains(fn ($domain) => $domain->is_primary);
        $ownerReady = $tenant->memberships->contains(
            fn (TenantMembership $membership) => $membership->role === 'owner' && $membership->isActive()
        );
        $basicData = $this->inspectBasicData($tenant);
        $checks = [
            [
                'key' => 'database',
                'label' => 'Banco do tenant',
                'ok' => false,
                'detail' => $snapshot['detail'],
            ],
            [
                'key' => 'schema',
                'label' => 'Schema mínimo',
                'ok' => false,
                'detail' => $snapshot['detail'],
            ],
            [
                'key' => 'primary_domain',
                'label' => 'Domínio principal',
                'ok' => $domainReady,
                'detail' => $domainReady
                    ? 'Há um domínio principal configurado para o tenant.'
                    : 'Ainda não existe domínio principal configurado.',
            ],
            [
                'key' => 'owner',
                'label' => 'Owner ativo',
                'ok' => $ownerReady,
                'detail' => $ownerReady
                    ? 'Existe um owner ativo vinculado ao tenant.'
                    : 'Ainda não existe owner ativo vinculado ao tenant.',
            ],
            [
                'key' => 'automation_defaults',
                'label' => 'Automações default',
                'ok' => false,
                'detail' => $snapshot['detail'],
            ],
            [
                'key' => 'basic_data',
                'label' => 'Dados básicos mínimos',
                'ok' => $basicData['ok'],
                'detail' => $basicData['detail'],
            ],
        ];

        $okCount = collect($checks)->where('ok', true)->count();
        $totalCount = count($checks);

        return [
            'checks' => $checks,
            'schema_missing_tables' => [],
            'summary' => [
                'ok_count' => $okCount,
                'total_count' => $totalCount,
                'pending_count' => $totalCount - $okCount,
            ],
            'data_source' => 'fallback',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function resolveSuspensionObservability(Tenant $tenant, array $snapshot): array
    {
        $payload = data_get($snapshot, 'payload.suspension_observability');

        if (is_array($payload) && $payload !== []) {
            return array_merge($payload, [
                'data_source' => 'snapshot',
            ]);
        }

        $latestSuspensionAudit = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'landlord_tenant.status_changed')
            ->latest('created_at')
            ->get()
            ->first(fn (AuditLog $auditLog): bool => data_get($auditLog->after_json, 'status') === 'suspended');

        return [
            'availability' => [
                'available' => false,
                'label' => 'Snapshot de hardening indisponível',
                'detail' => $snapshot['detail'],
                'missing_tables' => [],
            ],
            'access_tokens' => [
                'active_count' => UserAccessToken::query()
                    ->where('tenant_id', $tenant->id)
                    ->count(),
                'last_revoked_count' => is_numeric(data_get($latestSuspensionAudit?->metadata_json, 'revoked_access_token_count'))
                    ? (int) data_get($latestSuspensionAudit?->metadata_json, 'revoked_access_token_count')
                    : null,
                'last_revoked_at' => $latestSuspensionAudit?->created_at?->setTimezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            ],
            'summary' => [
                'window_label' => 'Indisponível',
                'total_count' => 0,
                'affected_channels_count' => 0,
                'recurring' => false,
                'recurring_label' => 'A observabilidade de suspensão depende de snapshot administrativo atualizado.',
            ],
            'channels' => collect(BuildLandlordTenantSuspensionObservabilityAction::channelLabels())
                ->map(fn (string $label, string $channel): array => [
                    'channel' => $channel,
                    'label' => $label,
                    'count' => 0,
                    'last_seen_at' => null,
                ])
                ->values()
                ->all(),
            'recent_blocks' => [],
            'webhook_policy' => [
                'status_code' => 202,
                'label' => 'Webhook suspenso reconhecido sem processamento',
                'detail' => 'Webhooks recebidos durante a suspensão retornam 202 e são auditados como ignorados para evitar retries contínuos desnecessários.',
            ],
            'data_source' => 'fallback',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, string>  $sectionSources
     * @return array<string, mixed>
     */
    private function buildSnapshotViewMeta(array $snapshot, array $sectionSources): array
    {
        $statusTone = match ($snapshot['status']) {
            'ready' => 'success',
            'stale', 'refreshing' => 'warning',
            'failed' => 'error',
            default => 'neutral',
        };

        return [
            'status' => $snapshot['status'],
            'label' => $snapshot['label'],
            'detail' => $snapshot['detail'],
            'generated_at' => $snapshot['generated_at'],
            'generated_at_iso' => $snapshot['generated_at_iso'],
            'age_seconds' => $snapshot['age_seconds'],
            'age_label' => $this->ageLabel($snapshot['age_seconds']),
            'is_stale' => $snapshot['is_stale'],
            'stale_after_seconds' => $snapshot['stale_after_seconds'],
            'last_refresh_started_at' => $snapshot['last_refresh_started_at'],
            'last_refresh_completed_at' => $snapshot['last_refresh_completed_at'],
            'last_refresh_failed_at' => $snapshot['last_refresh_failed_at'],
            'last_refresh_error' => $snapshot['last_refresh_error'],
            'status_tone' => $statusTone,
            'section_sources' => $sectionSources,
            'retry' => $snapshot['retry'] ?? [],
        ];
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    private function inspectBasicData(Tenant $tenant): array
    {
        $missingFields = [];

        if (blank($tenant->trade_name)) {
            $missingFields[] = 'nome fantasia';
        }

        if (blank($tenant->legal_name)) {
            $missingFields[] = 'razão social';
        }

        if (blank($tenant->timezone)) {
            $missingFields[] = 'timezone';
        }

        if (blank($tenant->currency)) {
            $missingFields[] = 'moeda';
        }

        if ($missingFields === []) {
            return [
                'ok' => true,
                'detail' => 'Nome fantasia, razão social, timezone e moeda estão preenchidos.',
            ];
        }

        return [
            'ok' => false,
            'detail' => sprintf('Pendências no cadastro básico: %s.', implode(', ', $missingFields)),
        ];
    }

    private function ageLabel(?int $ageSeconds): ?string
    {
        if ($ageSeconds === null) {
            return null;
        }

        return Carbon::now()
            ->subSeconds(max(0, $ageSeconds))
            ->locale('pt_BR')
            ->diffForHumans();
    }
}
