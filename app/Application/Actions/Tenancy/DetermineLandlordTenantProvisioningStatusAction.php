<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Infrastructure\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DetermineLandlordTenantProvisioningStatusAction
{
    public function __construct(
        private readonly TenantDatabaseProvisioner $databaseProvisioner,
        private readonly TenantDatabaseManager $tenantDatabaseManager,
    ) {
    }

    /**
     * @return array{
     *     code:string,
     *     label:string,
     *     detail:string,
     *     schema_ok:bool,
     *     database_exists:bool,
     *     owner_ready:bool,
     *     domain_ready:bool
     * }
     */
    public function execute(Tenant $tenant): array
    {
        $databaseExists = $this->databaseProvisioner->databaseExists($tenant->database_name);
        $domainReady = $tenant->domains->contains(fn ($domain) => $domain->is_primary);
        $ownerReady = $tenant->memberships->contains(
            fn ($membership) => $membership->role === 'owner' && $membership->isActive()
        );

        if (! $databaseExists) {
            return [
                'code' => 'database_missing',
                'label' => 'Banco pendente',
                'detail' => 'O banco do tenant ainda não está disponível.',
                'schema_ok' => false,
                'database_exists' => false,
                'owner_ready' => $ownerReady,
                'domain_ready' => $domainReady,
            ];
        }

        try {
            $this->tenantDatabaseManager->connect($tenant);

            $schemaOk = collect((array) config('landlord.tenants.schema_required_tables', []))
                ->every(fn (string $table): bool => Schema::connection('tenant')->hasTable($table));
        } catch (Throwable) {
            return [
                'code' => 'connection_failed',
                'label' => 'Falha de conexão',
                'detail' => 'Não foi possível validar o schema do tenant.',
                'schema_ok' => false,
                'database_exists' => true,
                'owner_ready' => $ownerReady,
                'domain_ready' => $domainReady,
            ];
        } finally {
            $this->tenantDatabaseManager->disconnect();
        }

        if (! $schemaOk) {
            return [
                'code' => 'schema_pending',
                'label' => 'Schema pendente',
                'detail' => 'As tabelas básicas do tenant ainda não estão completas.',
                'schema_ok' => false,
                'database_exists' => true,
                'owner_ready' => $ownerReady,
                'domain_ready' => $domainReady,
            ];
        }

        if (! $domainReady) {
            return [
                'code' => 'domain_missing',
                'label' => 'Domínio pendente',
                'detail' => 'O tenant ainda não possui domínio principal configurado.',
                'schema_ok' => true,
                'database_exists' => true,
                'owner_ready' => $ownerReady,
                'domain_ready' => false,
            ];
        }

        if (! $ownerReady) {
            return [
                'code' => 'owner_missing',
                'label' => 'Owner pendente',
                'detail' => 'O tenant foi provisionado sem owner ativo.',
                'schema_ok' => true,
                'database_exists' => true,
                'owner_ready' => false,
                'domain_ready' => true,
            ];
        }

        return [
            'code' => 'provisioned',
            'label' => 'Provisionado',
            'detail' => 'Banco, schema, domínio principal e owner ativo estão prontos.',
            'schema_ok' => true,
            'database_exists' => true,
            'owner_ready' => true,
            'domain_ready' => true,
        ];
    }
}
