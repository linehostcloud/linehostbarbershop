<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Agent\ExecuteWhatsappAgentInsightAction;
use App\Application\Actions\Agent\RecordWhatsappAgentAdminAuditAction;
use App\Application\Actions\Agent\RecordWhatsappAgentEventAction;
use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Http\Controllers\Controller;
use App\Infrastructure\Agent\WhatsappAgentInsightViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsappAgentController extends Controller
{
    public function index(
        Request $request,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'severity' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = AgentInsight::query()
            ->where('channel', 'whatsapp')
            ->when(
                isset($validated['status']) && $validated['status'] !== '',
                fn (Builder $query): Builder => $query->where('status', $validated['status']),
            )
            ->when(
                isset($validated['severity']) && $validated['severity'] !== '',
                fn (Builder $query): Builder => $query->where('severity', $validated['severity']),
            )
            ->when(
                isset($validated['type']) && $validated['type'] !== '',
                fn (Builder $query): Builder => $query->where('type', $validated['type']),
            )
            ->orderByRaw("case when status = 'active' then 0 when status = 'executed' then 1 when status = 'ignored' then 2 else 3 end")
            ->orderBy('priority')
            ->latest('last_detected_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (AgentInsight $insight): array => $viewFactory->detail($insight))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function latestRun(WhatsappAgentInsightViewFactory $viewFactory): JsonResponse
    {
        $run = AgentRun::query()
            ->where('channel', 'whatsapp')
            ->latest('started_at')
            ->first();

        return response()->json([
            'data' => $run !== null ? $viewFactory->runSummary($run) : null,
        ]);
    }

    public function resolve(
        Request $request,
        AgentInsight $insight,
        RecordWhatsappAgentAdminAuditAction $recordAudit,
        RecordWhatsappAgentEventAction $recordEvent,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): JsonResponse {
        abort_if($insight->status !== 'active', 422, 'Somente insights ativos podem ser resolvidos manualmente.');

        $before = $viewFactory->snapshot($insight);

        $insight->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
        ])->save();

        if ($insight->run !== null) {
            $recordEvent->execute(
                run: $insight->run,
                insight: $insight,
                eventName: 'whatsapp.agent.insight.resolved',
                payload: [
                    'insight_id' => $insight->id,
                    'insight_type' => $insight->type,
                    'resolution_reason' => 'manual_resolve',
                ],
                result: [
                    'status' => 'resolved',
                ],
                idempotencyKey: sprintf('agent-insight-resolved-manual:%s', $insight->id),
                occurredAt: now(),
            );
        }

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_agent.insight_resolved',
            insight: $insight,
            before: $before,
            after: $viewFactory->snapshot($insight),
        );

        return response()->json([
            'data' => $viewFactory->detail($insight),
        ]);
    }

    public function ignore(
        Request $request,
        AgentInsight $insight,
        RecordWhatsappAgentAdminAuditAction $recordAudit,
        RecordWhatsappAgentEventAction $recordEvent,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): JsonResponse {
        abort_if($insight->status !== 'active', 422, 'Somente insights ativos podem ser ignorados manualmente.');

        $before = $viewFactory->snapshot($insight);

        $insight->forceFill([
            'status' => 'ignored',
            'ignored_at' => now(),
        ])->save();

        if ($insight->run !== null) {
            $recordEvent->execute(
                run: $insight->run,
                insight: $insight,
                eventName: 'whatsapp.agent.insight.ignored',
                payload: [
                    'insight_id' => $insight->id,
                    'insight_type' => $insight->type,
                    'ignore_reason' => 'manual_ignore',
                ],
                result: [
                    'status' => 'ignored',
                ],
                idempotencyKey: sprintf('agent-insight-ignored-manual:%s', $insight->id),
                occurredAt: now(),
            );
        }

        $recordAudit->execute(
            request: $request,
            action: 'whatsapp_agent.insight_ignored',
            insight: $insight,
            before: $before,
            after: $viewFactory->snapshot($insight),
        );

        return response()->json([
            'data' => $viewFactory->detail($insight),
        ]);
    }

    public function execute(
        Request $request,
        AgentInsight $insight,
        ExecuteWhatsappAgentInsightAction $executeInsight,
        RecordWhatsappAgentAdminAuditAction $recordAudit,
        WhatsappAgentInsightViewFactory $viewFactory,
    ): JsonResponse {
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

        return response()->json([
            'data' => $viewFactory->detail($insight),
        ]);
    }
}
