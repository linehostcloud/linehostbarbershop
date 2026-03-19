<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Automation\SummarizeWhatsappAutomationGovernanceAction;
use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Http\Controllers\Controller;
use App\Infrastructure\Agent\WhatsappAgentInsightViewFactory;
use App\Infrastructure\Automation\WhatsappAutomationConfigViewFactory;
use App\Infrastructure\Auth\TenantAuthContext;
use App\Infrastructure\Auth\TenantPermissionMatrix;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantWhatsappGovernancePanelController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $tenantContext,
        TenantAuthContext $tenantAuthContext,
        TenantPermissionMatrix $permissionMatrix,
        EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        SummarizeWhatsappAutomationGovernanceAction $summarizeAutomations,
        WhatsappAutomationConfigViewFactory $automationViewFactory,
        WhatsappAgentInsightViewFactory $agentViewFactory,
    ): View {
        $tenant = $tenantContext->current();
        $user = $tenantAuthContext->user($request);
        $membership = $tenantAuthContext->membership($request);

        abort_if($tenant === null || $user === null || $membership === null, 404);

        $permissions = [
            'automations' => [
                'read' => $permissionMatrix->hasAbility($membership, 'whatsapp.automations.read'),
                'write' => $permissionMatrix->hasAbility($membership, 'whatsapp.automations.write'),
            ],
            'agent' => [
                'read' => $permissionMatrix->hasAbility($membership, 'whatsapp.agent.read'),
                'write' => $permissionMatrix->hasAbility($membership, 'whatsapp.agent.write'),
            ],
            'operations' => [
                'read' => $permissionMatrix->hasAbility($membership, 'whatsapp.operations.read'),
            ],
        ];

        abort_unless($permissions['automations']['read'] || $permissions['agent']['read'], 403);

        $filters = [
            'insight_status' => (string) $request->query('insight_status', ''),
            'insight_severity' => (string) $request->query('insight_severity', ''),
            'insight_type' => (string) $request->query('insight_type', ''),
        ];

        $automationItems = [];
        $automationRuns = [];

        if ($permissions['automations']['read']) {
            $ensureDefaults->execute();

            $automations = Automation::query()
                ->where('channel', 'whatsapp')
                ->with('latestRun')
                ->orderBy('priority')
                ->orderBy('trigger_event')
                ->get();
            $metrics = $summarizeAutomations->execute($automations->pluck('id')->all());

            $automationItems = $automations
                ->map(fn (Automation $automation): array => $automationViewFactory->governanceSummary(
                    $automation,
                    $metrics[$automation->id] ?? [],
                    $automation->latestRun,
                ))
                ->values()
                ->all();

            $automationRuns = AutomationRun::query()
                ->where('channel', 'whatsapp')
                ->latest('started_at')
                ->limit(8)
                ->get()
                ->map(fn (AutomationRun $run): array => $automationViewFactory->runSummary($run))
                ->values()
                ->all();
        }

        $agentInsightPaginator = null;
        $agentRuns = [];
        $latestAgentRun = null;

        if ($permissions['agent']['read']) {
            $agentInsightPaginator = AgentInsight::query()
                ->where('channel', 'whatsapp')
                ->when(
                    $filters['insight_status'] !== '',
                    fn (Builder $query): Builder => $query->where('status', $filters['insight_status']),
                )
                ->when(
                    $filters['insight_severity'] !== '',
                    fn (Builder $query): Builder => $query->where('severity', $filters['insight_severity']),
                )
                ->when(
                    $filters['insight_type'] !== '',
                    fn (Builder $query): Builder => $query->where('type', $filters['insight_type']),
                )
                ->orderByRaw("case when status = 'active' then 0 when status = 'executed' then 1 when status = 'ignored' then 2 else 3 end")
                ->orderBy('priority')
                ->latest('last_detected_at')
                ->paginate(8)
                ->withQueryString();

            $agentInsightPaginator->setCollection(
                $agentInsightPaginator->getCollection()->map(function (AgentInsight $insight) use ($agentViewFactory): array {
                    $detail = $agentViewFactory->detail($insight);
                    $detail['can_execute'] = $insight->status === 'active' && $insight->execution_mode === 'manual_safe_action';

                    return $detail;
                }),
            );

            $agentRuns = AgentRun::query()
                ->where('channel', 'whatsapp')
                ->latest('started_at')
                ->limit(8)
                ->get()
                ->map(fn (AgentRun $run): array => $agentViewFactory->runSummary($run))
                ->values()
                ->all();

            $latestAgentRun = $agentRuns[0] ?? null;
        }

        return view('tenant.panel.whatsapp.governance', [
            'tenant' => $tenant,
            'user' => $user,
            'membership' => $membership,
            'permissions' => $permissions,
            'filters' => $filters,
            'automations' => $automationItems,
            'automationRuns' => $automationRuns,
            'agentInsights' => $agentInsightPaginator,
            'agentRuns' => $agentRuns,
            'latestAgentRun' => $latestAgentRun,
            'navigation' => [
                'operations_url' => route('tenant.panel.whatsapp.operations'),
                'governance_url' => route('tenant.panel.whatsapp.governance'),
                'can_view_operations' => $permissions['operations']['read'],
                'can_view_governance' => $permissions['automations']['read'] || $permissions['agent']['read'],
                'active' => 'governance',
            ],
        ]);
    }
}
