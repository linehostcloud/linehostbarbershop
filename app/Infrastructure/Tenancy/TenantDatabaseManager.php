<?php

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

class TenantDatabaseManager
{
    /**
     * @var array<string, mixed>
     */
    private array $baseConnectionConfig;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $database,
    ) {
        $this->baseConnectionConfig = $this->config->get('database.connections.tenant', []);
    }

    public function connect(Tenant $tenant): void
    {
        $connection = config('tenancy.tenant_connection', 'tenant');
        $baseConfig = $this->config->get("database.connections.{$connection}", $this->baseConnectionConfig);
        $driver = $baseConfig['driver'] ?? 'mysql';

        $runtimeConfig = $driver === 'sqlite'
            ? array_merge($baseConfig, [
                'database' => $this->normalizeSqlitePath($tenant->database_name),
                'foreign_key_constraints' => true,
            ])
            : array_merge($baseConfig, [
                'host' => $tenant->database_host ?: ($baseConfig['host'] ?? '127.0.0.1'),
                'port' => $tenant->database_port ?: ($baseConfig['port'] ?? '3306'),
                'database' => $tenant->database_name,
                'username' => $tenant->database_username ?: ($baseConfig['username'] ?? 'root'),
                'password' => $tenant->resolveDatabasePassword() ?? ($baseConfig['password'] ?? ''),
            ]);

        $this->config->set("database.connections.{$connection}", $runtimeConfig);

        $this->database->purge($connection);
        $this->database->reconnect($connection);
    }

    public function disconnect(): void
    {
        $connection = config('tenancy.tenant_connection', 'tenant');

        $this->config->set("database.connections.{$connection}", $this->baseConnectionConfig);

        $this->database->disconnect($connection);
        $this->database->purge($connection);
    }

    private function normalizeSqlitePath(string $database): string
    {
        if ($database === ':memory:' || str_starts_with($database, DIRECTORY_SEPARATOR)) {
            return $database;
        }

        return database_path($database);
    }
}
