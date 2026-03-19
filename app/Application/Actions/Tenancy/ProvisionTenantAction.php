<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Application\DTOs\TenantProvisioningData;
use App\Application\DTOs\TenantProvisioningResult;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Infrastructure\Tenancy\TenantDatabaseProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProvisionTenantAction
{
    public function __construct(
        private readonly TenantDatabaseProvisioner $databaseProvisioner,
        private readonly TenantDatabaseManager $databaseManager,
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaultWhatsappAutomations,
    ) {
    }

    public function execute(TenantProvisioningData $data): TenantProvisioningResult
    {
        $slug = Str::slug($data->slug);

        if ($slug === '') {
            throw new RuntimeException('O slug informado para o tenant e invalido.');
        }

        $databaseName = $data->databaseName ?: $this->databaseProvisioner->makeDatabaseName($slug);
        $createdDatabase = false;

        if ($this->databaseProvisioner->databaseExists($databaseName)) {
            throw new RuntimeException(sprintf(
                'O banco de tenant "%s" ja existe. Escolha outro slug ou defina manualmente um nome de banco.',
                $databaseName,
            ));
        }

        try {
            $this->databaseProvisioner->createDatabase($databaseName);
            $createdDatabase = true;

            return DB::connection(config('tenancy.landlord_connection', 'landlord'))
                ->transaction(function () use ($data, $slug, $databaseName) {
                    $ownerCreated = false;
                    $temporaryPassword = null;
                    $owner = null;

                    $tenant = Tenant::query()->create([
                        'legal_name' => $data->legalName,
                        'trade_name' => $data->tradeName,
                        'slug' => $slug,
                        'niche' => $data->niche,
                        'timezone' => $data->timezone,
                        'currency' => $data->currency,
                        'status' => 'active',
                        'onboarding_stage' => 'provisioned',
                        'database_name' => $databaseName,
                        'database_host' => config('database.connections.'.config('tenancy.tenant_connection', 'tenant').'.host'),
                        'database_port' => config('database.connections.'.config('tenancy.tenant_connection', 'tenant').'.port'),
                        'database_username' => config('database.connections.'.config('tenancy.tenant_connection', 'tenant').'.username'),
                        'database_password_encrypted' => $this->encryptedTenantPassword(),
                        'plan_code' => $data->planCode,
                        'activated_at' => now(),
                    ]);

                    $tenant->domains()->create([
                        'domain' => $data->domain,
                        'type' => 'admin',
                        'is_primary' => true,
                        'ssl_status' => 'pending',
                    ]);

                    if ($data->ownerEmail !== null) {
                        $owner = User::query()
                            ->where('email', $data->ownerEmail)
                            ->first();

                        if ($owner === null) {
                            $temporaryPassword = $data->ownerPassword ?: Str::password(20);

                            $owner = User::query()->create([
                                'name' => $data->ownerName ?: $data->tradeName.' Admin',
                                'email' => $data->ownerEmail,
                                'phone_e164' => null,
                                'locale' => 'pt_BR',
                                'status' => 'active',
                                'email_verified_at' => now(),
                                'password' => Hash::make($temporaryPassword),
                            ]);

                            $ownerCreated = true;
                        }

                        $tenant->memberships()->firstOrCreate(
                            ['user_id' => $owner->id],
                            [
                                'role' => 'owner',
                                'is_primary' => true,
                                'permissions_json' => null,
                                'accepted_at' => now(),
                            ],
                        );
                    }

                    $this->runTenantMigrations($tenant);

                    return new TenantProvisioningResult(
                        tenant: $tenant->fresh(['domains', 'memberships']),
                        databaseName: $databaseName,
                        domain: $data->domain,
                        ownerCreated: $ownerCreated,
                        owner: $owner,
                        temporaryPassword: $temporaryPassword,
                    );
                }, 3);
        } catch (Throwable $throwable) {
            if ($createdDatabase) {
                $this->databaseProvisioner->dropDatabase($databaseName);
            }

            throw $throwable;
        }
    }

    private function encryptedTenantPassword(): ?string
    {
        $password = config('database.connections.'.config('tenancy.tenant_connection', 'tenant').'.password');

        if ($password === null || $password === '') {
            return null;
        }

        return Crypt::encryptString((string) $password);
    }

    private function runTenantMigrations(Tenant $tenant): void
    {
        $this->databaseManager->connect($tenant);

        try {
            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Falha ao executar as migrations do tenant.');
            }

            $this->ensureDefaultWhatsappAutomations->execute();
        } finally {
            $this->databaseManager->disconnect();
        }
    }
}
