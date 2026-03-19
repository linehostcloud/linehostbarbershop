<?php

namespace App\Application\Actions\Automation;

use App\Domain\Client\Models\Client;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;

class SyncClientLifecycleMetricsAction
{
    public function execute(?Client $client, ?\DateTimeInterface $visitedAt = null): void
    {
        if ($client === null) {
            return;
        }

        $visitedAtImmutable = $visitedAt !== null
            ? CarbonImmutable::instance(\Illuminate\Support\Carbon::instance($visitedAt))
            : CarbonImmutable::now();
        $previousLastVisit = $client->last_visit_at !== null
            ? CarbonImmutable::instance($client->last_visit_at)
            : null;
        $closedOrders = Order::query()
            ->where('client_id', $client->id)
            ->where('status', 'closed')
            ->count();
        $visitCount = max((int) $client->visit_count + 1, $closedOrders);
        $intervalDays = $previousLastVisit !== null
            ? max(1, $previousLastVisit->diffInDays($visitedAtImmutable))
            : null;
        $averageVisitInterval = $intervalDays === null
            ? $client->average_visit_interval_days
            : $this->weightedAverage(
                currentAverage: $client->average_visit_interval_days,
                newIntervalDays: $intervalDays,
                intervalsCount: max(1, $visitCount - 1),
            );

        $client->forceFill([
            'visit_count' => $visitCount,
            'last_visit_at' => $visitedAtImmutable,
            'average_visit_interval_days' => $averageVisitInterval,
            'retention_status' => 'active',
            'inactive_since' => null,
        ])->save();
    }

    private function weightedAverage(?int $currentAverage, int $newIntervalDays, int $intervalsCount): int
    {
        if ($intervalsCount <= 1 || $currentAverage === null) {
            return $newIntervalDays;
        }

        $previousIntervals = max(1, $intervalsCount - 1);

        return (int) round((($currentAverage * $previousIntervals) + $newIntervalDays) / $intervalsCount);
    }
}
