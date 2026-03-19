<?php

namespace App\Application\Actions\Agent;

use App\Application\Actions\Automation\DiscoverWhatsappAutomationCandidatesAction;
use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\Actions\Communication\CalculateWhatsappProviderHealthAction;
use App\Application\DTOs\OperationalWindow;
use App\Domain\Agent\Enums\WhatsappAgentInsightType;
use App\Domain\Agent\Models\AgentInsight;
use App\Domain\Agent\Models\AgentRun;
use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\EventLog;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class AnalyzeWhatsappOperationsAgentAction
{
    public function __construct(
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaults,
        private readonly DiscoverWhatsappAutomationCandidatesAction $discoverAutomationCandidates,
        private readonly CalculateWhatsappProviderHealthAction $calculateProviderHealth,
        private readonly RecordWhatsappAgentEventAction $recordEvent,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?OperationalWindow $window = null): array
    {
        $window ??= $this->defaultWindow();
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $this->ensureDefaults->execute();

        $run = AgentRun::query()->create([
            'channel' => 'whatsapp',
            'status' => 'running',
            'window_started_at' => $window->startedAt,
            'window_ended_at' => $window->endedAt,
            'started_at' => $now,
            'run_context_json' => [
                'window' => [
                    'label' => $window->label,
                    'started_at' => $window->startedAt->toIso8601String(),
                    'ended_at' => $window->endedAt->toIso8601String(),
                ],
                'tenant_id' => $this->tenantContext->current()?->id,
            ],
        ]);

        $detectedInsightKeys = [];
        $summary = [
            'insights_created' => 0,
            'insights_refreshed' => 0,
            'insights_resolved' => 0,
            'insights_ignored' => 0,
            'safe_actions_executed' => 0,
        ];

        try {
            foreach ($this->providerHealthAlertPayloads($window) as $payload) {
                $result = $this->storeInsight($run, $payload, $now);
                $detectedInsightKeys[] = $payload['insight_key'];
                $summary[$result['counter']]++;
            }

            foreach ($this->automationOpportunityPayloads($now) as $payload) {
                $result = $this->storeInsight($run, $payload, $now);
                $detectedInsightKeys[] = $payload['insight_key'];
                $summary[$result['counter']]++;
            }

            foreach ($this->duplicateRiskAlertPayloads($window) as $payload) {
                $result = $this->storeInsight($run, $payload, $now);
                $detectedInsightKeys[] = $payload['insight_key'];
                $summary[$result['counter']]++;
            }

            foreach ($this->deliveryInstabilityAlertPayloads($window) as $payload) {
                $result = $this->storeInsight($run, $payload, $now);
                $detectedInsightKeys[] = $payload['insight_key'];
                $summary[$result['counter']]++;
            }

            $resolved = $this->resolveStaleInsights($run, $detectedInsightKeys, $now);
            $summary['insights_resolved'] += $resolved;

            $activeInsightsTotal = AgentInsight::query()
                ->where('channel', 'whatsapp')
                ->where('status', 'active')
                ->count();

            $run->forceFill([
                'status' => 'completed',
                'insights_created' => $summary['insights_created'],
                'insights_refreshed' => $summary['insights_refreshed'],
                'insights_resolved' => $summary['insights_resolved'],
                'insights_ignored' => $summary['insights_ignored'],
                'safe_actions_executed' => 0,
                'completed_at' => now(),
                'result_json' => array_merge($summary, [
                    'active_insights_total' => $activeInsightsTotal,
                ]),
                'failure_reason' => null,
            ])->save();

            $this->recordEvent->execute(
                run: $run,
                eventName: 'whatsapp.agent.run.completed',
                payload: [
                    'agent_run_id' => $run->id,
                    'window' => [
                        'label' => $window->label,
                        'started_at' => $window->startedAt->toIso8601String(),
                        'ended_at' => $window->endedAt->toIso8601String(),
                    ],
                    ...$summary,
                    'active_insights_total' => $activeInsightsTotal,
                ],
                result: [
                    'status' => 'completed',
                ],
                idempotencyKey: sprintf('agent-run-completed:%s', $run->id),
                occurredAt: now(),
            );

            return [
                'agent_run_id' => $run->id,
                ...$summary,
                'active_insights_total' => $activeInsightsTotal,
            ];
        } catch (Throwable $throwable) {
            $run->forceFill([
                'status' => 'failed',
                'failure_reason' => $throwable->getMessage(),
                'completed_at' => now(),
                'result_json' => $summary,
            ])->save();

            $this->recordEvent->execute(
                run: $run,
                eventName: 'whatsapp.agent.run.failed',
                payload: [
                    'agent_run_id' => $run->id,
                    'error_message' => $throwable->getMessage(),
                    ...$summary,
                ],
                result: [
                    'status' => 'failed',
                ],
                idempotencyKey: sprintf('agent-run-failed:%s', $run->id),
                occurredAt: now(),
            );

            throw $throwable;
        }
    }

    private function defaultWindow(): OperationalWindow
    {
        $timezone = config('app.timezone', 'UTC');
        $endedAt = CarbonImmutable::now($timezone);
        $minutes = max(5, (int) config('communication.whatsapp.agent.window_minutes', 120));

        return new OperationalWindow(
            label: sprintf('%dm', $minutes),
            startedAt: $endedAt->subMinutes($minutes),
            endedAt: $endedAt,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function providerHealthAlertPayloads(OperationalWindow $window): array
    {
        $payloads = [];
        $signalThreshold = max(1, (int) config('communication.whatsapp.agent.provider_signal_alert_threshold', 2));

        foreach (WhatsappProviderConfig::query()->where('slot', 'primary')->get() as $configuration) {
            $snapshot = $this->calculateProviderHealth->execute($configuration, $window);
            $pressureSignals = $snapshot->timeoutRecent + $snapshot->rateLimitRecent + $snapshot->unavailableRecent + $snapshot->transientRecent;

            if (! in_array($snapshot->stateLabel, ['degraded', 'unstable', 'unavailable'], true) && $pressureSignals < $signalThreshold && $snapshot->fallbacksRecent < $signalThreshold) {
                continue;
            }

            $severity = match ($snapshot->stateLabel) {
                'unavailable', 'unstable' => 'high',
                default => 'medium',
            };

            $payloads[] = [
                'insight_key' => sprintf('provider_health_alert:%s:%s', $configuration->slot, $configuration->provider),
                'type' => WhatsappAgentInsightType::ProviderHealthAlert->value,
                'recommendation_type' => 'review_primary_provider',
                'severity' => $severity,
                'priority' => $severity === 'high' ? 10 : 20,
                'title' => sprintf('Provider principal %s em estado %s', $configuration->provider, $snapshot->stateLabel),
                'summary' => $snapshot->stateReason,
                'target_type' => 'provider_config',
                'target_id' => $configuration->id,
                'target_label' => sprintf('%s/%s', $configuration->slot, $configuration->provider),
                'provider' => $configuration->provider,
                'slot' => $configuration->slot,
                'automation_id' => null,
                'evidence_json' => [
                    'source_signals' => ['provider_health', 'integration_attempts', 'fallback_events'],
                    'health_window' => [
                        'label' => $window->label,
                        'started_at' => $window->startedAt->toIso8601String(),
                        'ended_at' => $window->endedAt->toIso8601String(),
                    ],
                    'operational_state' => $snapshot->stateLabel,
                    'successes_recent' => $snapshot->successesRecent,
                    'failures_recent' => $snapshot->failuresRecent,
                    'retries_recent' => $snapshot->retriesRecent,
                    'fallbacks_recent' => $snapshot->fallbacksRecent,
                    'timeout_recent' => $snapshot->timeoutRecent,
                    'rate_limit_recent' => $snapshot->rateLimitRecent,
                    'unavailable_recent' => $snapshot->unavailableRecent,
                    'transient_recent' => $snapshot->transientRecent,
                    'top_error_codes' => $snapshot->topErrorCodes,
                ],
                'suggested_action' => 'review_primary_provider',
                'action_payload_json' => [
                    'provider' => $configuration->provider,
                    'slot' => $configuration->slot,
                    'state' => $snapshot->stateLabel,
                ],
                'execution_mode' => 'recommend_only',
            ];
        }

        return $payloads;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function automationOpportunityPayloads(CarbonImmutable $now): array
    {
        $payloads = [];
        $staleDays = max(1, (int) config('communication.whatsapp.agent.automation_stale_days', 7));
        $thresholds = [
            WhatsappAutomationType::AppointmentReminder->value => max(1, (int) config('communication.whatsapp.agent.reminder_opportunity_min_candidates', 2)),
            WhatsappAutomationType::InactiveClientReactivation->value => max(1, (int) config('communication.whatsapp.agent.reactivation_opportunity_min_candidates', 3)),
        ];

        foreach (Automation::query()->where('channel', 'whatsapp')->whereIn('trigger_event', WhatsappAutomationType::values())->get() as $automation) {
            $threshold = $thresholds[(string) $automation->trigger_event] ?? 1;
            $summary = $this->discoverAutomationCandidates->summarize($automation, $now, $threshold);

            if ($summary['eligible_total'] < $threshold) {
                continue;
            }

            $inactive = $automation->status !== 'active';
            $stale = $automation->last_executed_at === null || $automation->last_executed_at->lt($now->subDays($staleDays));

            if (! $inactive && ! $stale) {
                continue;
            }

            $type = (string) $automation->trigger_event;
            $insightType = $type === WhatsappAutomationType::InactiveClientReactivation->value
                ? WhatsappAgentInsightType::AutomationOpportunityReactivation
                : WhatsappAgentInsightType::AutomationOpportunityReminder;
            $recommendationType = $inactive ? 'enable_automation' : 'review_automation_configuration';
            $executionMode = $inactive ? 'manual_safe_action' : 'recommend_only';
            $title = $type === WhatsappAutomationType::InactiveClientReactivation->value
                ? 'Oportunidade de reativacao detectada'
                : 'Oportunidade de lembrete detectada';
            $summaryText = $inactive
                ? sprintf(
                    'Existem pelo menos %d candidatos elegiveis e a automacao %s esta desativada.',
                    $summary['eligible_total'],
                    $automation->trigger_event,
                )
                : sprintf(
                    'Existem pelo menos %d candidatos elegiveis e a automacao %s esta sem execucao recente.',
                    $summary['eligible_total'],
                    $automation->trigger_event,
                );

            $payloads[] = [
                'insight_key' => sprintf('%s:%s', $insightType->value, $automation->trigger_event),
                'type' => $insightType->value,
                'recommendation_type' => $recommendationType,
                'severity' => $inactive ? 'medium' : 'low',
                'priority' => $inactive ? 30 : 40,
                'title' => $title,
                'summary' => $summaryText,
                'target_type' => 'automation',
                'target_id' => $automation->id,
                'target_label' => $automation->name,
                'provider' => null,
                'slot' => null,
                'automation_id' => $automation->id,
                'evidence_json' => [
                    'source_signals' => ['automation_candidates', 'automation_configuration'],
                    'automation_type' => $automation->trigger_event,
                    'automation_status' => $automation->status,
                    'eligible_candidates_at_least' => $summary['eligible_total'],
                    'skip_reasons' => $summary['skip_reasons'],
                    'last_executed_at' => $automation->last_executed_at?->toIso8601String(),
                ],
                'suggested_action' => $recommendationType,
                'action_payload_json' => [
                    'automation_id' => $automation->id,
                    'automation_type' => $automation->trigger_event,
                    'target_status' => 'active',
                ],
                'execution_mode' => $executionMode,
            ];
        }

        return $payloads;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function duplicateRiskAlertPayloads(OperationalWindow $window): array
    {
        $threshold = max(1, (int) config('communication.whatsapp.agent.duplicate_risk_alert_threshold', 2));
        $query = EventLog::query()
            ->where('event_name', 'whatsapp.message.duplicate_risk_detected')
            ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt]);
        $total = (clone $query)->count();

        if ($total < $threshold) {
            return [];
        }

        $codes = (clone $query)
            ->whereNotNull('payload_json->risk_error_code')
            ->selectRaw("json_extract(payload_json, '$.risk_error_code') as risk_error_code, COUNT(*) as total")
            ->groupBy('risk_error_code')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'code' => trim((string) $row->risk_error_code, '"'),
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        return [[
            'insight_key' => 'duplicate_risk_alert:tenant',
            'type' => WhatsappAgentInsightType::DuplicateRiskAlert->value,
            'recommendation_type' => 'review_duplicate_risk',
            'severity' => $total >= ($threshold * 2) ? 'high' : 'medium',
            'priority' => 20,
            'title' => 'Risco de duplicidade acima do limiar recente',
            'summary' => sprintf('%d eventos de duplicate_risk_detected apareceram na janela operacional.', $total),
            'target_type' => 'tenant',
            'target_id' => (string) $this->tenantContext->current()?->id,
            'target_label' => $this->tenantContext->current()?->trade_name,
            'provider' => null,
            'slot' => null,
            'automation_id' => null,
            'evidence_json' => [
                'source_signals' => ['duplicate_risk_detected'],
                'total' => $total,
                'top_error_codes' => $codes,
                'window' => [
                    'label' => $window->label,
                    'started_at' => $window->startedAt->toIso8601String(),
                    'ended_at' => $window->endedAt->toIso8601String(),
                ],
            ],
            'suggested_action' => 'review_duplicate_risk',
            'action_payload_json' => [
                'window' => $window->label,
            ],
            'execution_mode' => 'recommend_only',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deliveryInstabilityAlertPayloads(OperationalWindow $window): array
    {
        $attempts = IntegrationAttempt::query()
            ->where('channel', 'whatsapp')
            ->where('operation', 'send_message')
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$window->startedAt, $window->endedAt]);

        $statusTotals = (clone $attempts)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
        $total = array_sum($statusTotals);
        $issues = (int) ($statusTotals['failed'] ?? 0) + (int) ($statusTotals['retry_scheduled'] ?? 0) + (int) ($statusTotals['fallback_scheduled'] ?? 0);
        $issueThreshold = max(1, (int) config('communication.whatsapp.agent.delivery_instability_issue_threshold', 4));
        $minimumAttempts = max(1, (int) config('communication.whatsapp.agent.delivery_instability_min_attempts', 5));
        $failureRateThreshold = max(1.0, (float) config('communication.whatsapp.agent.delivery_instability_failure_rate', 25));
        $failureRate = $total > 0 ? round(($issues / $total) * 100, 2) : 0.0;

        if ($total < $minimumAttempts || ($issues < $issueThreshold && $failureRate < $failureRateThreshold)) {
            return [];
        }

        $providers = (clone $attempts)
            ->selectRaw('provider, COUNT(*) as total')
            ->groupBy('provider')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'provider' => (string) $row->provider,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        return [[
            'insight_key' => 'delivery_instability_alert:tenant',
            'type' => WhatsappAgentInsightType::DeliveryInstabilityAlert->value,
            'recommendation_type' => 'review_delivery_instability',
            'severity' => $failureRate >= ($failureRateThreshold * 1.5) ? 'high' : 'medium',
            'priority' => 15,
            'title' => 'Instabilidade operacional recente no delivery WhatsApp',
            'summary' => sprintf('%d eventos de falha, retry ou fallback em %d tentativas recentes.', $issues, $total),
            'target_type' => 'tenant',
            'target_id' => (string) $this->tenantContext->current()?->id,
            'target_label' => $this->tenantContext->current()?->trade_name,
            'provider' => null,
            'slot' => null,
            'automation_id' => null,
            'evidence_json' => [
                'source_signals' => ['integration_attempts', 'fallbacks', 'retries'],
                'total_attempts' => $total,
                'issue_total' => $issues,
                'failure_rate' => $failureRate,
                'status_totals' => $statusTotals,
                'provider_totals' => $providers,
                'window' => [
                    'label' => $window->label,
                    'started_at' => $window->startedAt->toIso8601String(),
                    'ended_at' => $window->endedAt->toIso8601String(),
                ],
            ],
            'suggested_action' => 'review_delivery_instability',
            'action_payload_json' => [
                'window' => $window->label,
            ],
            'execution_mode' => 'recommend_only',
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{counter:string,insight:AgentInsight}
     */
    private function storeInsight(AgentRun $run, array $payload, CarbonImmutable $now): array
    {
        $activeInsight = AgentInsight::query()
            ->where('channel', 'whatsapp')
            ->where('insight_key', $payload['insight_key'])
            ->where('status', 'active')
            ->first();

        if ($activeInsight !== null) {
            $activeInsight->forceFill([
                'agent_run_id' => $run->id,
                'recommendation_type' => $payload['recommendation_type'],
                'severity' => $payload['severity'],
                'priority' => $payload['priority'],
                'title' => $payload['title'],
                'summary' => $payload['summary'],
                'target_type' => $payload['target_type'],
                'target_id' => $payload['target_id'],
                'target_label' => $payload['target_label'],
                'provider' => $payload['provider'],
                'slot' => $payload['slot'],
                'automation_id' => $payload['automation_id'],
                'evidence_json' => $payload['evidence_json'],
                'suggested_action' => $payload['suggested_action'],
                'action_payload_json' => $payload['action_payload_json'],
                'execution_mode' => $payload['execution_mode'],
                'last_detected_at' => $now,
            ])->save();

            return [
                'counter' => 'insights_refreshed',
                'insight' => $activeInsight,
            ];
        }

        $ignoredInsight = AgentInsight::query()
            ->where('channel', 'whatsapp')
            ->where('insight_key', $payload['insight_key'])
            ->where('status', 'ignored')
            ->latest('ignored_at')
            ->first();

        $reopenHours = max(1, (int) config('communication.whatsapp.agent.ignored_reopen_hours', 24));

        if ($ignoredInsight !== null && $ignoredInsight->ignored_at !== null && $ignoredInsight->ignored_at->gt($now->subHours($reopenHours))) {
            $ignoredInsight->forceFill([
                'agent_run_id' => $run->id,
                'last_detected_at' => $now,
                'evidence_json' => $payload['evidence_json'],
            ])->save();

            return [
                'counter' => 'insights_ignored',
                'insight' => $ignoredInsight,
            ];
        }

        $insight = AgentInsight::query()->create([
            'agent_run_id' => $run->id,
            'channel' => 'whatsapp',
            'insight_key' => $payload['insight_key'],
            'type' => $payload['type'],
            'recommendation_type' => $payload['recommendation_type'],
            'status' => 'active',
            'severity' => $payload['severity'],
            'priority' => $payload['priority'],
            'title' => $payload['title'],
            'summary' => $payload['summary'],
            'target_type' => $payload['target_type'],
            'target_id' => $payload['target_id'],
            'target_label' => $payload['target_label'],
            'provider' => $payload['provider'],
            'slot' => $payload['slot'],
            'automation_id' => $payload['automation_id'],
            'evidence_json' => $payload['evidence_json'],
            'suggested_action' => $payload['suggested_action'],
            'action_payload_json' => $payload['action_payload_json'],
            'execution_mode' => $payload['execution_mode'],
            'first_detected_at' => $now,
            'last_detected_at' => $now,
        ]);

        $this->recordEvent->execute(
            run: $run,
            insight: $insight,
            eventName: 'whatsapp.agent.insight.created',
            payload: [
                'insight_id' => $insight->id,
                'insight_key' => $insight->insight_key,
                'insight_type' => $insight->type,
                'recommendation_type' => $insight->recommendation_type,
                'severity' => $insight->severity,
                'title' => $insight->title,
                'summary' => $insight->summary,
            ],
            result: [
                'status' => 'active',
            ],
            idempotencyKey: sprintf('agent-insight-created:%s', $insight->id),
            occurredAt: now(),
        );

        return [
            'counter' => 'insights_created',
            'insight' => $insight,
        ];
    }

    /**
     * @param  list<string>  $detectedInsightKeys
     */
    private function resolveStaleInsights(AgentRun $run, array $detectedInsightKeys, CarbonImmutable $now): int
    {
        $resolved = 0;

        $query = AgentInsight::query()
            ->where('channel', 'whatsapp')
            ->where('status', 'active');

        if ($detectedInsightKeys !== []) {
            $query->whereNotIn('insight_key', $detectedInsightKeys);
        }

        foreach ($query->get() as $insight) {
            $insight->forceFill([
                'status' => 'resolved',
                'resolved_at' => $now,
                'last_detected_at' => $now,
            ])->save();

            $this->recordEvent->execute(
                run: $run,
                insight: $insight,
                eventName: 'whatsapp.agent.insight.resolved',
                payload: [
                    'insight_id' => $insight->id,
                    'insight_type' => $insight->type,
                    'resolution_reason' => 'condition_cleared',
                ],
                result: [
                    'status' => 'resolved',
                ],
                idempotencyKey: sprintf('agent-insight-resolved:%s:%s', $run->id, $insight->id),
                occurredAt: now(),
            );

            $resolved++;
        }

        return $resolved;
    }
}
