<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Tenancy\BuildLandlordTenantIndexDataAction;
use App\Application\Actions\Tenancy\BuildLandlordTenantDetailDataAction;
use App\Application\Actions\Tenancy\BuildTenantProvisioningDataAction;
use App\Application\Actions\Tenancy\EnsureLandlordTenantDefaultAutomationsAction;
use App\Application\Actions\Tenancy\ProvisionTenantFromLandlordPanelAction;
use App\Application\Actions\Tenancy\RunLandlordTenantSchemaSyncAction;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreLandlordTenantRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class LandlordTenantController extends Controller
{
    public function index(
        BuildLandlordTenantIndexDataAction $buildIndexData,
    ): View {
        return view('landlord.panel.tenants.index', [
            'tenants' => $buildIndexData->execute(),
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
