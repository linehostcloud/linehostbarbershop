<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Automation\EnsureDefaultWhatsappAutomationsAction;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class MigrateTenantSchemaAction
{
    public function __construct(
        private readonly TenantDatabaseManager $databaseManager,
        private readonly EnsureDefaultWhatsappAutomationsAction $ensureDefaultWhatsappAutomations,
    ) {
    }

    public function execute(Tenant $tenant, bool $fresh = false): void
    {
        $this->databaseManager->connect($tenant);

        try {
            $command = $fresh ? 'migrate:fresh' : 'migrate';
            $exitCode = Artisan::call($command, [
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
