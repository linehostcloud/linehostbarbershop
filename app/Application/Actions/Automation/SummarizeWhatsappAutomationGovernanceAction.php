<?php

namespace App\Application\Actions\Automation;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunTarget;

class SummarizeWhatsappAutomationGovernanceAction
{
    /**
     * @param  list<string>  $automationIds
     * @return array<string, array<string, mixed>>
     */
    public function execute(array $automationIds): array
    {
        if ($automationIds === []) {
            return [];
        }

        $runMetrics = AutomationRun::query()
            ->selectRaw('automation_id, count(*) as runs_total, coalesce(sum(messages_queued), 0) as messages_queued_total, coalesce(sum(skipped_total), 0) as skipped_total, coalesce(sum(failed_total), 0) as failed_total')
            ->whereIn('automation_id', $automationIds)
            ->groupBy('automation_id')
            ->get()
            ->keyBy('automation_id');

        $skipReasons = AutomationRunTarget::query()
            ->selectRaw('automation_id, skip_reason, count(*) as total')
            ->whereIn('automation_id', $automationIds)
            ->whereNotNull('skip_reason')
            ->groupBy('automation_id', 'skip_reason')
            ->get()
            ->groupBy('automation_id');

        $metrics = [];

        foreach ($automationIds as $automationId) {
            $aggregate = $runMetrics->get($automationId);
            $reasonItems = collect($skipReasons->get($automationId, collect()))
                ->sortByDesc('total')
                ->map(function (AutomationRunTarget $target): array {
                    return [
                        'reason' => (string) $target->skip_reason,
                        'total' => (int) $target->getAttribute('total'),
                    ];
                })
                ->values();
            $cooldownItem = $reasonItems->firstWhere('reason', 'cooldown_active');

            $metrics[$automationId] = [
                'runs_total' => (int) ($aggregate?->getAttribute('runs_total') ?? 0),
                'messages_queued_total' => (int) ($aggregate?->getAttribute('messages_queued_total') ?? 0),
                'skipped_total' => (int) ($aggregate?->getAttribute('skipped_total') ?? 0),
                'failed_total' => (int) ($aggregate?->getAttribute('failed_total') ?? 0),
                'cooldown_hits_total' => (int) ($cooldownItem['total'] ?? 0),
                'skip_reason_totals' => $reasonItems->take(4)->all(),
            ];
        }

        return $metrics;
    }
}
