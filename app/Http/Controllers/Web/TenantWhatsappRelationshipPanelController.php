<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Communication\BuildWhatsappRelationshipPanelDataAction;
use App\Http\Controllers\Controller;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantWhatsappRelationshipPanelController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $tenantContext,
        TenantAuthContext $tenantAuthContext,
        TenantPermissionMatrix $permissionMatrix,
        BuildWhatsappRelationshipPanelDataAction $buildPanelData,
    ): View {
        $tenant = $tenantContext->current();
        $user = $tenantAuthContext->user($request);
        $membership = $tenantAuthContext->membership($request);

        abort_if($tenant === null || $user === null || $membership === null, 404);

        $permissions = [
            'relationship' => [
                'read' => $permissionMatrix->hasAbility($membership, 'appointments.read')
                    || $permissionMatrix->hasAbility($membership, 'clients.read'),
                'write' => $permissionMatrix->hasAbility($membership, 'messages.write'),
            ],
            'operations' => [
                'read' => $permissionMatrix->hasAbility($membership, 'whatsapp.operations.read'),
            ],
            'governance' => [
                'read' => $permissionMatrix->hasAbility($membership, 'whatsapp.automations.read')
                    || $permissionMatrix->hasAbility($membership, 'whatsapp.agent.read'),
            ],
        ];

        abort_unless($permissions['relationship']['read'], 403);

        return view('tenant.panel.whatsapp.relationship', [
            'tenant' => $tenant,
            'user' => $user,
            'membership' => $membership,
            'permissions' => $permissions,
            'panel' => $buildPanelData->execute(
                filters: [
                    'date' => (string) $request->query('date', ''),
                ],
                canTriggerManualMessages: $permissions['relationship']['write'],
            ),
            'navigation' => [
                'relationship_url' => route('tenant.panel.whatsapp.relationship'),
                'operations_url' => route('tenant.panel.whatsapp.operations'),
                'governance_url' => route('tenant.panel.whatsapp.governance'),
                'can_view_relationship' => $permissions['relationship']['read'],
                'can_view_operations' => $permissions['operations']['read'],
                'can_view_governance' => $permissions['governance']['read'],
                'active' => 'relationship',
            ],
        ]);
    }
}
