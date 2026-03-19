<?php

namespace App\Http\Controllers\Web;

use App\Application\Actions\Agent\ExecuteWhatsappAgentInsightAction;
use App\Application\Actions\Agent\IgnoreWhatsappAgentInsightAction;
use App\Application\Actions\Agent\RecordWhatsappAgentAdminAuditAction;
use App\Application\Actions\Agent\ResolveWhatsappAgentInsightAction;
use App\Domain\Agent\Models\AgentInsight;
use App\Http\Controllers\Controller;
use App\Infrastructure\Agent\WhatsappAgentInsightViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantWhatsappAgentGovernanceController extends Controller
{
    public function resolve(
        Request $request,
        AgentInsight $insight,
        ResolveWhatsappAgentInsightAction $resolveInsight,
        RecordWhatsappAgentAdminAuditAction $recordAudit,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): RedirectResponse {
        $before = $viewFactory->snapshot($insight);
        $insight = $resolveInsight->execute($insight);

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_agent.insight_resolved',
            insight: $insight,
            before: $before,
            after: $viewFactory->snapshot($insight),
        );

        return redirect()->back()->with('status', 'Insight marcado como resolvido.');
    }

    public function ignore(
        Request $request,
        AgentInsight $insight,
        IgnoreWhatsappAgentInsightAction $ignoreInsight,
        RecordWhatsappAgentAdminAuditAction $recordAudit,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): RedirectResponse {
        $before = $viewFactory->snapshot($insight);
        $insight = $ignoreInsight->execute($insight);

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_agent.insight_ignored',
            insight: $insight,
            before: $before,
            after: $viewFactory->snapshot($insight),
        );

        return redirect()->back()->with('status', 'Insight marcado como ignorado.');
    }

    public function execute(
        Request $request,
        AgentInsight $insight,
        ExecuteWhatsappAgentInsightAction $executeInsight,
        RecordWhatsappAgentAdminAuditAction $recordAudit,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): RedirectResponse {
        $before = $viewFactory->snapshot($insight);
        $result = $executeInsight->execute($insight);
        $insight->refresh();

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_agent.recommendation_executed',
            insight: $insight,
            before: $before,
            after: $viewFactory->snapshot($insight),
            metadata: [
                'execution_result' => $result,
            ],
        );

        return redirect()->back()->with('status', 'Ação segura do agente executada com sucesso.');
    }
}
