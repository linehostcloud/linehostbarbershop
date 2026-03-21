<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Observability\Models\TenantOperationalBlockAudit;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Observability\LandlordTenantDetailPerformanceTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BuildLandlordTenantSuspensionObservabilityAction
{
    public function __construct(
        private readonly LandlordTenantDetailPerformanceTracker $performanceTracker,
    ) {}

    private const WINDOW_DAYS = 7;

    private const RECENT_LIMIT = 6;

    /**
     * @var list<string>
     */
    private const REQUIRED_OBSERVABILITY_TABLES = [
        'tenant_operational_block_audits',
        'boundary_rejection_audits',
    ];

    /**
     * @var array<string, string>
     */
    private const CHANNEL_LABELS = [
        'web' => 'Painel web bloqueado',
        'api' => 'API tenant bloqueada',
        'command' => 'Runtime assíncrono ignorado',
        'credential_issue' => 'Emissão de credencial bloqueada',
        'outbound' => 'API outbound bloqueada',
        'webhook' => 'Webhooks ignorados',
    ];

    /**
     * @return array{
     *     availability:array{available:bool,label:string|null,detail:string|null,missing_tables:list<string>},
     *     access_tokens:array{active_count:int,last_revoked_count:int|null,last_revoked_at:string|null},
     *     summary:array{window_label:string,total_count:int,affected_channels_count:int,recurring:bool,recurring_label:string},
     *     channels:list<array{channel:string,label:string,count:int,last_seen_at:string|null}>,
     *     recent_blocks:list<array{id:string,channel:string,label:string,detail:string,occurred_at:string|null}>,
     *     webhook_policy:array{status_code:int,label:string,detail:string}
     * }
     */
    public function execute(Tenant $tenant): array
    {
        $windowStart = now()->subDays(self::WINDOW_DAYS);
        $latestSuspensionAudit = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'landlord_tenant.status_changed')
            ->latest('created_at')
            ->get()
            ->first(fn (AuditLog $auditLog): bool => data_get($auditLog->after_json, 'status') === 'suspended');
        $accessTokens = [
            'active_count' => UserAccessToken::query()
                ->where('tenant_id', $tenant->id)
                ->count(),
            'last_revoked_count' => is_numeric(data_get($latestSuspensionAudit?->metadata_json, 'revoked_access_token_count'))
                ? (int) data_get($latestSuspensionAudit?->metadata_json, 'revoked_access_token_count')
                : null,
            'last_revoked_at' => $this->formatDate($latestSuspensionAudit?->created_at),
        ];
        $missingTables = $this->missingObservabilityTables();

        if ($missingTables !== []) {
            $this->performanceTracker->increment('suspension_observability_missing_table_count', count($missingTables));
            $this->performanceTracker->recordFailure(
                'suspension_observability.missing_tables',
                new RuntimeException(sprintf('Missing required landlord observability tables: %s', implode(', ', $missingTables))),
                [
                    'tenant_id' => (string) $tenant->getKey(),
                    'tenant_slug' => (string) $tenant->slug,
                    'missing_tables' => $missingTables,
                ],
            );

            return $this->unavailablePayload($accessTokens, $missingTables);
        }

        $transversalBlockAudits = TenantOperationalBlockAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('reason_code', 'tenant_status_runtime_enforcement')
            ->where('occurred_at', '>=', $windowStart)
            ->latest('occurred_at')
            ->get();

        $boundaryAudits = BoundaryRejectionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('code', WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value)
            ->where('occurred_at', '>=', $windowStart)
            ->latest('occurred_at')
            ->get()
            ->filter(fn (BoundaryRejectionAudit $audit): bool => data_get($audit->context_json, 'tenant_status') === 'suspended')
            ->values();

        $channels = $this->buildChannels($transversalBlockAudits, $boundaryAudits);
        $totalCount = (int) collect($channels)->sum('count');
        $affectedChannelsCount = collect($channels)
            ->filter(fn (array $channel): bool => $channel['count'] > 0)
            ->count();

        return [
            'availability' => [
                'available' => true,
                'label' => null,
                'detail' => null,
                'missing_tables' => [],
            ],
            'access_tokens' => $accessTokens,
            'summary' => [
                'window_label' => sprintf('Últimos %d dias', self::WINDOW_DAYS),
                'total_count' => $totalCount,
                'affected_channels_count' => $affectedChannelsCount,
                'recurring' => $totalCount >= 5,
                'recurring_label' => $totalCount >= 5
                    ? 'Recorrência detectada de bloqueios operacionais durante a suspensão.'
                    : 'Sem recorrência relevante de bloqueios operacionais no período.',
            ],
            'channels' => $channels,
            'recent_blocks' => $this->buildRecentBlocks($transversalBlockAudits, $boundaryAudits),
            'webhook_policy' => [
                'status_code' => 202,
                'label' => 'Webhook suspenso reconhecido sem processamento',
                'detail' => 'Webhooks recebidos durante a suspensão retornam 202 e são auditados como ignorados para evitar retries contínuos desnecessários.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return self::CHANNEL_LABELS;
    }

    /**
     * @return list<array{channel:string,label:string,count:int,last_seen_at:string|null}>
     */
    private function buildChannels(Collection $transversalBlockAudits, Collection $boundaryAudits): array
    {
        return collect(self::CHANNEL_LABELS)
            ->map(function (string $label, string $channel) use ($transversalBlockAudits, $boundaryAudits): array {
                $channelAudits = in_array($channel, ['outbound', 'webhook'], true)
                    ? $boundaryAudits->where('direction', $channel)->values()
                    : $transversalBlockAudits->where('channel', $channel)->values();

                return [
                    'channel' => $channel,
                    'label' => $label,
                    'count' => $channelAudits->count(),
                    'last_seen_at' => $this->formatDate($channelAudits->first()?->occurred_at),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  array{active_count:int,last_revoked_count:int|null,last_revoked_at:string|null}  $accessTokens
     * @param  list<string>  $missingTables
     * @return array{
     *     availability:array{available:bool,label:string|null,detail:string|null,missing_tables:list<string>},
     *     access_tokens:array{active_count:int,last_revoked_count:int|null,last_revoked_at:string|null},
     *     summary:array{window_label:string,total_count:int,affected_channels_count:int,recurring:bool,recurring_label:string},
     *     channels:list<array{channel:string,label:string,count:int,last_seen_at:string|null}>,
     *     recent_blocks:list<array{id:string,channel:string,label:string,detail:string,occurred_at:string|null}>,
     *     webhook_policy:array{status_code:int,label:string,detail:string}
     * }
     */
    private function unavailablePayload(array $accessTokens, array $missingTables): array
    {
        return [
            'availability' => [
                'available' => false,
                'label' => 'Observabilidade operacional indisponível',
                'detail' => 'A seção de bloqueios operacionais foi degradada com segurança porque a estrutura landlord deste ambiente está incompleta. Aplique a migration landlord pendente para reativar esta leitura.',
                'missing_tables' => array_values($missingTables),
            ],
            'access_tokens' => $accessTokens,
            'summary' => [
                'window_label' => 'Indisponível',
                'total_count' => 0,
                'affected_channels_count' => 0,
                'recurring' => false,
                'recurring_label' => 'Observabilidade operacional indisponível até a estrutura landlord deste ambiente ser corrigida.',
            ],
            'channels' => $this->emptyChannels(),
            'recent_blocks' => [],
            'webhook_policy' => [
                'status_code' => 202,
                'label' => 'Webhook suspenso reconhecido sem processamento',
                'detail' => 'Webhooks recebidos durante a suspensão retornam 202 e são auditados como ignorados para evitar retries contínuos desnecessários.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function missingObservabilityTables(): array
    {
        return collect(self::REQUIRED_OBSERVABILITY_TABLES)
            ->filter(fn (string $table): bool => ! Schema::connection('landlord')->hasTable($table))
            ->values()
            ->all();
    }

    /**
     * @return list<array{channel:string,label:string,count:int,last_seen_at:string|null}>
     */
    private function emptyChannels(): array
    {
        return collect(self::CHANNEL_LABELS)
            ->map(fn (string $label, string $channel): array => [
                'channel' => $channel,
                'label' => $label,
                'count' => 0,
                'last_seen_at' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:string,channel:string,label:string,detail:string,occurred_at:string|null}>
     */
    private function buildRecentBlocks(Collection $transversalBlockAudits, Collection $boundaryAudits): array
    {
        $transversalItems = $transversalBlockAudits
            ->map(function (TenantOperationalBlockAudit $audit): array {
                return [
                    'id' => $audit->id,
                    'channel' => $audit->channel,
                    'label' => self::CHANNEL_LABELS[$audit->channel] ?? 'Bloqueio operacional',
                    'detail' => $this->transversalDetail($audit),
                    'occurred_at' => $this->formatDate($audit->occurred_at),
                    'sort_at' => $audit->occurred_at?->getTimestamp() ?? 0,
                ];
            });

        $boundaryItems = $boundaryAudits
            ->map(function (BoundaryRejectionAudit $audit): array {
                $channel = (string) $audit->direction;

                return [
                    'id' => $audit->id,
                    'channel' => $channel,
                    'label' => self::CHANNEL_LABELS[$channel] ?? 'Bloqueio operacional',
                    'detail' => $this->boundaryDetail($audit),
                    'occurred_at' => $this->formatDate($audit->occurred_at),
                    'sort_at' => $audit->occurred_at?->getTimestamp() ?? 0,
                ];
            });

        return $transversalItems
            ->merge($boundaryItems)
            ->sortByDesc('sort_at')
            ->take(self::RECENT_LIMIT)
            ->map(fn (array $item): array => [
                'id' => $item['id'],
                'channel' => $item['channel'],
                'label' => $item['label'],
                'detail' => $item['detail'],
                'occurred_at' => $item['occurred_at'],
            ])
            ->values()
            ->all();
    }

    private function transversalDetail(TenantOperationalBlockAudit $audit): string
    {
        return match ($audit->channel) {
            'command' => sprintf(
                'Comando %s ignorado com tenant em status %s.',
                $audit->surface ?: 'desconhecido',
                data_get($audit->context_json, 'tenant_status', 'desconhecido'),
            ),
            'credential_issue' => sprintf(
                'Emissão de credencial bloqueada em %s.',
                (string) data_get($audit->context_json, 'token_name', 'fluxo interno'),
            ),
            default => trim(sprintf(
                '%s %s',
                $audit->method ?: '',
                $audit->endpoint ?: ($audit->surface ?: 'rota tenant bloqueada'),
            )),
        };
    }

    private function boundaryDetail(BoundaryRejectionAudit $audit): string
    {
        return trim(sprintf(
            '%s %s',
            $audit->method ?: '',
            $audit->endpoint ?: 'borda WhatsApp',
        ));
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)
            ->setTimezone(config('app.timezone', 'UTC'))
            ->format('d/m/Y H:i');
    }
}
