<?php

namespace App\Infrastructure\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TenantDatabaseProvisioner
{
    public function makeDatabaseName(string $slug): string
    {
        $prefix = (string) config('tenancy.provisioning.database_prefix', 'tenant_');
        $normalizedSlug = Str::of($slug)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        if ($normalizedSlug === '') {
            throw new RuntimeException('Nao foi possivel gerar um nome de banco valido para o tenant.');
        }

        $baseName = $prefix.$normalizedSlug;

        if ($this->tenantDriver() === 'sqlite') {
            $baseName = Str::finish($baseName, '.sqlite');

            return strlen($baseName) <= 255
                ? $baseName
                : substr($baseName, 0, 200).'_'.substr(sha1($slug), 0, 16).'.sqlite';
        }

        return strlen($baseName) <= 64
            ? $baseName
            : substr($baseName, 0, 40).'_'.substr(sha1($slug), 0, 16);
    }

    public function databaseExists(string $databaseName): bool
    {
        if ($this->tenantDriver() === 'sqlite') {
            return is_file($this->normalizeSqlitePath($databaseName));
        }

        $schema = DB::connection(config('tenancy.landlord_connection', 'landlord'));
        $result = $schema->selectOne('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$databaseName]);

        return $result !== null;
    }

    public function createDatabase(string $databaseName): void
    {
        if ($this->tenantDriver() === 'sqlite') {
            $path = $this->normalizeSqlitePath($databaseName);

            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException(sprintf('Nao foi possivel criar o diretorio do banco SQLite do tenant: %s', $directory));
            }

            if (! touch($path)) {
                throw new RuntimeException(sprintf('Nao foi possivel criar o banco SQLite do tenant: %s', $path));
            }

            return;
        }

        $charset = $this->validatedIdentifier((string) config('tenancy.provisioning.database_charset', 'utf8mb4'));
        $collation = $this->validatedIdentifier((string) config('tenancy.provisioning.database_collation', 'utf8mb4_unicode_ci'));
        $database = $this->validatedIdentifier($databaseName);

        DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->statement(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s',
                $database,
                $charset,
                $collation,
            ));
    }

    public function dropDatabase(string $databaseName): void
    {
        if ($this->tenantDriver() === 'sqlite') {
            $path = $this->normalizeSqlitePath($databaseName);

            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $database = $this->validatedIdentifier($databaseName);

        DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
    }

    private function tenantDriver(): string
    {
        return (string) config('database.connections.'.config('tenancy.tenant_connection', 'tenant').'.driver', 'mysql');
    }

    private function normalizeSqlitePath(string $databaseName): string
    {
        if ($databaseName === ':memory:' || str_starts_with($databaseName, DIRECTORY_SEPARATOR)) {
            return $databaseName;
        }

        return database_path($databaseName);
    }

    private function validatedIdentifier(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new RuntimeException(sprintf('Identificador invalido para provisionamento de banco: %s', $value));
        }

        return $value;
    }
}
