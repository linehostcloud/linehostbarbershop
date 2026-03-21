<?php

namespace App\Application\Actions\Tenancy;

class ResolveLandlordTenantSnapshotDashboardFiltersAction
{
    public const SNAPSHOT_STATUS_HEALTHY = 'healthy';

    public const SNAPSHOT_STATUS_STALE = 'stale';

    public const SNAPSHOT_STATUS_MISSING = 'missing';

    public const SNAPSHOT_STATUS_FAILED = 'failed';

    public const SNAPSHOT_STATUS_REFRESHING = 'refreshing';

    public const SNAPSHOT_STATUS_FALLBACK = 'fallback';

    public const SORT_PRIORITY = 'priority';

    public const SORT_SNAPSHOT_AGE = 'snapshot_age';

    public const SORT_TENANT = 'tenant';

    public const SORT_UPDATED_AT = 'updated_at';

    /**
     * @return array{
     *     snapshot_status:string,
     *     tenant_status:string,
     *     search:string,
     *     sort:string,
     *     direction:string
     * }
     */
    public function execute(array $input): array
    {
        $sort = $this->normalize(
            (string) ($input['sort'] ?? ''),
            array_keys($this->sortOptions()),
        );
        $sort = $sort !== '' ? $sort : self::SORT_PRIORITY;

        $direction = $this->normalize(
            (string) ($input['direction'] ?? ''),
            ['asc', 'desc'],
        );
        $direction = $direction !== '' ? $direction : $this->defaultDirection($sort);

        return [
            'snapshot_status' => $this->normalize(
                (string) ($input['snapshot_status'] ?? ''),
                array_keys($this->snapshotStatusOptions()),
            ),
            'tenant_status' => $this->normalize(
                (string) ($input['tenant_status'] ?? ''),
                array_keys($this->tenantStatusOptions()),
            ),
            'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 120),
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * @return array{
     *     snapshot_status:array<string, string>,
     *     tenant_status:array<string, string>,
     *     sort:array<string, string>,
     *     direction:array<string, string>
     * }
     */
    public function options(): array
    {
        return [
            'snapshot_status' => $this->snapshotStatusOptions(),
            'tenant_status' => $this->tenantStatusOptions(),
            'sort' => $this->sortOptions(),
            'direction' => [
                'desc' => 'Descendente',
                'asc' => 'Ascendente',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function hasActiveFilters(array $filters): bool
    {
        if (($filters['snapshot_status'] ?? '') !== '') {
            return true;
        }

        if (($filters['tenant_status'] ?? '') !== '') {
            return true;
        }

        if (($filters['search'] ?? '') !== '') {
            return true;
        }

        $sort = (string) ($filters['sort'] ?? self::SORT_PRIORITY);
        $direction = (string) ($filters['direction'] ?? $this->defaultDirection($sort));

        return $sort !== self::SORT_PRIORITY || $direction !== $this->defaultDirection(self::SORT_PRIORITY);
    }

    /**
     * @return array<string, string>
     */
    public function snapshotStatusOptions(): array
    {
        return [
            self::SNAPSHOT_STATUS_HEALTHY => 'Healthy',
            self::SNAPSHOT_STATUS_STALE => 'Stale',
            self::SNAPSHOT_STATUS_MISSING => 'Missing',
            self::SNAPSHOT_STATUS_FAILED => 'Failed',
            self::SNAPSHOT_STATUS_REFRESHING => 'Refreshing',
            self::SNAPSHOT_STATUS_FALLBACK => 'Fallback conservador',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function tenantStatusOptions(): array
    {
        return [
            'trial' => 'Trial',
            'active' => 'Ativo',
            'suspended' => 'Suspenso',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortOptions(): array
    {
        return [
            self::SORT_PRIORITY => 'Prioridade operacional',
            self::SORT_SNAPSHOT_AGE => 'Idade do snapshot',
            self::SORT_TENANT => 'Tenant',
            self::SORT_UPDATED_AT => 'Última atualização',
        ];
    }

    private function defaultDirection(string $sort): string
    {
        return match ($sort) {
            self::SORT_TENANT => 'asc',
            default => 'desc',
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalize(string $value, array $allowed): string
    {
        $normalized = trim(mb_strtolower($value));

        return in_array($normalized, $allowed, true) ? $normalized : '';
    }
}
