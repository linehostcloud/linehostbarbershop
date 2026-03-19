<?php

namespace App\Application\Actions\Agent;

use App\Domain\Agent\Models\AgentInsight;

class IgnoreWhatsappAgentInsightAction
{
    public function __construct(
        private readonly RecordWhatsappAgentEventAction $recordEvent,
    ) {
    }

    public function execute(AgentInsight $insight, string $reason = 'manual_ignore'): AgentInsight
    {
        abort_if($insight->status !== 'active', 422, 'Somente insights ativos podem ser ignorados manualmente.');

        $insight->forceFill([
            'status' => 'ignored',
            'ignored_at' => now(),
        ])->save();

        if ($insight->run !== null) {
            $this->recordEvent->execute(
                run: $insight->run,
                insight: $insight,
                eventName: 'whatsapp.agent.insight.ignored',
                payload: [
                    'insight_id' => $insight->id,
                    'insight_type' => $insight->type,
                    'ignore_reason' => $reason,
                ],
                result: [
                    'status' => 'ignored',
                ],
                idempotencyKey: sprintf('agent-insight-ignored:%s:%s', $reason, $insight->id),
                occurredAt: now(),
            );
        }

        return $insight->refresh();
    }
}
