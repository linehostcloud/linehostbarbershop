<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Auth\Models\UserAccessToken;
use App\Domain\Communication\Enums\WhatsappBoundaryRejectionCode;
use App\Domain\Observability\Models\BoundaryRejectionAudit;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;

class BuildLandlordTenantSuspensionObservabilityAction
{
    private const WINDOW_DAYS = 7;

    /**
     * @return array{
     *     access_tokens:array{active_count:int,last_revoked_count:int|null,last_revoked_at:string|null},
     *     boundary:array{
     *         window_label:string,
     *         total_count:int,
     *         recurring:bool,
     *         recurring_label:string,
     *         channels:list<array{channel:string,label:string,count:int,last_seen_at:string|null}>
     *     },
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

        $suspensionBoundaryAudits = BoundaryRejectionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('code', WhatsappBoundaryRejectionCode::SecurityPolicyViolation->value)
            ->where('occurred_at', '>=', $windowStart)
            ->latest('occurred_at')
            ->get()
            ->filter(fn (BoundaryRejectionAudit $audit): bool => data_get($audit->context_json, 'tenant_status') === 'suspended');

        $channels = collect([
            'outbound' => 'API outbound bloqueada',
            'webhook' => 'Webhooks ignorados',
        ])->map(function (string $label, string $channel) use ($suspensionBoundaryAudits): array {
            $channelAudits = $suspensionBoundaryAudits->where('direction', $channel)->values();

            return [
                'channel' => $channel,
                'label' => $label,
                'count' => $channelAudits->count(),
                'last_seen_at' => $this->formatDate($channelAudits->first()?->occurred_at),
            ];
        })->values()->all();

        $boundaryTotal = array_sum(array_map(
            static fn (array $channel): int => (int) $channel['count'],
            $channels,
        ));

        return [
            'access_tokens' => [
                'active_count' => UserAccessToken::query()
                    ->where('tenant_id', $tenant->id)
                    ->count(),
                'last_revoked_count' => is_numeric(data_get($latestSuspensionAudit?->metadata_json, 'revoked_access_token_count'))
                    ? (int) data_get($latestSuspensionAudit?->metadata_json, 'revoked_access_token_count')
                    : null,
                'last_revoked_at' => $this->formatDate($latestSuspensionAudit?->created_at),
            ],
            'boundary' => [
                'window_label' => sprintf('Últimos %d dias', self::WINDOW_DAYS),
                'total_count' => $boundaryTotal,
                'recurring' => $boundaryTotal >= 5,
                'recurring_label' => $boundaryTotal >= 5
                    ? 'Recorrência detectada na borda WhatsApp durante a suspensão.'
                    : 'Sem recorrência relevante na borda WhatsApp no período.',
                'channels' => $channels,
            ],
            'webhook_policy' => [
                'status_code' => 202,
                'label' => 'Webhook suspenso reconhecido sem processamento',
                'detail' => 'Webhooks recebidos durante a suspensão retornam 202 e são auditados como ignorados para evitar retries contínuos desnecessários.',
            ],
        ];
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
