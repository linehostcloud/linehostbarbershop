<?php

namespace Tests\Concerns;

use App\Application\Actions\Auth\IssueTenantAccessTokenAction;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait RefreshTenantDatabases
{
    protected string $landlordDatabasePath;

    /**
     * @var list<string>
     */
    protected array $tenantDatabasePaths = [];

    protected function setUpRefreshTenantDatabases(): void
    {
        $this->landlordDatabasePath = database_path('landlord_test.sqlite');

        $this->recreateSqliteDatabase($this->landlordDatabasePath);

        config()->set('database.connections.landlord', array_merge(
            config('database.connections.landlord'),
            [
                'driver' => 'sqlite',
                'database' => $this->landlordDatabasePath,
                'foreign_key_constraints' => true,
            ],
        ));

        config()->set('database.connections.tenant', array_merge(
            config('database.connections.tenant'),
            [
                'driver' => 'sqlite',
                'database' => database_path('tenant_test.sqlite'),
                'foreign_key_constraints' => true,
            ],
        ));

        DB::purge('landlord');
        DB::purge('tenant');

        $this->artisan('migrate:fresh', [
            '--database' => 'landlord',
            '--path' => 'database/migrations/landlord',
        ])->assertExitCode(0);

        $this->beforeApplicationDestroyed(function (): void {
            DB::disconnect('tenant');
            DB::purge('tenant');
            DB::disconnect('landlord');
            DB::purge('landlord');

            $this->deleteDatabaseFile($this->landlordDatabasePath);

            foreach ($this->tenantDatabasePaths as $path) {
                $this->deleteDatabaseFile($path);
            }
        });
    }

    protected function provisionTenant(string $slug = 'barbearia-alpha', ?string $domain = null): Tenant
    {
        $domain ??= "{$slug}.test";
        $databaseFilename = sprintf('tenant_%s.sqlite', str_replace('-', '_', $slug));
        $databasePath = $this->trackTenantDatabase($databaseFilename);

        $this->recreateSqliteDatabase($databasePath);

        $tenant = Tenant::query()->create([
            'legal_name' => strtoupper(str_replace('-', ' ', $slug)).' LTDA',
            'trade_name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'niche' => 'barbershop',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'status' => 'active',
            'onboarding_stage' => 'completed',
            'database_name' => $databaseFilename,
            'database_host' => null,
            'database_port' => null,
            'database_username' => null,
            'database_password_encrypted' => null,
            'activated_at' => now(),
        ]);

        $tenant->domains()->create([
            'domain' => $domain,
            'type' => 'admin',
            'is_primary' => true,
            'ssl_status' => 'active',
            'verified_at' => now(),
        ]);

        config()->set('database.connections.tenant', array_merge(
            config('database.connections.tenant'),
            [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'foreign_key_constraints' => true,
            ],
        ));

        DB::purge('tenant');

        $this->artisan('migrate:fresh', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
        ])->assertExitCode(0);

        return $tenant->fresh(['domains']);
    }

    protected function createTenantUser(
        Tenant $tenant,
        string $role = 'owner',
        ?array $permissions = null,
        ?string $email = null,
        string $password = 'password123',
    ): User {
        $user = User::factory()->create([
            'name' => 'Usuario '.Str::upper(Str::random(4)),
            'email' => $email ?? sprintf('%s@auth.test', Str::lower(Str::random(10))),
            'locale' => 'pt_BR',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => $password,
        ]);

        $tenant->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'role' => $role,
                'is_primary' => $role === 'owner',
                'permissions_json' => $permissions,
                'accepted_at' => now(),
            ],
        );

        return $user->fresh();
    }

    protected function issueTenantAccessToken(Tenant $tenant, User $user, array $abilities = ['*']): string
    {
        return app(IssueTenantAccessTokenAction::class)->execute($user, $tenant, [
            'name' => 'test-suite',
            'abilities' => $abilities,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ])->plainTextToken;
    }

    /**
     * @return array<string, string>
     */
    protected function tenantAuthHeaders(
        Tenant $tenant,
        string $role = 'owner',
        ?array $permissions = null,
        ?User $user = null,
    ): array {
        $user ??= $this->createTenantUser(
            tenant: $tenant,
            role: $role,
            permissions: $permissions,
        );

        return [
            'Authorization' => 'Bearer '.$this->issueTenantAccessToken($tenant, $user),
        ];
    }

    protected function trackTenantDatabase(string $databaseFilename): string
    {
        $databasePath = database_path($databaseFilename);
        $this->tenantDatabasePaths[] = $databasePath;

        return $databasePath;
    }

    private function recreateSqliteDatabase(string $path): void
    {
        $this->deleteDatabaseFile($path);

        touch($path);
    }

    private function deleteDatabaseFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
