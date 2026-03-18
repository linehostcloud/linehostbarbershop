<?php

namespace Tests\Integration\Tenancy;

use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class TenantDatabaseManagerTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_points_the_tenant_connection_to_the_provisioned_database(): void
    {
        $tenant = $this->provisionTenant(
            slug: 'barbearia-premium',
            domain: 'barbearia-premium.test',
        );

        app(TenantDatabaseManager::class)->connect($tenant);

        $this->assertTrue(Schema::connection('tenant')->hasTable('clients'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('orders'));
    }
}
