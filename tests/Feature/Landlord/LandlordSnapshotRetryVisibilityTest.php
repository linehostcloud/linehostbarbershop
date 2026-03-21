<?php

namespace Tests\Feature\Landlord;

use App\Application\Actions\Tenancy\ResolveLandlordTenantSnapshotRetryStateAction;
use App\Domain\Tenant\Models\LandlordTenantDetailSnapshot;
use App\Models\User;
use Tests\Concerns\RefreshTenantDatabases;
use Tests\TestCase;

class LandlordSnapshotRetryVisibilityTest extends TestCase
{
    use RefreshTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('landlord.admin_emails', ['saas-admin@test.local']);
        config()->set('tenancy.provisioning.database_prefix', $this->testDatabaseDirectory.DIRECTORY_SEPARATOR.'tenant_');
        config()->set('tenancy.provisioning.default_domain_suffix', 'saas.test');
        config()->set('tenancy.identification.local_browser_domain_suffix', 'saas.test');
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 4);
        config()->set('landlord.tenants.detail_snapshot.retry_backoff_seconds', [60, 300, 900]);
    }

    // -------------------------------------------------------------------------
    // ResolveLandlordTenantSnapshotRetryStateAction
    // -------------------------------------------------------------------------

    public function test_idle_state_when_no_retry(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(retryAttempt: 0);

        $this->assertSame('idle', $result['retry_status']);
        $this->assertFalse($result['is_retrying']);
        $this->assertNull($result['attempt_label']);
        $this->assertSame(0, $result['retry_attempt']);
    }

    public function test_scheduled_state_when_next_retry_in_future(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(
            retryAttempt: 1,
            nextRetryAt: now()->addMinutes(5),
        );

        $this->assertSame('scheduled', $result['retry_status']);
        $this->assertTrue($result['is_retrying']);
        $this->assertSame('1/4', $result['attempt_label']);
        $this->assertNotNull($result['next_retry_in_seconds']);
        $this->assertGreaterThan(0, $result['next_retry_in_seconds']);
        $this->assertNotNull($result['next_retry_in_label']);
    }

    public function test_running_state_when_next_retry_in_past(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(
            retryAttempt: 2,
            nextRetryAt: now()->subMinutes(1),
        );

        $this->assertSame('running', $result['retry_status']);
        $this->assertTrue($result['is_retrying']);
        $this->assertSame('2/4', $result['attempt_label']);
        $this->assertNull($result['next_retry_in_seconds']);
    }

    public function test_exhausted_state_when_retry_exhausted_at_set(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(
            retryAttempt: 4,
            retryExhaustedAt: now()->subMinutes(2),
            lastRefreshError: 'SQLSTATE[HY000] [2002] Connection refused',
        );

        $this->assertSame('exhausted', $result['retry_status']);
        $this->assertFalse($result['is_retrying']);
        $this->assertSame('4/4', $result['attempt_label']);
        $this->assertNotNull($result['retry_exhausted_at']);
        $this->assertNotNull($result['last_error_summary']);
    }

    public function test_retry_max_reflects_config(): void
    {
        config()->set('landlord.tenants.detail_snapshot.retry_max_attempts', 6);

        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(retryAttempt: 3);

        $this->assertSame(6, $result['retry_max']);
        $this->assertSame('3/6', $result['attempt_label']);
    }

    public function test_error_summary_truncated_to_120_chars(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();
        $longError = str_repeat('A', 200);

        $result = $action->execute(
            retryAttempt: 1,
            lastRefreshError: $longError,
        );

        $this->assertSame(120, mb_strlen($result['last_error_summary']));
        $this->assertStringEndsWith('...', $result['last_error_summary']);
    }

    public function test_next_retry_in_label_formats_correctly(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(
            retryAttempt: 2,
            nextRetryAt: now()->addSeconds(330),
        );

        $this->assertSame('scheduled', $result['retry_status']);
        $this->assertNotNull($result['next_retry_in_label']);
        $this->assertMatchesRegularExpression('/\d+min/', $result['next_retry_in_label']);
    }

    public function test_exhausted_takes_precedence_over_scheduled(): void
    {
        $action = new ResolveLandlordTenantSnapshotRetryStateAction();

        $result = $action->execute(
            retryAttempt: 4,
            nextRetryAt: now()->addMinutes(5),
            retryExhaustedAt: now(),
        );

        $this->assertSame('exhausted', $result['retry_status']);
    }

    // -------------------------------------------------------------------------
    // Persistência de retry no snapshot
    // -------------------------------------------------------------------------

    public function test_retry_columns_persist_on_snapshot(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createTenantWithSnapshot($admin);

        $snapshot = LandlordTenantDetailSnapshot::query()
            ->where('tenant_id', $tenant->getKey())
            ->first();

        $nextRetryAt = now()->addMinutes(5);

        $snapshot->update([
            'retry_attempt' => 2,
            'next_retry_at' => $nextRetryAt,
            'retry_exhausted_at' => null,
        ]);

        $snapshot->refresh();

        $this->assertSame(2, $snapshot->retry_attempt);
        $this->assertNotNull($snapshot->next_retry_at);
        $this->assertNull($snapshot->retry_exhausted_at);
    }

    public function test_retry_exhausted_persists_correctly(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createTenantWithSnapshot($admin);

        $snapshot = LandlordTenantDetailSnapshot::query()
            ->where('tenant_id', $tenant->getKey())
            ->first();

        $exhaustedAt = now();

        $snapshot->update([
            'retry_attempt' => 4,
            'next_retry_at' => null,
            'retry_exhausted_at' => $exhaustedAt,
        ]);

        $snapshot->refresh();

        $this->assertSame(4, $snapshot->retry_attempt);
        $this->assertNull($snapshot->next_retry_at);
        $this->assertNotNull($snapshot->retry_exhausted_at);
    }

    public function test_retry_cleared_returns_to_idle(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createTenantWithSnapshot($admin);

        $snapshot = LandlordTenantDetailSnapshot::query()
            ->where('tenant_id', $tenant->getKey())
            ->first();

        $snapshot->update([
            'retry_attempt' => 3,
            'next_retry_at' => now()->addMinutes(5),
            'retry_exhausted_at' => null,
        ]);

        $snapshot->update([
            'retry_attempt' => 0,
            'next_retry_at' => null,
            'retry_exhausted_at' => null,
        ]);

        $snapshot->refresh();

        $action = new ResolveLandlordTenantSnapshotRetryStateAction();
        $result = $action->execute(
            retryAttempt: $snapshot->retry_attempt,
            nextRetryAt: $snapshot->next_retry_at,
            retryExhaustedAt: $snapshot->retry_exhausted_at,
        );

        $this->assertSame('idle', $result['retry_status']);
        $this->assertFalse($result['is_retrying']);
    }

    // -------------------------------------------------------------------------
    // Integração com dashboard (via action)
    // -------------------------------------------------------------------------

    public function test_dashboard_data_includes_retry_scheduled(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createTenantWithSnapshot($admin, [
            'refresh_status' => 'failed',
            'last_refresh_error' => 'Connection refused',
            'retry_attempt' => 1,
            'next_retry_at' => now()->addMinutes(5),
        ]);

        $action = app(\App\Application\Actions\Tenancy\BuildLandlordTenantSnapshotDashboardDataAction::class);
        $result = $action->execute([
            'snapshot_status' => '',
            'tenant_status' => '',
            'search' => '',
            'sort' => 'priority',
            'direction' => 'desc',
        ]);

        $firstTenant = $result['tenants']->first();

        $this->assertNotNull($firstTenant);
        $this->assertSame('scheduled', $firstTenant['retry']['retry_status']);
        $this->assertTrue($firstTenant['retry']['is_retrying']);
        $this->assertSame('1/4', $firstTenant['retry']['attempt_label']);
        $this->assertNotNull($firstTenant['retry']['next_retry_in_label']);
    }

    public function test_dashboard_data_includes_retry_exhausted(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createTenantWithSnapshot($admin, [
            'refresh_status' => 'failed',
            'last_refresh_error' => 'Connection refused',
            'retry_attempt' => 4,
            'next_retry_at' => null,
            'retry_exhausted_at' => now()->subMinutes(2),
        ]);

        $action = app(\App\Application\Actions\Tenancy\BuildLandlordTenantSnapshotDashboardDataAction::class);
        $result = $action->execute([
            'snapshot_status' => '',
            'tenant_status' => '',
            'search' => '',
            'sort' => 'priority',
            'direction' => 'desc',
        ]);

        $firstTenant = $result['tenants']->first();

        $this->assertNotNull($firstTenant);
        $this->assertSame('exhausted', $firstTenant['retry']['retry_status']);
        $this->assertFalse($firstTenant['retry']['is_retrying']);
        $this->assertSame('4/4', $firstTenant['retry']['attempt_label']);
    }

    public function test_dashboard_data_shows_idle_when_no_retry(): void
    {
        $admin = $this->createLandlordAdmin();
        $this->createTenantWithSnapshot($admin, [
            'refresh_status' => 'failed',
            'last_refresh_error' => 'Some error happened',
            'last_refresh_failed_at' => now()->subMinutes(10),
            'retry_attempt' => 0,
        ]);

        $action = app(\App\Application\Actions\Tenancy\BuildLandlordTenantSnapshotDashboardDataAction::class);
        $result = $action->execute([
            'snapshot_status' => '',
            'tenant_status' => '',
            'search' => '',
            'sort' => 'priority',
            'direction' => 'desc',
        ]);

        $firstTenant = $result['tenants']->first();

        $this->assertNotNull($firstTenant);
        $this->assertSame('idle', $firstTenant['retry']['retry_status']);
        $this->assertFalse($firstTenant['retry']['is_retrying']);
    }

    // -------------------------------------------------------------------------
    // Integração com detalhe do tenant
    // -------------------------------------------------------------------------

    public function test_detail_shows_retry_section_when_scheduled(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createTenantWithSnapshot($admin, [
            'refresh_status' => 'failed',
            'last_refresh_error' => 'Connection refused',
            'retry_attempt' => 2,
            'next_retry_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Estado de Retry')
            ->assertSee('Retry agendado')
            ->assertSee('2/4');
    }

    public function test_detail_shows_retry_exhausted(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createTenantWithSnapshot($admin, [
            'refresh_status' => 'failed',
            'last_refresh_error' => 'Connection refused',
            'retry_attempt' => 4,
            'retry_exhausted_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Estado de Retry')
            ->assertSee('Retry esgotado')
            ->assertSee('4/4');
    }

    public function test_detail_hides_retry_section_when_idle(): void
    {
        $admin = $this->createLandlordAdmin();
        $tenant = $this->createTenantWithSnapshot($admin, [
            'refresh_status' => 'ready',
            'retry_attempt' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('landlord.tenants.show', $tenant))
            ->assertOk()
            ->assertDontSee('Estado de Retry');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createLandlordAdmin(): User
    {
        return User::factory()->create([
            'name' => 'SaaS Admin',
            'email' => 'saas-admin@test.local',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);
    }

    private function createTenantWithSnapshot(User $admin, array $snapshotOverrides = []): \App\Domain\Tenant\Models\Tenant
    {
        $tenant = \App\Domain\Tenant\Models\Tenant::query()->create([
            'trade_name' => 'Test Tenant',
            'legal_name' => 'Test Tenant LTDA',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => 'active',
            'database_name' => 'test_db',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'plan_code' => 'starter',
        ]);

        LandlordTenantDetailSnapshot::query()->create(array_merge([
            'tenant_id' => $tenant->getKey(),
            'refresh_status' => 'ready',
            'last_refresh_source' => 'manual',
            'payload_json' => ['provisioning' => [], 'operational' => [], 'suspension_observability' => []],
            'generated_at' => now(),
            'last_refresh_started_at' => now()->subMinutes(1),
            'last_refresh_completed_at' => now(),
            'retry_attempt' => 0,
            'next_retry_at' => null,
            'retry_exhausted_at' => null,
        ], $snapshotOverrides));

        return $tenant;
    }
}
