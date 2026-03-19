<?php

namespace App\Http\Controllers\Api;

use App\Application\Actions\Communication\CalculateWhatsappProviderHealthAction;
use App\Domain\Auth\Models\AuditLog;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Integration\Models\IntegrationAttempt;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\EventLog;
use App\Domain\Observability\Models\OutboxEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WhatsappOperationalQueryRequest;
use App\Infrastructure\Observability\WhatsappOperationsViewFactory;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class WhatsappOperationsController extends Controller
{
    public function summary(
        WhatsappOperationalQueryRequest $request,
        TenantContext $tenantContext,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($tenantContext);
        $window = $request->window();
        $providerFilter = $this->resolvedProviderFilter($request);

        $messagesQuery = Message::query()
            ->where('channel', 'whatsapp')
            ->whereBetween('updated_at', [$window->startedAt, $window->endedAt]);

        if ($providerFilter !== null) {
            $messagesQuery->where('provider', $providerFilter);
        }

        $outboxQuery = OutboxEvent::query()
            ->where('event_name', 'like', 'whatsapp.%')
            ->whereBetween('updated_at', [$window->startedAt, $window->endedAt]);

        if ($providerFilter !== null) {
            $outboxQuery->whereHas('message', fn (Builder $query): Builder => $query->where('provider', $providerFilter));
        }

        $attemptsQuery = IntegrationAttempt::query()
            ->where('channel', 'whatsapp')
            ->whereBetween('created_at', [$window->startedAt, $window->endedAt]);

        if ($providerFilter !== null) {
            $attemptsQuery->where('provider', $providerFilter);
        }

        $boundaryQuery = $this->baseBoundaryQuery($request, $tenantId, $providerFilter);
        $boundaryQuery->whereBetween('occurred_at', [$window->startedAt, $window->endedAt]);

        $messageStatusTotals = $this->countPairs(
            $this->groupedCounts($messagesQuery, 'status'),
            'status',
        );
        $outboxStatusTotals = $this->countPairs(
            $this->groupedCounts($outboxQuery, 'status'),
            'status',
        );
        $attemptStatusTotals = $this->countPairs(
            $this->groupedCounts($attemptsQuery, 'status'),
            'status',
        );
        $attemptErrorTotals = $this->countPairs(
            $this->groupedCounts((clone $attemptsQuery)->whereNotNull('normalized_error_code'), 'normalized_error_code'),
            'error_code',
        );
        $boundaryCodeTotals = $this->countPairs(
            $this->groupedCounts($boundaryQuery, 'code'),
            'code',
        );
        $messageStatusMap = $this->pairsToMap($messageStatusTotals, 'status');
        $outboxStatusMap = $this->pairsToMap($outboxStatusTotals, 'status');
        $attemptStatusMap = $this->pairsToMap($attemptStatusTotals, 'status');
        $fallbackEventMetrics = $this->fallbackEventMetrics($window, $providerFilter);
        $duplicateEventMetrics = $this->duplicateEventMetrics($window, $providerFilter);
        $attemptTotal = array_sum(array_values($attemptStatusMap));
        $operationalFailuresTotal = $this->sumBuckets($attemptStatusMap, ['failed', 'retry_scheduled', 'fallback_scheduled']);
        $pendingQueueTotal = $this->sumBuckets($outboxStatusMap, ['pending', 'processing', 'retry_scheduled']);
        $boundaryTotal = array_sum(array_column($boundaryCodeTotals, 'total'));

        return response()->json([
            'data' => [
                'window' => $this->windowPayload($window),
                'filters' => $this->filtersPayload($request, $providerFilter),
                'operational_cards' => [
                    'messages_recent_total' => array_sum(array_values($messageStatusMap)),
                    'attempts_recent_total' => $attemptTotal,
                    'operational_failures_total' => $operationalFailuresTotal,
                    'retry_scheduled_total' => (int) ($attemptStatusMap['retry_scheduled'] ?? 0),
                    'fallback_scheduled_total' => (int) ($attemptStatusMap['fallback_scheduled'] ?? 0),
                    'fallback_executed_total' => (int) ($fallbackEventMetrics['totals']['executed'] ?? 0),
                    'duplicate_prevented_total' => (int) ($attemptStatusMap['duplicate_prevented'] ?? 0),
                    'duplicate_risk_total' => (int) ($duplicateEventMetrics['totals']['risk_detected'] ?? 0),
                    'boundary_rejections_total' => $boundaryTotal,
                    'pending_queue_total' => $pendingQueueTotal,
                    'operational_failure_rate' => $this->percentage($operationalFailuresTotal, $attemptTotal),
                ],
                'messages' => [
                    'total' => array_sum(array_column($messageStatusTotals, 'total')),
                    'status_totals' => $messageStatusTotals,
                ],
                'outbox_events' => [
                    'total' => array_sum(array_column($outboxStatusTotals, 'total')),
                    'status_totals' => $outboxStatusTotals,
                ],
                'integration_attempts' => [
                    'total' => array_sum(array_column($attemptStatusTotals, 'total')),
                    'status_totals' => $attemptStatusTotals,
                    'error_code_totals' => $attemptErrorTotals,
                ],
                'boundary_rejections' => [
                    'total' => array_sum(array_column($boundaryCodeTotals, 'total')),
                    'code_totals' => $boundaryCodeTotals,
                ],
            ],
        ]);
    }

    public function providers(
        WhatsappOperationalQueryRequest $request,
        TenantContext $tenantContext,
        WhatsappOperationsViewFactory $viewFactory,
        CalculateWhatsappProviderHealthAction $calculateProviderHealth,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($tenantContext);
        $window = $request->window();
        $providerFilter = $this->resolvedProviderFilter($request);

        $configurations = WhatsappProviderConfig::query()
            ->when($providerFilter !== null, fn (Builder $query): Builder => $query->where('provider', $providerFilter))
            ->when($request->filled('slot'), fn (Builder $query): Builder => $query->where('slot', (string) $request->string('slot')))
            ->orderByRaw("case when slot = 'primary' then 0 else 1 end")
            ->get();

        $healthcheckAudits = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where('action', 'whatsapp_provider_config.healthcheck_requested')
            ->latest('created_at')
            ->get();

        $latestHealthchecks = [];

        foreach ($healthcheckAudits as $audit) {
            $key = $this->auditCompositeKey($audit);

            if ($key === null || array_key_exists($key, $latestHealthchecks)) {
                continue;
            }

            $latestHealthchecks[$key] = [
                'healthy' => (bool) data_get($audit->metadata_json, 'result.healthy'),
                'http_status' => data_get($audit->metadata_json, 'result.http_status'),
                'latency_ms' => data_get($audit->metadata_json, 'result.latency_ms'),
                'error_code' => data_get($audit->metadata_json, 'result.error.code'),
                'error_message' => data_get($audit->metadata_json, 'result.error.message'),
                'checked_at' => data_get($audit->metadata_json, 'result.checked_at') ?: $audit->created_at?->toIso8601String(),
            ];
        }

        $latestAuditActivity = [];

        foreach (AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('action', [
                'whatsapp_provider_config.activated',
                'whatsapp_provider_config.deactivated',
                'whatsapp_provider_config.healthcheck_requested',
            ])
            ->latest('created_at')
            ->get() as $audit) {
            $key = $this->auditCompositeKey($audit);

            if ($key === null || array_key_exists($key, $latestAuditActivity)) {
                continue;
            }

            $latestAuditActivity[$key] = $audit->created_at?->toIso8601String();
        }

        $latestBoundaryActivity = BoundaryRejectionAudit::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('provider')
            ->selectRaw('provider, MAX(occurred_at) as last_boundary_at')
            ->groupBy('provider')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->provider => (string) $row->last_boundary_at,
            ])
            ->all();

        $data = [];

        foreach ($configurations as $configuration) {
            $provider = (string) $configuration->provider;
            $key = sprintf('%s|%s', $configuration->slot, $provider);
            $healthSnapshot = $calculateProviderHealth->execute($configuration, $window);

            $data[] = $viewFactory->providerHealth(
                configuration: $configuration,
                healthSnapshot: $healthSnapshot,
                lastHealthcheck: $latestHealthchecks[$key] ?? null,
                lastActivityAt: $this->latestTimestamp([
                    $healthSnapshot->lastAttemptAt,
                    $latestBoundaryActivity[$provider] ?? null,
                    $latestAuditActivity[$key] ?? null,
                ]),
            );
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'window' => $this->windowPayload($window),
                'filters' => $this->filtersPayload($request, $providerFilter),
            ],
        ]);
    }

    public function queue(
        WhatsappOperationalQueryRequest $request,
        WhatsappOperationsViewFactory $viewFactory,
    ): JsonResponse {
        $window = $request->window();
        $providerFilter = $this->resolvedProviderFilter($request);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $request->perPage();
        $fetchLimit = $page * $perPage;
        $total = 0;
        $items = [];

        if ($this->queueTypeMatches($request, 'outbox_failed') && ! $this->errorCodeFilterBlocksNonAttemptSources($request) && $this->statusMatches($request, 'failed')) {
            $query = OutboxEvent::query()
                ->with(['message', 'eventLog'])
                ->where('event_name', 'like', 'whatsapp.%')
                ->where('status', 'failed')
                ->where(function (Builder $query) use ($window): void {
                    $query->whereBetween('failed_at', [$window->startedAt, $window->endedAt])
                        ->orWhere(function (Builder $query) use ($window): void {
                            $query->whereNull('failed_at')
                                ->whereBetween('updated_at', [$window->startedAt, $window->endedAt]);
                        });
                })
                ->where(function (Builder $query): void {
                    $query->whereNull('last_reclaim_reason')
                        ->orWhereNotIn('last_reclaim_reason', $this->manualReviewReasons());
                });

            if ($providerFilter !== null) {
                $query->whereHas('message', fn (Builder $query): Builder => $query->where('provider', $providerFilter));
            }

            $total += (clone $query)->count();

            foreach ((clone $query)
                ->latest('failed_at')
                ->latest('updated_at')
                ->limit($fetchLimit)
                ->get() as $outboxEvent) {
                $items[] = $viewFactory->queueOutboxItem($outboxEvent, 'outbox_failed');
            }
        }

        if ($this->queueTypeMatches($request, 'outbox_reclaimed_recently') && ! $this->errorCodeFilterBlocksNonAttemptSources($request) && $this->statusMatches($request, 'retry_scheduled')) {
            $query = OutboxEvent::query()
                ->with(['message', 'eventLog'])
                ->where('event_name', 'like', 'whatsapp.%')
                ->where('status', 'retry_scheduled')
                ->where('reclaim_count', '>', 0)
                ->whereBetween('last_reclaimed_at', [$window->startedAt, $window->endedAt]);

            if ($providerFilter !== null) {
                $query->whereHas('message', fn (Builder $query): Builder => $query->where('provider', $providerFilter));
            }

            $total += (clone $query)->count();

            foreach ((clone $query)
                ->latest('last_reclaimed_at')
                ->limit($fetchLimit)
                ->get() as $outboxEvent) {
                $items[] = $viewFactory->queueOutboxItem($outboxEvent, 'outbox_reclaimed_recently');
            }
        }

        if ($this->queueTypeMatches($request, 'outbox_manual_review_required') && ! $this->errorCodeFilterBlocksNonAttemptSources($request) && $this->statusMatches($request, 'failed')) {
            $query = OutboxEvent::query()
                ->with(['message', 'eventLog'])
                ->where('event_name', 'like', 'whatsapp.%')
                ->where('status', 'failed')
                ->whereIn('last_reclaim_reason', $this->manualReviewReasons())
                ->where(function (Builder $query) use ($window): void {
                    $query->whereBetween('failed_at', [$window->startedAt, $window->endedAt])
                        ->orWhere(function (Builder $query) use ($window): void {
                            $query->whereNull('failed_at')
                                ->whereBetween('updated_at', [$window->startedAt, $window->endedAt]);
                        });
                });

            if ($providerFilter !== null) {
                $query->whereHas('message', fn (Builder $query): Builder => $query->where('provider', $providerFilter));
            }

            $total += (clone $query)->count();

            foreach ((clone $query)
                ->latest('failed_at')
                ->limit($fetchLimit)
                ->get() as $outboxEvent) {
                $items[] = $viewFactory->queueOutboxItem($outboxEvent, 'outbox_manual_review_required');
            }
        }

        if ($this->queueTypeMatches($request, 'message_terminal_failure') && ! $this->errorCodeFilterBlocksNonAttemptSources($request) && $this->statusMatches($request, 'failed')) {
            $query = Message::query()
                ->where('channel', 'whatsapp')
                ->where('status', 'failed')
                ->where(function (Builder $query) use ($window): void {
                    $query->whereBetween('failed_at', [$window->startedAt, $window->endedAt])
                        ->orWhere(function (Builder $query) use ($window): void {
                            $query->whereNull('failed_at')
                                ->whereBetween('updated_at', [$window->startedAt, $window->endedAt]);
                        });
                });

            if ($providerFilter !== null) {
                $query->where('provider', $providerFilter);
            }

            $total += (clone $query)->count();

            foreach ((clone $query)
                ->latest('failed_at')
                ->latest('updated_at')
                ->limit($fetchLimit)
                ->get() as $message) {
                $items[] = $viewFactory->queueMessageItem($message);
            }
        }

        if ($this->queueTypeMatches($request, 'integration_attempt_issue')) {
            $query = IntegrationAttempt::query()
                ->with('message')
                ->where('channel', 'whatsapp')
                ->whereBetween('created_at', [$window->startedAt, $window->endedAt]);

            if ($providerFilter !== null) {
                $query->where('provider', $providerFilter);
            }

            if ($request->filled('status')) {
                $query->where('status', (string) $request->string('status'));
            }

            $errorCode = $request->filled('error_code')
                ? (string) $request->string('error_code')
                : null;

            if ($errorCode !== null) {
                $query->where('normalized_error_code', $errorCode);
            } else {
                $query->whereIn('normalized_error_code', $this->monitoredAttemptErrorCodes());
            }

            $total += (clone $query)->count();

            foreach ((clone $query)
                ->latest('failed_at')
                ->latest('created_at')
                ->limit($fetchLimit)
                ->get() as $attempt) {
                $items[] = $viewFactory->queueIntegrationAttemptItem($attempt);
            }
        }

        usort($items, fn (array $left, array $right): int => strcmp((string) ($right['occurred_at'] ?? ''), (string) ($left['occurred_at'] ?? '')));

        return response()->json([
            'data' => array_values(array_slice($items, ($page - 1) * $perPage, $perPage)),
            'meta' => array_merge(
                $this->paginationMeta($page, $perPage, $total),
                [
                    'window' => $this->windowPayload($window),
                    'filters' => $this->filtersPayload($request, $providerFilter),
                ],
            ),
        ]);
    }

    public function boundarySummary(
        WhatsappOperationalQueryRequest $request,
        TenantContext $tenantContext,
        WhatsappOperationsViewFactory $viewFactory,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($tenantContext);
        $window = $request->window();
        $providerFilter = $this->resolvedProviderFilter($request);
        $latestLimit = (int) config('observability.whatsapp_operations.default_boundary_latest_limit', 10);

        $query = $this->baseBoundaryQuery($request, $tenantId, $providerFilter)
            ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt]);

        $codeTotals = $this->countPairs($this->groupedCounts($query, 'code'), 'code');
        $endpointTotals = $this->countPairs($this->groupedCounts($query, 'endpoint'), 'endpoint');
        $directionTotals = $this->countPairs($this->groupedCounts($query, 'direction'), 'direction');

        $latest = (clone $query)
            ->latest('occurred_at')
            ->limit($latestLimit)
            ->get()
            ->map(fn (BoundaryRejectionAudit $audit): array => $viewFactory->boundarySummaryItem($audit))
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'window' => $this->windowPayload($window),
                'filters' => $this->filtersPayload($request, $providerFilter),
                'total' => array_sum(array_column($codeTotals, 'total')),
                'code_totals' => $codeTotals,
                'endpoint_totals' => $endpointTotals,
                'direction_totals' => $directionTotals,
                'latest' => $latest,
            ],
        ]);
    }

    public function boundaryRejections(
        WhatsappOperationalQueryRequest $request,
        TenantContext $tenantContext,
        WhatsappOperationsViewFactory $viewFactory,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($tenantContext);
        $window = $request->window();
        $providerFilter = $this->resolvedProviderFilter($request);

        $paginator = $this->baseBoundaryQuery($request, $tenantId, $providerFilter)
            ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt])
            ->latest('occurred_at')
            ->paginate($request->perPage());

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (BoundaryRejectionAudit $audit): array => $viewFactory->boundarySummaryItem($audit))
                ->values()
                ->all(),
            'meta' => array_merge(
                $this->paginationMeta($paginator->currentPage(), $paginator->perPage(), $paginator->total()),
                [
                    'window' => $this->windowPayload($window),
                    'filters' => $this->filtersPayload($request, $providerFilter),
                ],
            ),
        ]);
    }

    public function feed(
        WhatsappOperationalQueryRequest $request,
        TenantContext $tenantContext,
        WhatsappOperationsViewFactory $viewFactory,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($tenantContext);
        $window = $request->window();
        $providerFilter = $this->resolvedProviderFilter($request);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $request->perPage();
        $fetchLimit = $page * $perPage;
        $total = 0;
        $items = [];

        if ($this->feedSourceMatches($request, 'admin_audit')) {
            $auditQuery = AuditLog::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('action', $this->feedAuditActions($request))
                ->whereBetween('created_at', [$window->startedAt, $window->endedAt]);

            $auditItems = (clone $auditQuery)
                ->latest('created_at')
                ->get()
                ->map(fn (AuditLog $audit): array => $viewFactory->feedAuditItem($audit))
                ->filter(fn (array $item): bool => $this->matchesFeedFilters($request, $item, $providerFilter))
                ->values();

            $total += $auditItems->count();
            $items = [...$items, ...$auditItems->take($fetchLimit)->all()];
        }

        if ($this->feedSourceMatches($request, 'event_log') && $this->feedTypeMatches($request, [
            'outbox_reclaimed',
            'manual_review_required',
            'provider_fallback_scheduled',
            'provider_fallback_executed',
            'duplicate_prevented',
            'duplicate_risk_detected',
        ])) {
            $query = EventLog::query()
                ->with('message')
                ->whereIn('event_name', $this->feedEventNames($request))
                ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt]);

            if ($providerFilter !== null) {
                $query->whereHas('message', fn (Builder $query): Builder => $query->where('provider', $providerFilter));
            }

            if ($request->filled('status')) {
                $query->where('status', (string) $request->string('status'));
            }

            if ($request->filled('direction') || $request->filled('error_code')) {
                $query->whereRaw('1 = 0');
            }

            $eventLogItemsQuery = (clone $query)->latest('occurred_at');

            if (! $this->feedRequiresPostFilterScan($request)) {
                $eventLogItemsQuery->limit($fetchLimit);
            }

            $eventLogItems = $eventLogItemsQuery
                ->get()
                ->map(fn (EventLog $eventLog): array => $viewFactory->feedEventLogItem($eventLog))
                ->filter(fn (array $item): bool => $this->matchesFeedFilters($request, $item, $providerFilter))
                ->values();

            $total += $this->feedRequiresPostFilterScan($request)
                ? $eventLogItems->count()
                : (clone $query)->count();
            $items = [...$items, ...$eventLogItems->all()];
        }

        if ($this->feedSourceMatches($request, 'boundary_rejection_audit') && $this->feedTypeMatches($request, ['boundary_rejection'])) {
            $query = $this->baseBoundaryQuery($request, $tenantId, $providerFilter)
                ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt]);

            if ($request->filled('error_code')) {
                $query->where('code', (string) $request->string('error_code'));
            }

            if ($request->filled('status')) {
                $query->whereRaw('1 = 0');
            }

            $boundaryItems = (clone $query)
                ->latest('occurred_at')
                ->limit($fetchLimit)
                ->get()
                ->map(fn (BoundaryRejectionAudit $audit): array => $viewFactory->feedBoundaryItem($audit))
                ->filter(fn (array $item): bool => $this->matchesFeedFilters($request, $item, $providerFilter))
                ->values();

            $total += (clone $query)->count();
            $items = [...$items, ...$boundaryItems->all()];
        }

        if ($this->feedSourceMatches($request, 'integration_attempt') && $this->feedTypeMatches($request, ['terminal_failure'])) {
            $query = IntegrationAttempt::query()
                ->with('message')
                ->where('channel', 'whatsapp')
                ->where('status', 'failed')
                ->where('retryable', false)
                ->whereBetween('created_at', [$window->startedAt, $window->endedAt]);

            if ($providerFilter !== null) {
                $query->where('provider', $providerFilter);
            }

            if ($request->filled('error_code')) {
                $query->where('normalized_error_code', (string) $request->string('error_code'));
            }

            if ($request->filled('direction')) {
                $query->where('direction', (string) $request->string('direction'));
            }

            $attemptItemsQuery = (clone $query)
                ->latest('failed_at')
                ->latest('created_at');

            if (! $this->feedRequiresPostFilterScan($request)) {
                $attemptItemsQuery->limit($fetchLimit);
            }

            $attemptItems = $attemptItemsQuery
                ->get()
                ->map(fn (IntegrationAttempt $attempt): array => $viewFactory->feedIntegrationAttemptItem($attempt))
                ->filter(fn (array $item): bool => $this->matchesFeedFilters($request, $item, $providerFilter))
                ->values();

            $total += $this->feedRequiresPostFilterScan($request)
                ? $attemptItems->count()
                : (clone $query)->count();
            $items = [...$items, ...$attemptItems->all()];
        }

        usort($items, fn (array $left, array $right): int => strcmp((string) ($right['occurred_at'] ?? ''), (string) ($left['occurred_at'] ?? '')));

        return response()->json([
            'data' => array_values(array_slice($items, ($page - 1) * $perPage, $perPage)),
            'meta' => array_merge(
                $this->paginationMeta($page, $perPage, $total),
                [
                    'window' => $this->windowPayload($window),
                    'filters' => $this->filtersPayload($request, $providerFilter),
                ],
            ),
        ]);
    }

    private function currentTenantId(TenantContext $tenantContext): string
    {
        $tenant = $tenantContext->current();

        abort_if($tenant === null, 404, 'Tenant ativo nao encontrado para consulta operacional.');

        return (string) $tenant->id;
    }

    private function resolvedProviderFilter(WhatsappOperationalQueryRequest $request): ?string
    {
        if ($request->filled('provider')) {
            return (string) $request->string('provider');
        }

        if (! $request->filled('slot')) {
            return null;
        }

        return WhatsappProviderConfig::query()
            ->where('slot', (string) $request->string('slot'))
            ->value('provider');
    }

    /**
     * @return array<string, mixed>
     */
    private function windowPayload(\App\Application\DTOs\OperationalWindow $window): array
    {
        return [
            'label' => $window->label,
            'started_at' => $window->startedAt->toIso8601String(),
            'ended_at' => $window->endedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersPayload(WhatsappOperationalQueryRequest $request, ?string $providerFilter): array
    {
        return array_filter([
            'provider' => $request->filled('provider') ? (string) $request->string('provider') : null,
            'slot' => $request->filled('slot') ? (string) $request->string('slot') : null,
            'resolved_provider' => $request->filled('slot') && ! $request->filled('provider') ? $providerFilter : null,
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'code' => $request->filled('code') ? (string) $request->string('code') : null,
            'error_code' => $request->filled('error_code') ? (string) $request->string('error_code') : null,
            'direction' => $request->filled('direction') ? (string) $request->string('direction') : null,
            'type' => $request->filled('type') ? (string) $request->string('type') : null,
            'source' => $request->filled('source') ? (string) $request->string('source') : null,
            'attention_type' => $request->filled('attention_type') ? (string) $request->string('attention_type') : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function baseBoundaryQuery(
        WhatsappOperationalQueryRequest $request,
        string $tenantId,
        ?string $providerFilter,
    ): Builder {
        return BoundaryRejectionAudit::query()
            ->where('tenant_id', $tenantId)
            ->when($providerFilter !== null, fn (Builder $query): Builder => $query->where('provider', $providerFilter))
            ->when($request->filled('slot'), fn (Builder $query): Builder => $query->where('slot', (string) $request->string('slot')))
            ->when($request->filled('code'), fn (Builder $query): Builder => $query->where('code', (string) $request->string('code')))
            ->when($request->filled('direction'), fn (Builder $query): Builder => $query->where('direction', (string) $request->string('direction')));
    }

    /**
     * @return array<string, int>
     */
    private function groupedCounts(Builder $query, string $column): array
    {
        $counts = (clone $query)
            ->selectRaw(sprintf('%s as bucket, COUNT(*) as aggregate_total', $column))
            ->groupBy($column)
            ->pluck('aggregate_total', 'bucket')
            ->all();

        $normalized = [];

        foreach ($counts as $bucket => $total) {
            if ($bucket === null || $bucket === '') {
                continue;
            }

            $normalized[(string) $bucket] = (int) $total;
        }

        arsort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array<string, int|string>>
     */
    private function countPairs(array $counts, string $key): array
    {
        $pairs = [];

        foreach ($counts as $bucket => $total) {
            $pairs[] = [
                $key => $bucket,
                'total' => (int) $total,
            ];
        }

        return $pairs;
    }

    /**
     * @param  list<array<string, int|string>>  $pairs
     * @return array<string, int>
     */
    private function pairsToMap(array $pairs, string $key): array
    {
        $map = [];

        foreach ($pairs as $pair) {
            $bucket = $pair[$key] ?? null;

            if (! is_string($bucket) || $bucket === '') {
                continue;
            }

            $map[$bucket] = (int) ($pair['total'] ?? 0);
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $totals
     * @param  list<string>  $buckets
     */
    private function sumBuckets(array $totals, array $buckets): int
    {
        $sum = 0;

        foreach ($buckets as $bucket) {
            $sum += (int) ($totals[$bucket] ?? 0);
        }

        return $sum;
    }

    private function percentage(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function latestTimestamp(array $candidates): ?string
    {
        $values = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                $values[] = CarbonImmutable::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        if ($values === []) {
            return null;
        }

        usort(
            $values,
            static fn (CarbonImmutable $left, CarbonImmutable $right): int => $right->getTimestamp() <=> $left->getTimestamp(),
        );

        return $values[0]->toIso8601String();
    }

    private function auditCompositeKey(AuditLog $audit): ?string
    {
        $slot = data_get($audit->metadata_json, 'slot')
            ?? data_get($audit->after_json, 'slot')
            ?? data_get($audit->before_json, 'slot');
        $provider = data_get($audit->metadata_json, 'provider')
            ?? data_get($audit->after_json, 'provider')
            ?? data_get($audit->before_json, 'provider');

        if (! is_string($slot) || $slot === '' || ! is_string($provider) || $provider === '') {
            return null;
        }

        return sprintf('%s|%s', $slot, $provider);
    }

    /**
     * @return list<string>
     */
    private function manualReviewReasons(): array
    {
        return [
            'automatic_reopen_unsafe_due_to_inflight_dispatch',
            'max_reclaim_attempts_exceeded',
        ];
    }

    /**
     * @return list<string>
     */
    private function monitoredAttemptErrorCodes(): array
    {
        return [
            'provider_unavailable',
            'rate_limit',
            'unsupported_feature',
            'timeout_error',
            'transient_network_error',
        ];
    }

    /**
     * @return array{
     *     totals: array{scheduled:int,executed:int},
     *     by_provider: array<string, array{scheduled:int,executed:int}>
     * }
     */
    private function fallbackEventMetrics(\App\Application\DTOs\OperationalWindow $window, ?string $providerFilter = null): array
    {
        $totals = [
            'scheduled' => 0,
            'executed' => 0,
        ];
        $byProvider = [];

        $events = EventLog::query()
            ->whereIn('event_name', [
                'whatsapp.message.fallback.scheduled',
                'whatsapp.message.fallback.executed',
            ])
            ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt])
            ->get();

        foreach ($events as $event) {
            $bucket = match ($event->event_name) {
                'whatsapp.message.fallback.scheduled' => 'scheduled',
                'whatsapp.message.fallback.executed' => 'executed',
                default => null,
            };

            if ($bucket === null) {
                continue;
            }

            $provider = data_get($event->context_json, 'provider');

            if (! is_string($provider) || $provider === '') {
                continue;
            }

            if ($providerFilter !== null && $provider !== $providerFilter) {
                continue;
            }

            $totals[$bucket]++;
            $byProvider[$provider][$bucket] = (int) (($byProvider[$provider][$bucket] ?? 0) + 1);
            $byProvider[$provider]['scheduled'] = (int) ($byProvider[$provider]['scheduled'] ?? 0);
            $byProvider[$provider]['executed'] = (int) ($byProvider[$provider]['executed'] ?? 0);
        }

        return [
            'totals' => $totals,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * @return array{
     *     totals: array{prevented:int,risk_detected:int}
     * }
     */
    private function duplicateEventMetrics(\App\Application\DTOs\OperationalWindow $window, ?string $providerFilter = null): array
    {
        $query = EventLog::query()
            ->whereIn('event_name', [
                'whatsapp.message.duplicate_prevented',
                'whatsapp.message.duplicate_risk_detected',
            ])
            ->whereBetween('occurred_at', [$window->startedAt, $window->endedAt]);

        if ($providerFilter !== null) {
            $query->where(function (Builder $query) use ($providerFilter): void {
                $query
                    ->whereHas('message', fn (Builder $messageQuery): Builder => $messageQuery->where('provider', $providerFilter))
                    ->orWhere('context_json->provider', $providerFilter);
            });
        }

        $totals = [
            'prevented' => 0,
            'risk_detected' => 0,
        ];

        foreach ($query->get() as $event) {
            if ($event->event_name === 'whatsapp.message.duplicate_prevented') {
                $totals['prevented']++;
            }

            if ($event->event_name === 'whatsapp.message.duplicate_risk_detected') {
                $totals['risk_detected']++;
            }
        }

        return [
            'totals' => $totals,
        ];
    }

    private function isOperationalSignal(string $errorCode): bool
    {
        return in_array($errorCode, $this->monitoredAttemptErrorCodes(), true);
    }

    private function queueTypeMatches(WhatsappOperationalQueryRequest $request, string $type): bool
    {
        return ! $request->filled('attention_type')
            || (string) $request->string('attention_type') === $type;
    }

    private function statusMatches(WhatsappOperationalQueryRequest $request, string $status): bool
    {
        return ! $request->filled('status')
            || (string) $request->string('status') === $status;
    }

    private function errorCodeFilterBlocksNonAttemptSources(WhatsappOperationalQueryRequest $request): bool
    {
        return $request->filled('error_code');
    }

    /**
     * @param  list<string>  $types
     */
    private function feedTypeMatches(WhatsappOperationalQueryRequest $request, array $types): bool
    {
        return ! $request->filled('type')
            || in_array((string) $request->string('type'), $types, true);
    }

    private function feedSourceMatches(WhatsappOperationalQueryRequest $request, string $source): bool
    {
        return ! $request->filled('source')
            || (string) $request->string('source') === $source;
    }

    /**
     * @return list<string>
     */
    private function feedAuditActions(WhatsappOperationalQueryRequest $request): array
    {
        return match ((string) $request->string('type')) {
            'provider_config_activated' => ['whatsapp_provider_config.activated'],
            'provider_config_deactivated' => ['whatsapp_provider_config.deactivated'],
            'provider_healthcheck' => ['whatsapp_provider_config.healthcheck_requested'],
            default => [
                'whatsapp_provider_config.activated',
                'whatsapp_provider_config.deactivated',
                'whatsapp_provider_config.healthcheck_requested',
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function feedEventNames(WhatsappOperationalQueryRequest $request): array
    {
        return match ((string) $request->string('type')) {
            'outbox_reclaimed' => ['outbox.event.reclaimed'],
            'manual_review_required' => ['outbox.event.reclaim.blocked'],
            'provider_fallback_scheduled' => ['whatsapp.message.fallback.scheduled'],
            'provider_fallback_executed' => ['whatsapp.message.fallback.executed'],
            'duplicate_prevented' => ['whatsapp.message.duplicate_prevented'],
            'duplicate_risk_detected' => ['whatsapp.message.duplicate_risk_detected'],
            default => [
                'outbox.event.reclaimed',
                'outbox.event.reclaim.blocked',
                'whatsapp.message.fallback.scheduled',
                'whatsapp.message.fallback.executed',
                'whatsapp.message.duplicate_prevented',
                'whatsapp.message.duplicate_risk_detected',
            ],
        };
    }

    private function feedRequiresPostFilterScan(WhatsappOperationalQueryRequest $request): bool
    {
        return $request->filled('slot');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesFeedFilters(
        WhatsappOperationalQueryRequest $request,
        array $item,
        ?string $providerFilter,
    ): bool {
        if ($request->filled('type') && $item['type'] !== (string) $request->string('type')) {
            return false;
        }

        if ($request->filled('status') && (string) ($item['status'] ?? '') !== (string) $request->string('status')) {
            return false;
        }

        if ($request->filled('error_code') && (string) ($item['error_code'] ?? '') !== (string) $request->string('error_code')) {
            return false;
        }

        if ($request->filled('direction') && (string) ($item['direction'] ?? '') !== (string) $request->string('direction')) {
            return false;
        }

        if ($providerFilter !== null && (string) ($item['provider'] ?? '') !== $providerFilter) {
            return false;
        }

        if ($request->filled('slot') && (string) ($item['slot'] ?? '') !== (string) $request->string('slot')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(int $page, int $perPage, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }
}
