<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Auth\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;

class BuildLandlordTenantRecentActivityAction
{
    private const DEFAULT_LIMIT = 6;

    public function __construct(
        private readonly MapLandlordAuditLogActivityAction $mapAuditActivity,
    ) {}

    /**
     * @return list<array{
     *     id:string,
     *     action:string,
     *     label:string,
     *     detail:string,
     *     occurred_at:string|null,
     *     actor:array{name:string|null,email:string|null,label:string},
     *     tenant:array{id:string|null,trade_name:string|null,slug:string|null,label:string}
     * }>
     */
    public function execute(Tenant $tenant, int $limit = self::DEFAULT_LIMIT): array
    {
        return AuditLog::query()
            ->with('actor')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $auditLog): array => $this->mapAuditActivity->execute($auditLog))
            ->values()
            ->all();
    }
}
