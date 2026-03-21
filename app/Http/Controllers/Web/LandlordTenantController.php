<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Observability\RecordLandlordTenantIndexPerformanceAction;
use App\Application\Actions\Observability\RecordLandlordTenantDetailPerformanceAction;
use App\Application\Actions\Tenancy\AddLandlordTenantDomainAction;
use App\Application\Actions\Tenancy\BuildLandlordDashboardDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantDetailDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantIndexDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantIndexReadContextAction;
use App\Application\Actions\Tenancy\BuildLandlordSnapshotBatchHistoryDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantSnapshotDashboardDataAction;
use App\Application\Actions\Tenancy\BuildTenantProvisioningDataAction;
use App\Application\Actions\Tenancy\ChangeLandlordTenantStatusAction;
use App\Application\Actions\Tenancy\EnsureLandlordTenantDefaultAutomationsAction;
use App\Application\Actions\Tenancy\MarkLandlordTenantDetailSnapshotStaleAction;
use App\Application\Actions\Tenancy\ProvisionTenantFromLandlordPanelAction;
use App\Application\Actions\Tenancy\QueueLandlordTenantSnapshotBatchRefreshAction;
use App\Application\Actions\Tenancy\RefreshLandlordTenantDetailSnapshotAction;
use App\Application\Actions\Tenancy\ResolveLandlordTenantIndexFiltersAction;
use App\Application\Actions\Tenancy\ResolveLandlordTenantSnapshotDashboardFiltersAction;
use App\Application\Actions\Tenancy\RunLandlordTenantSchemaSyncAction;
use App\Application\Actions\Tenancy\SetLandlordTenantPrimaryDomainAction;
use App\Application\Actions\Tenancy\TransitionLandlordTenantOnboardingStageAction;
use App\Application\Actions\Tenancy\UpdateLandlordTenantBasicsAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ChangeLandlordTenantStatusRequest;
use App\Http\Requests\Web\QueueLandlordTenantSnapshotBatchRefreshRequest;
use App\Http\Requests\Web\StoreLandlordTenantDomainRequest;
use App\Http\Requests\Web\StoreLandlordTenantRequest;
use App\Http\Requests\Web\TransitionLandlordTenantOnboardingStageRequest;
use App\Http\Requests\Web\UpdateLandlordTenantBasicsRequest;
use App\Support\Observability\LandlordTenantIndexPerformanceTracker;
use App\Support\Observability\LandlordTenantDetailPerformanceTracker;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class LandlordTenantController extends Controller
{
    public function index(
        Request $request,
        BuildLandlordTenantIndexReadContextAction $buildReadContext,
        BuildLandlordTenantIndexDataAction $buildIndexData,
        BuildLandlordDashboardDataAction $buildDashboardData,
        ResolveLandlordTenantIndexFiltersAction $resolveFilters,
        RecordLandlordTenantIndexPerformanceAction $recordPerformance,
        LandlordTenantIndexPerformanceTracker $performanceTracker,
    ): View {
        $filters = $resolveFilters->execute($request->query());
        $performanceTracker->setMeta('route_name', (string) $request->route()?->getName());
        $performanceTracker->setMeta('path', $request->path());

        try {
            $payload = $performanceTracker->measure('total_duration_ms', function () use (
                $buildReadContext,
                $buildIndexData,
                $buildDashboardData,
                $filters,
                $resolveFilters,
            ): array {
                $readContext = $buildReadContext->execute();

                return [
                    'dashboard' => $buildDashboardData->execute($readContext),
                    'tenants' => $buildIndexData->execute($filters, $readContext),
                    'filters' => $filters,
                    'filterOptions' => $resolveFilters->options(),
                    'hasActiveFilters' => $resolveFilters->hasActiveFilters($filters),
                ];
            });
        } catch (Throwable $throwable) {
            $performanceTracker->recordFailure('landlord.tenants.index.read', $throwable);
            $recordPerformance->execute($request, $filters, $performanceTracker, $throwable);

            throw $throwable;
        }

        $recordPerformance->execute($request, $filters, $performanceTracker);

        return view('landlord.panel.tenants.index', array_merge($payload, [
            'navigation' => [
                'active' => 'tenants',
            ],
        ]));
    }

    public function create(
        BuildTenantProvisioningDataAction $buildProvisioningData,
    ): View {
        return view('landlord.panel.tenants.create', [
            'navigation' => [
                'active' => 'create',
            ],
            'defaults' => [
                'domain_suffix' => $buildProvisioningData->defaultDomainSuffix(),
                'timezone' => (string) config('landlord.tenants.defaults.timezone', 'America/Sao_Paulo'),
                'currency' => (string) config('landlord.tenants.defaults.currency', 'BRL'),
                'plan_code' => (string) config('landlord.tenants.defaults.plan_code', 'starter'),
            ],
        ]);
    }

    public function snapshotDashboard(
        Request $request,
        BuildLandlordTenantSnapshotDashboardDataAction $buildSnapshotDashboard,
        BuildLandlordSnapshotBatchHistoryDataAction $buildBatchHistory,
        ResolveLandlordTenantSnapshotDashboardFiltersAction $resolveFilters,
    ): View {
        $filters = $resolveFilters->execute($request->query());

        return view('landlord.panel.tenants.snapshots', array_merge(
            $buildSnapshotDashboard->execute($filters),
            [
                'filters' => $filters,
                'filterOptions' => $resolveFilters->options(),
                'hasActiveFilters' => $resolveFilters->hasActiveFilters($filters),
                'batchHistory' => $buildBatchHistory->execute(),
                'navigation' => [
                    'active' => 'snapshots',
                ],
            ],
        ));
    }

    public function queueSnapshotBatchRefresh(
        QueueLandlordTenantSnapshotBatchRefreshRequest $request,
        QueueLandlordTenantSnapshotBatchRefreshAction $queueBatchRefresh,
        ResolveLandlordTenantSnapshotDashboardFiltersAction $resolveFilters,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        $filters = $resolveFilters->execute($request->validated());

        try {
            $result = $queueBatchRefresh->execute(
                actor: $actor,
                mode: (string) $request->validated('mode'),
                filters: $filters,
                selectedTenantIds: array_values((array) $request->validated('selected_ids', [])),
            );
        } catch (Throwable $throwable) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $throwable->getMessage(),
            ]);
        }

        return back()->with('status', $this->snapshotBatchRefreshStatusPayload($result));
    }

    public function store(
        StoreLandlordTenantRequest $request,
        ProvisionTenantFromLandlordPanelAction $provisionTenant,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $result = $provisionTenant->execute(
                actor: $actor,
                input: $request->validated(),
            );
        } catch (Throwable $throwable) {
            return back()
                ->withInput()
                ->withErrors([
                    'provisioning' => $throwable->getMessage(),
                ]);
        }

        return redirect()
            ->route('landlord.tenants.index')
            ->with('status', [
                'type' => 'success',
                'message' => sprintf('Tenant "%s" provisionado com sucesso.', $result->tenant->trade_name),
                'tenant' => [
                    'slug' => $result->tenant->slug,
                    'domain' => $result->domain,
                    'owner_email' => $result->owner?->email,
                    'temporary_password' => $result->temporaryPassword,
                    'owner_created' => $result->ownerCreated,
                ],
            ]);
    }

    public function show(
        Request $request,
        Tenant $tenant,
        BuildLandlordTenantDetailDataAction $buildDetailData,
        RecordLandlordTenantDetailPerformanceAction $recordPerformance,
        LandlordTenantDetailPerformanceTracker $performanceTracker,
    ): View {
        $performanceTracker->setMeta('route_name', (string) $request->route()?->getName());
        $performanceTracker->setMeta('path', $request->path());
        $performanceTracker->setMeta('tenant_id', (string) $tenant->getKey());
        $performanceTracker->setMeta('tenant_slug', (string) $tenant->slug);

        try {
            $payload = $performanceTracker->measure('total_duration_ms', fn (): array => $buildDetailData->execute($tenant));
        } catch (Throwable $throwable) {
            $performanceTracker->recordFailure('landlord.tenants.show.read', $throwable, [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
            ]);
            $recordPerformance->execute($request, $tenant, $performanceTracker, $throwable);

            throw $throwable;
        }

        $recordPerformance->execute($request, $tenant, $performanceTracker);

        return view('landlord.panel.tenants.show', [
            'tenant' => $payload,
            'navigation' => [
                'active' => 'tenants',
            ],
        ]);
    }

    public function updateBasics(
        UpdateLandlordTenantBasicsRequest $request,
        Tenant $tenant,
        UpdateLandlordTenantBasicsAction $updateTenantBasics,
        MarkLandlordTenantDetailSnapshotStaleAction $markSnapshotStale,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $result = $updateTenantBasics->execute(
                tenant: $tenant,
                actor: $actor,
                input: $request->validated(),
            );
        } catch (Throwable $throwable) {
            return back()
                ->withInput()
                ->with('status', [
                    'type' => 'error',
                    'message' => $throwable->getMessage(),
                ]);
        }

        $markSnapshotStale->execute($tenant);

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => $result['changed']
                    ? sprintf('Dados básicos do tenant "%s" atualizados com sucesso.', $tenant->fresh()->trade_name)
                    : sprintf('Nenhuma alteração foi aplicada aos dados básicos do tenant "%s".', $tenant->trade_name),
            ]);
    }

    public function changeStatus(
        ChangeLandlordTenantStatusRequest $request,
        Tenant $tenant,
        ChangeLandlordTenantStatusAction $changeTenantStatus,
        MarkLandlordTenantDetailSnapshotStaleAction $markSnapshotStale,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $result = $changeTenantStatus->execute(
                tenant: $tenant,
                actor: $actor,
                input: $request->validated(),
            );
        } catch (Throwable $throwable) {
            return back()
                ->withInput()
                ->withErrors([
                    'status' => $throwable->getMessage(),
                ], 'tenantStatusTransition');
        }

        $markSnapshotStale->execute($tenant);

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => sprintf(
                    'Status do tenant "%s" atualizado para "%s".',
                    $tenant->fresh()->trade_name,
                    mb_strtolower($result['label']),
                ),
            ]);
    }

    public function transitionOnboardingStage(
        TransitionLandlordTenantOnboardingStageRequest $request,
        Tenant $tenant,
        TransitionLandlordTenantOnboardingStageAction $transitionOnboardingStage,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $result = $transitionOnboardingStage->execute(
                tenant: $tenant,
                actor: $actor,
                input: $request->validated(),
            );
        } catch (Throwable $throwable) {
            return back()
                ->withInput()
                ->withErrors([
                    'onboarding_stage' => $throwable->getMessage(),
                ], 'tenantOnboardingTransition');
        }

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => sprintf(
                    'Onboarding do tenant "%s" atualizado para "%s".',
                    $tenant->fresh()->trade_name,
                    mb_strtolower($result['label']),
                ),
            ]);
    }

    public function storeDomain(
        StoreLandlordTenantDomainRequest $request,
        Tenant $tenant,
        AddLandlordTenantDomainAction $addTenantDomain,
        MarkLandlordTenantDetailSnapshotStaleAction $markSnapshotStale,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $result = $addTenantDomain->execute(
                tenant: $tenant,
                actor: $actor,
                input: $request->validated(),
            );
        } catch (Throwable $throwable) {
            return back()
                ->withInput()
                ->with('status', [
                    'type' => 'error',
                    'message' => $throwable->getMessage(),
                ]);
        }

        $markSnapshotStale->execute($tenant);

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => $result['became_primary']
                    ? sprintf('Domínio "%s" adicionado e definido como principal do tenant "%s".', $result['domain']->domain, $tenant->trade_name)
                    : sprintf('Domínio "%s" adicionado ao tenant "%s".', $result['domain']->domain, $tenant->trade_name),
            ]);
    }

    public function setPrimaryDomain(
        Request $request,
        Tenant $tenant,
        TenantDomain $domain,
        SetLandlordTenantPrimaryDomainAction $setPrimaryDomain,
        MarkLandlordTenantDetailSnapshotStaleAction $markSnapshotStale,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);
        abort_unless($domain->tenant_id === $tenant->id, 404);

        try {
            $result = $setPrimaryDomain->execute($tenant, $domain, $actor);
        } catch (Throwable $throwable) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $throwable->getMessage(),
            ]);
        }

        $markSnapshotStale->execute($tenant);

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => $result['changed']
                    ? sprintf('Domínio principal do tenant "%s" atualizado para "%s".', $tenant->trade_name, $domain->domain)
                    : sprintf('O domínio "%s" já era o principal do tenant "%s".', $domain->domain, $tenant->trade_name),
            ]);
    }

    public function syncSchema(
        Request $request,
        Tenant $tenant,
        RunLandlordTenantSchemaSyncAction $runSchemaSync,
        MarkLandlordTenantDetailSnapshotStaleAction $markSnapshotStale,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $runSchemaSync->execute($tenant, $actor);
        } catch (Throwable $throwable) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $throwable->getMessage(),
            ]);
        }

        $markSnapshotStale->execute($tenant);

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => sprintf('Schema do tenant "%s" sincronizado com sucesso.', $tenant->trade_name),
            ]);
    }

    public function ensureDefaultAutomations(
        Request $request,
        Tenant $tenant,
        EnsureLandlordTenantDefaultAutomationsAction $ensureDefaultAutomations,
        MarkLandlordTenantDetailSnapshotStaleAction $markSnapshotStale,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $count = $ensureDefaultAutomations->execute($tenant, $actor);
        } catch (Throwable $throwable) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $throwable->getMessage(),
            ]);
        }

        $markSnapshotStale->execute($tenant);

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => sprintf(
                    'Automações default garantidas para o tenant "%s" (%d automações).',
                    $tenant->trade_name,
                    $count,
                ),
            ]);
    }

    public function refreshDetailSnapshot(
        Request $request,
        Tenant $tenant,
        RefreshLandlordTenantDetailSnapshotAction $refreshSnapshot,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $result = $refreshSnapshot->execute($tenant, 'manual');
        } catch (Throwable $throwable) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $throwable->getMessage(),
            ]);
        }

        if ($result['status'] === 'skipped_locked') {
            return redirect()
                ->route('landlord.tenants.show', $tenant)
                ->with('status', [
                    'type' => 'error',
                    'message' => sprintf('Já existe um refresh de snapshot em andamento para o tenant "%s".', $tenant->trade_name),
                ]);
        }

        return redirect()
            ->route('landlord.tenants.show', $tenant)
            ->with('status', [
                'type' => 'success',
                'message' => sprintf('Snapshot administrativo do tenant "%s" atualizado com sucesso.', $tenant->fresh()->trade_name),
            ]);
    }

    /**
     * @param  array{
     *     result_status:string,
     *     batch_id:string,
     *     mode:string,
     *     mode_label:string,
     *     filters:array<string, string>,
     *     selected_count:int,
     *     matched_count:int,
     *     eligible_count:int,
     *     dispatched_count:int,
     *     skipped_locked_count:int,
     *     skipped_refreshing_count:int,
     *     skipped_healthy_count:int,
     *     skipped_cooldown_count:int,
     *     dispatch_failed_count:int,
     *     duplicate_submission:bool
     * }  $result
     * @return array<string, mixed>
     */
    private function snapshotBatchRefreshStatusPayload(array $result): array
    {
        $modeLabel = mb_strtolower((string) $result['mode_label']);
        $summary = [
            'matched_count' => (int) $result['matched_count'],
            'eligible_count' => (int) $result['eligible_count'],
            'dispatched_count' => (int) $result['dispatched_count'],
            'skipped_locked_count' => (int) $result['skipped_locked_count'],
            'skipped_refreshing_count' => (int) $result['skipped_refreshing_count'],
            'skipped_healthy_count' => (int) $result['skipped_healthy_count'],
            'skipped_cooldown_count' => (int) $result['skipped_cooldown_count'],
            'dispatch_failed_count' => (int) $result['dispatch_failed_count'],
        ];

        if ((bool) $result['duplicate_submission']) {
            return [
                'type' => 'warning',
                'message' => 'Já existe um disparo semelhante de refresh em lote em andamento para este mesmo recorte operacional.',
                'batch' => [
                    'id' => $result['batch_id'],
                    'mode_label' => $result['mode_label'],
                ],
                'summary' => $summary,
            ];
        }

        if ($summary['dispatch_failed_count'] > 0 && $summary['dispatched_count'] === 0) {
            return [
                'type' => 'error',
                'message' => sprintf('O refresh em lote no modo %s falhou antes de enfileirar os tenants elegíveis.', $modeLabel),
                'batch' => [
                    'id' => $result['batch_id'],
                    'mode_label' => $result['mode_label'],
                ],
                'summary' => $summary,
            ];
        }

        if ($summary['dispatched_count'] > 0) {
            return [
                'type' => $result['result_status'] === 'partially_completed' ? 'warning' : 'success',
                'message' => sprintf(
                    '%d refresh(es) de snapshot enfileirado(s) no modo %s.',
                    $summary['dispatched_count'],
                    $modeLabel,
                ),
                'batch' => [
                    'id' => $result['batch_id'],
                    'mode_label' => $result['mode_label'],
                ],
                'summary' => $summary,
            ];
        }

        if ($summary['matched_count'] === 0) {
            return [
                'type' => 'warning',
                'message' => sprintf('Nenhum tenant correspondente foi encontrado para o refresh em lote no modo %s.', $modeLabel),
                'batch' => [
                    'id' => $result['batch_id'],
                    'mode_label' => $result['mode_label'],
                ],
                'summary' => $summary,
            ];
        }

        return [
            'type' => 'warning',
            'message' => sprintf('Nenhum refresh foi enfileirado no modo %s porque os tenants atuais já estavam saudáveis, refreshing, bloqueados ou em cooldown.', $modeLabel),
            'batch' => [
                'id' => $result['batch_id'],
                'mode_label' => $result['mode_label'],
            ],
            'summary' => $summary,
        ];
    }
}
