<?php

namespace App\Application\Actions\Agent;

use App\Domain\Agent\Models\AgentInsight;

class ResolveWhatsappAgentInsightAction
{
    public function __construct(
        private readonly RecordWhatsappAgentEventAction $recordEvent,
    ) {
    }

    public function execute(AgentInsight $insight, string $reason = 'manual_resolve'): AgentInsight
    {
        abort_if($insight->status !== 'active', 422, 'Somente insights ativos podem ser resolvidos manualmente.');

        $insight->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
        ])->save();

        if ($insight->run !== null) {
            $this->recordEvent->execute(
                run: $insight->run,
                insight: $insight,
                eventName: 'whatsapp.agent.insight.resolved',
                payload: [
                    'insight_id' => $insight->id,
                    'insight_type' => $insight->type,
                    'resolution_reason' => $reason,
                ],
                result: [
                    'status' => 'resolved',
                ],
                idempotencyKey: sprintf('agent-insight-resolved:%s:%s', $reason, $insight->id),
                occurredAt: now(),
            );
        }

        return $insight->refresh();
    }
}
