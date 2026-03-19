<?php

namespace App\Application\Actions\Agent;

use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Automation\Models\Automation;
use RuntimeException;

class ExecuteWhatsappAgentInsightAction
{
    public function __construct(
        private readonly RecordWhatsappAgentEventAction $recordEvent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(AgentInsight $insight): array
    {
        if ($insight->status !== 'active') {
            throw new RuntimeException('Somente insights ativos podem executar uma acao recomendada.');
        }

        if ($insight->execution_mode !== 'manual_safe_action') {
            throw new RuntimeException('Este insight nao permite execucao segura nesta etapa.');
        }

        $action = (string) ($insight->suggested_action ?? '');
        $run = $insight->run()->first();

        if ($run === null) {
            throw new RuntimeException('Insight sem run associado para trilha operacional.');
        }

        if ($action !== 'enable_automation') {
            throw new RuntimeException('A recomendacao informada nao possui executor seguro registrado.');
        }

        $automationId = data_get($insight->action_payload_json, 'automation_id');

        if (! is_string($automationId) || $automationId === '') {
            throw new RuntimeException('Insight sem automacao vinculada para execucao segura.');
        }

        $automation = Automation::query()->findOrFail($automationId);
        $alreadyActive = $automation->status === 'active';

        if (! $alreadyActive) {
            $automation->forceFill([
                'status' => 'active',
            ])->save();
        }

        $result = [
            'action' => 'enable_automation',
            'automation_id' => $automation->id,
            'automation_type' => $automation->trigger_event,
            'already_active' => $alreadyActive,
            'applied_status' => $automation->status,
        ];

        $insight->forceFill([
            'status' => 'executed',
            'executed_at' => now(),
            'execution_result_json' => $result,
        ])->save();

        $this->recordEvent->execute(
            run: $run,
            insight: $insight,
            eventName: 'whatsapp.agent.recommendation.executed',
            payload: array_merge($result, [
                'insight_id' => $insight->id,
                'insight_type' => $insight->type,
            ]),
            result: [
                'status' => 'executed',
            ],
            idempotencyKey: sprintf('agent-insight-executed:%s', $insight->id),
            occurredAt: now(),
        );

        return $result;
    }
}
