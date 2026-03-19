<?php

namespace Tests\Integration\Tenancy;

use App\Application\Actions\Tenancy\ProvisionTenantAction;
use App\Application\DTOs\TenantProvisioningData;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class ProvisionTenantActionTest extends TestCase
{
    use RefreshTenantDatabases;

    public function test_it_provisions_a_tenant_database_domain_and_owner(): void
    {
        $databaseName = 'tenant_barbearia_centro.sqlite';
        $this->trackTenantDatabase($databaseName);

        $result = app(ProvisionTenantAction::class)->execute(new TenantProvisioningData(
            slug: 'barbearia-centro',
            tradeName: 'Barbearia Centro',
            legalName: 'Barbearia Centro LTDA',
            domain: 'barbearia-centro.tenant.test',
            databaseName: $databaseName,
            ownerName: 'Owner Centro',
            ownerEmail: 'owner@centro.test',
        ));

        $tenant = Tenant::query()
            ->where('slug', 'barbearia-centro')
            ->firstOrFail();

        $owner = User::query()
            ->where('email', 'owner@centro.test')
            ->firstOrFail();

        $this->assertSame($tenant->id, $result->tenant->id);
        $this->assertSame('Barbearia Centro', $tenant->trade_name);
        $this->assertSame($databaseName, $tenant->database_name);
        $this->assertSame('barbearia-centro.tenant.test', $tenant->domains()->value('domain'));
        $this->assertSame($owner->id, $result->owner?->id);
        $this->assertTrue($result->ownerCreated);
        $this->assertNotEmpty($result->temporaryPassword);
        $this->assertTrue($tenant->memberships()->where('user_id', $owner->id)->exists());

        app(TenantDatabaseManager::class)->connect($tenant);

        try {
            $this->assertTrue(Schema::connection('tenant')->hasTable('clients'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('messages'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('event_logs'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('outbox_events'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('integration_attempts'));
            $this->assertTrue(Schema::connection('tenant')->hasTable('whatsapp_provider_configs'));
        } finally {
            app(TenantDatabaseManager::class)->disconnect();
        }
    }
}
