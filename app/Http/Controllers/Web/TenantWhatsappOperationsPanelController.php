<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantWhatsappOperationsPanelController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $tenantContext,
        TenantAuthContext $tenantAuthContext,
        TenantPermissionMatrix $permissionMatrix,
    ): View {
        $tenant = $tenantContext->current();
        $user = $tenantAuthContext->user($request);
        $membership = $tenantAuthContext->membership($request);

        abort_if($tenant === null || $user === null || $membership === null, 404);
        $canViewGovernance = $permissionMatrix->hasAbility($membership, 'whatsapp.automations.read')
            || $permissionMatrix->hasAbility($membership, 'whatsapp.agent.read');

        return view('tenant.panel.whatsapp.operations', [
            'tenant' => $tenant,
            'user' => $user,
            'membership' => $membership,
            'navigation' => [
                'operations_url' => route('tenant.panel.whatsapp.operations'),
                'governance_url' => route('tenant.panel.whatsapp.governance'),
                'can_view_operations' => true,
                'can_view_governance' => $canViewGovernance,
                'active' => 'operations',
            ],
            'boot' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'trade_name' => $tenant->trade_name,
                    'domain' => $tenant->domains()->value('domain'),
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'membership' => [
                    'id' => $membership->id,
                    'role' => $membership->role,
                ],
                'filters' => [
                    'window' => (string) $request->query('window', config('observability.whatsapp_operations.default_window', '24h')),
                    'provider' => (string) $request->query('provider', ''),
                    'queue_provider' => (string) $request->query('queue_provider', ''),
                    'queue_status' => (string) $request->query('queue_status', ''),
                    'queue_error_code' => (string) $request->query('queue_error_code', ''),
                    'feed_type' => (string) $request->query('feed_type', ''),
                    'feed_source' => (string) $request->query('feed_source', ''),
                    'auto_refresh' => filter_var($request->query('auto_refresh', false), FILTER_VALIDATE_BOOL),
                    'queue_page' => max(1, (int) $request->query('queue_page', 1)),
                    'boundary_page' => max(1, (int) $request->query('boundary_page', 1)),
                    'feed_page' => max(1, (int) $request->query('feed_page', 1)),
                ],
                'urls' => [
                    'summary' => '/api/v1/operations/whatsapp/summary',
                    'providers' => '/api/v1/operations/whatsapp/providers',
                    'agent' => '/api/v1/operations/whatsapp/agent',
                    'queue' => '/api/v1/operations/whatsapp/queue',
                    'boundary_summary' => '/api/v1/operations/whatsapp/boundary-rejections/summary',
                    'boundary_rejections' => '/api/v1/operations/whatsapp/boundary-rejections',
                    'feed' => '/api/v1/operations/whatsapp/feed',
                    'logout' => route('tenant.panel.whatsapp.operations.logout'),
                ],
            ],
        ]);
    }
}
