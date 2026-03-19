<?php

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class TenantExecutionLockManager
{
    /**
     * @template T
     *
     * @param  Closure():T  $callback
     * @return array{acquired:bool,lock_key:string,result:mixed}
     */
    public function executeForTenant(Tenant $tenant, string $operation, int $seconds, Closure $callback): array
    {
        return $this->executeForScope(
            $this->tenantScopeKey($tenant),
            $operation,
            $seconds,
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  Closure():T  $callback
     * @return array{acquired:bool,lock_key:string,result:mixed}
     */
    public function executeForCurrentTenantConnection(string $operation, int $seconds, Closure $callback): array
    {
        return $this->executeForScope(
            $this->currentTenantConnectionScopeKey(),
            $operation,
            $seconds,
            $callback,
        );
    }

    public function acquireForTenant(Tenant $tenant, string $operation, int $seconds): ?Lock
    {
        return $this->acquire($this->tenantScopeKey($tenant), $operation, $seconds);
    }

    public function acquireForCurrentTenantConnection(string $operation, int $seconds): ?Lock
    {
        return $this->acquire($this->currentTenantConnectionScopeKey(), $operation, $seconds);
    }

    public function lockKeyForTenant(Tenant $tenant, string $operation): string
    {
        return $this->lockKey($this->tenantScopeKey($tenant), $operation);
    }

    public function lockKeyForCurrentTenantConnection(string $operation): string
    {
        return $this->lockKey($this->currentTenantConnectionScopeKey(), $operation);
    }

    /**
     * @template T
     *
     * @param  Closure():T  $callback
     * @return array{acquired:bool,lock_key:string,result:mixed}
     */
    private function executeForScope(string $scope, string $operation, int $seconds, Closure $callback): array
    {
        $lockKey = $this->lockKey($scope, $operation);
        $lock = Cache::lock($lockKey, max(1, $seconds));

        if (! $lock->get()) {
            return [
                'acquired' => false,
                'lock_key' => $lockKey,
                'result' => null,
            ];
        }

        try {
            return [
                'acquired' => true,
                'lock_key' => $lockKey,
                'result' => $callback(),
            ];
        } finally {
            $lock->release();
        }
    }

    private function acquire(string $scope, string $operation, int $seconds): ?Lock
    {
        $lock = Cache::lock($this->lockKey($scope, $operation), max(1, $seconds));

        return $lock->get() ? $lock : null;
    }

    private function tenantScopeKey(Tenant $tenant): string
    {
        return (string) ($tenant->getKey() ?: $tenant->slug);
    }

    private function currentTenantConnectionScopeKey(): string
    {
        $connection = (string) config('tenancy.tenant_connection', 'tenant');
        $database = config(sprintf('database.connections.%s.database', $connection));

        if (is_string($database) && $database !== '') {
            return $database;
        }

        return $connection;
    }

    private function lockKey(string $scope, string $operation): string
    {
        return sprintf('tenant-operation-lock:%s:%s', $operation, sha1($scope));
    }
}
