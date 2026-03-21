<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Tenancy\AddLandlordTenantDomainAction;
use App\Application\Actions\Tenancy\BuildLandlordDashboardDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantDetailDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantIndexDataAction;
use App\Application\Actions\Tenancy\BuildTenantProvisioningDataAction;
use App\Application\Actions\Tenancy\ChangeLandlordTenantStatusAction;
use App\Application\Actions\Tenancy\EnsureLandlordTenantDefaultAutomationsAction;
use App\Application\Actions\Tenancy\ProvisionTenantFromLandlordPanelAction;
use App\Application\Actions\Tenancy\ResolveLandlordTenantIndexFiltersAction;
use App\Application\Actions\Tenancy\RunLandlordTenantSchemaSyncAction;
use App\Application\Actions\Tenancy\SetLandlordTenantPrimaryDomainAction;
use App\Application\Actions\Tenancy\TransitionLandlordTenantOnboardingStageAction;
use App\Application\Actions\Tenancy\UpdateLandlordTenantBasicsAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ChangeLandlordTenantStatusRequest;
use App\Http\Requests\Web\StoreLandlordTenantDomainRequest;
use App\Http\Requests\Web\StoreLandlordTenantRequest;
use App\Http\Requests\Web\TransitionLandlordTenantOnboardingStageRequest;
use App\Http\Requests\Web\UpdateLandlordTenantBasicsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class LandlordTenantController extends Controller
{
    public function index(
        Request $request,
        BuildLandlordTenantIndexDataAction $buildIndexData,
        BuildLandlordDashboardDataAction $buildDashboardData,
        ResolveLandlordTenantIndexFiltersAction $resolveFilters,
    ): View {
        $filters = $resolveFilters->execute($request->query());

        return view('landlord.panel.tenants.index', [
            'dashboard' => $buildDashboardData->execute(),
            'tenants' => $buildIndexData->execute($filters),
            'filters' => $filters,
            'filterOptions' => $resolveFilters->options(),
            'hasActiveFilters' => $resolveFilters->hasActiveFilters($filters),
            'navigation' => [
                'active' => 'tenants',
            ],
        ]);
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
        Tenant $tenant,
        BuildLandlordTenantDetailDataAction $buildDetailData,
    ): View {
        return view('landlord.panel.tenants.show', [
            'tenant' => $buildDetailData->execute($tenant),
            'navigation' => [
                'active' => 'tenants',
            ],
        ]);
    }

    public function updateBasics(
        UpdateLandlordTenantBasicsRequest $request,
        Tenant $tenant,
        UpdateLandlordTenantBasicsAction $updateTenantBasics,
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
}
