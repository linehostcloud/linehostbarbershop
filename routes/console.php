<?php

use App\Application\Actions\Tenancy\ProvisionTenantAction;
use App\Application\DTOs\TenantProvisioningData;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenancy:migrate-landlord {--fresh : Recria o banco landlord antes de migrar}', function () {
    if (! $this->option('fresh') && Schema::connection('landlord')->hasTable('users')) {
        $column = DB::connection('landlord')->selectOne('SHOW COLUMNS FROM users WHERE Field = ?', ['id']);
        $columnType = strtolower((string) ($column->Type ?? ''));

        if ($columnType !== '' && str_contains($columnType, 'bigint')) {
            $databaseName = DB::connection('landlord')->getDatabaseName();

            $this->error(sprintf(
                'Schema legado detectado no banco landlord "%s": users.id esta em "%s", mas a arquitetura atual exige ULID.',
                $databaseName,
                $columnType,
            ));
            $this->newLine();
            $this->line('Acoes recomendadas:');
            $this->line('1. Configure LANDLORD_DB_DATABASE para um banco exclusivo do landlord.');
            $this->line('2. Se este banco for apenas de desenvolvimento/local, execute: php artisan tenancy:migrate-landlord --fresh');

            return self::FAILURE;
        }
    }

    $migrationCommand = $this->option('fresh') ? 'migrate:fresh' : 'migrate';

    $this->call($migrationCommand, [
        '--database' => 'landlord',
        '--path' => 'database/migrations/landlord',
        '--force' => true,
    ]);
})->purpose('Executa as migrations do banco landlord');

Artisan::command('tenancy:migrate-tenant {tenant : Slug ou ULID do tenant} {--fresh : Recria o banco do tenant antes de migrar}', function (
    string $tenantIdentifier,
    TenantDatabaseManager $databaseManager,
) {
    $tenant = Tenant::query()
        ->where('id', $tenantIdentifier)
        ->orWhere('slug', $tenantIdentifier)
        ->first();

    if ($tenant === null) {
        $this->error(sprintf('Tenant "%s" nao encontrado.', $tenantIdentifier));

        return self::FAILURE;
    }

    $migrationCommand = $this->option('fresh') ? 'migrate:fresh' : 'migrate';

    $databaseManager->connect($tenant);

    try {
        return $this->call($migrationCommand, [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    } finally {
        $databaseManager->disconnect();
    }
})->purpose('Executa as migrations do banco do tenant informado');

Artisan::command('tenancy:provision-tenant
    {slug : Slug unico do tenant}
    {tradeName : Nome fantasia do tenant}
    {--legal-name= : Razao social. Se omitido, usa o nome fantasia}
    {--domain= : Dominio principal. Se omitido, usa o suffix configurado}
    {--database-name= : Nome explicito do banco do tenant}
    {--plan=starter : Codigo do plano comercial}
    {--timezone=America/Sao_Paulo : Timezone principal do tenant}
    {--currency=BRL : Moeda principal}
    {--owner-email= : Email do usuario owner inicial}
    {--owner-name= : Nome do usuario owner inicial}
    {--owner-password= : Senha inicial do owner. Se omitido, sera gerada}', function (
    string $slug,
    string $tradeName,
    ProvisionTenantAction $provisionTenant,
) {
    $domain = $this->option('domain')
        ?: sprintf('%s.%s', $slug, ltrim((string) config('tenancy.provisioning.default_domain_suffix', 'sistemabarbearia.local'), '.'));

    $data = new TenantProvisioningData(
        slug: $slug,
        tradeName: $tradeName,
        legalName: $this->option('legal-name') ?: $tradeName,
        domain: $domain,
        databaseName: $this->option('database-name') ?: null,
        timezone: (string) $this->option('timezone'),
        currency: (string) $this->option('currency'),
        planCode: (string) $this->option('plan'),
        ownerName: $this->option('owner-name') ?: null,
        ownerEmail: $this->option('owner-email') ?: null,
        ownerPassword: $this->option('owner-password') ?: null,
    );

    try {
        $result = $provisionTenant->execute($data);
    } catch (Throwable $throwable) {
        $this->error($throwable->getMessage());

        return self::FAILURE;
    }

    $this->info('Tenant provisionado com sucesso.');
    $this->newLine();
    $this->line(sprintf('Tenant ID: %s', $result->tenant->getKey()));
    $this->line(sprintf('Slug: %s', $result->tenant->slug));
    $this->line(sprintf('Dominio principal: %s', $result->domain));
    $this->line(sprintf('Banco do tenant: %s', $result->databaseName));
    $this->line(sprintf('Plano: %s', $result->tenant->plan_code));

    if ($result->owner !== null) {
        $this->line(sprintf('Owner: %s <%s>', $result->owner->name, $result->owner->email));
        $this->line(sprintf('Owner criado agora: %s', $result->ownerCreated ? 'sim' : 'nao'));
    }

    if ($result->temporaryPassword !== null) {
        $this->warn(sprintf('Senha temporaria do owner: %s', $result->temporaryPassword));
    }

    return self::SUCCESS;
})->purpose('Provisiona tenant, banco, dominio, owner opcional e migrations');
