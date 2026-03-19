<?php

use App\Application\Actions\Observability\ReclaimStaleOutboxEventsAction;
use App\Application\Actions\Tenancy\ProvisionTenantAction;
use App\Application\DTOs\TenantProvisioningData;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Application\Actions\Observability\ProcessOutboxEventAction;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderRegistry;
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

Artisan::command('tenancy:process-outbox {--tenant=* : Slugs ou ULIDs de tenants especificos} {--limit=50 : Quantidade maxima de eventos por tenant}', function (
    TenantDatabaseManager $databaseManager,
    ProcessOutboxEventAction $processOutboxEvent,
    ReclaimStaleOutboxEventsAction $reclaimStaleOutboxEvents,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $limit = max(1, (int) $this->option('limit'));
    $autoRunReclaim = (bool) config('observability.outbox.reclaim.auto_run_on_process', true);

    $tenants = Tenant::query()
        ->when(
            $tenantIdentifiers !== [],
            fn ($query) => $query->where(function ($tenantQuery) use ($tenantIdentifiers): void {
                $tenantQuery
                    ->whereIn('id', $tenantIdentifiers)
                    ->orWhereIn('slug', $tenantIdentifiers);
            }),
            fn ($query) => $query->where('status', 'active'),
        )
        ->orderBy('slug')
        ->get();

    if ($tenants->isEmpty()) {
        $this->warn('Nenhum tenant encontrado para processamento do outbox.');

        return self::SUCCESS;
    }

    $totals = [
        'reclaimed' => 0,
        'reconciled' => 0,
        'processed' => 0,
        'retry_scheduled' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    foreach ($tenants as $tenant) {
        $databaseManager->connect($tenant);

        try {
            if ($autoRunReclaim) {
                $reclaimSummary = $reclaimStaleOutboxEvents->execute($limit);

                if ($reclaimSummary['enabled']) {
                    $totals['reclaimed'] += $reclaimSummary['reclaimed'];
                    $totals['reconciled'] += $reclaimSummary['reconciled'];
                    $totals['failed'] += $reclaimSummary['failed'];
                    $totals['skipped'] += $reclaimSummary['skipped'];

                    if (
                        $reclaimSummary['reclaimed'] > 0
                        || $reclaimSummary['reconciled'] > 0
                        || $reclaimSummary['failed'] > 0
                    ) {
                        $this->line(sprintf(
                            '[%s] reclaim reclaimed=%d reconciled=%d failed=%d skipped=%d threshold=%ds',
                            $tenant->slug,
                            $reclaimSummary['reclaimed'],
                            $reclaimSummary['reconciled'],
                            $reclaimSummary['failed'],
                            $reclaimSummary['skipped'],
                            $reclaimSummary['stale_after_seconds'],
                        ));
                    }
                }
            }

            $events = OutboxEvent::query()
                ->whereIn('status', ['pending', 'retry_scheduled'])
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->limit($limit)
                ->get();

            if ($events->isEmpty()) {
                $this->line(sprintf('[%s] nenhum evento pendente.', $tenant->slug));

                continue;
            }

            $tenantCounters = [
                'processed' => 0,
                'retry_scheduled' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];

            foreach ($events as $event) {
                $processedEvent = $processOutboxEvent->execute($event);
                $status = $processedEvent->status;

                if (! array_key_exists($status, $tenantCounters)) {
                    $status = 'skipped';
                }

                $tenantCounters[$status]++;
                $totals[$status]++;
            }

            $this->line(sprintf(
                '[%s] processed=%d retry=%d failed=%d skipped=%d',
                $tenant->slug,
                $tenantCounters['processed'],
                $tenantCounters['retry_scheduled'],
                $tenantCounters['failed'],
                $tenantCounters['skipped'],
            ));
        } finally {
            $databaseManager->disconnect();
        }
    }

    $this->newLine();
    $this->info(sprintf(
        'Totais: reclaimed=%d reconciled=%d processed=%d retry=%d failed=%d skipped=%d',
        $totals['reclaimed'],
        $totals['reconciled'],
        $totals['processed'],
        $totals['retry_scheduled'],
        $totals['failed'],
        $totals['skipped'],
    ));

    return self::SUCCESS;
})->purpose('Processa eventos pendentes do outbox por tenant com retry controlado pelo banco');

Artisan::command('tenancy:reclaim-stale-outbox {--tenant=* : Slugs ou ULIDs de tenants especificos} {--limit=50 : Quantidade maxima de eventos stale por tenant}', function (
    TenantDatabaseManager $databaseManager,
    ReclaimStaleOutboxEventsAction $reclaimStaleOutboxEvents,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $limit = max(1, (int) $this->option('limit'));

    $tenants = Tenant::query()
        ->when(
            $tenantIdentifiers !== [],
            fn ($query) => $query->where(function ($tenantQuery) use ($tenantIdentifiers): void {
                $tenantQuery
                    ->whereIn('id', $tenantIdentifiers)
                    ->orWhereIn('slug', $tenantIdentifiers);
            }),
            fn ($query) => $query->where('status', 'active'),
        )
        ->orderBy('slug')
        ->get();

    if ($tenants->isEmpty()) {
        $this->warn('Nenhum tenant encontrado para reclaim do outbox.');

        return self::SUCCESS;
    }

    $totals = [
        'reclaimed' => 0,
        'reconciled' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    foreach ($tenants as $tenant) {
        $databaseManager->connect($tenant);

        try {
            $summary = $reclaimStaleOutboxEvents->execute($limit);

            if (! $summary['enabled']) {
                $this->warn(sprintf('[%s] reclaim automatico/manual esta desabilitado por configuracao.', $tenant->slug));

                continue;
            }

            $totals['reclaimed'] += $summary['reclaimed'];
            $totals['reconciled'] += $summary['reconciled'];
            $totals['failed'] += $summary['failed'];
            $totals['skipped'] += $summary['skipped'];

            $this->line(sprintf(
                '[%s] reclaimed=%d reconciled=%d failed=%d skipped=%d threshold=%ds max_reclaims=%d backoff=%ds',
                $tenant->slug,
                $summary['reclaimed'],
                $summary['reconciled'],
                $summary['failed'],
                $summary['skipped'],
                $summary['stale_after_seconds'],
                $summary['max_attempts'],
                $summary['backoff_seconds'],
            ));
        } finally {
            $databaseManager->disconnect();
        }
    }

    $this->newLine();
    $this->info(sprintf(
        'Totais reclaim: reclaimed=%d reconciled=%d failed=%d skipped=%d',
        $totals['reclaimed'],
        $totals['reconciled'],
        $totals['failed'],
        $totals['skipped'],
    ));

    return self::SUCCESS;
})->purpose('Recupera com seguranca outbox events stale presos em processing sem reabrir dispatch incerto');

Artisan::command('tenancy:configure-whatsapp-provider
    {tenant : Slug ou ULID do tenant}
    {provider : Provider primario ou secundario}
    {--slot=primary : Slot configurado (primary|secondary)}
    {--fallback-provider= : Provider de fallback estrutural, sem failover automatico}
    {--base-url= : Base URL do provider}
    {--api-version= : Versao da API}
    {--api-key= : API key}
    {--access-token= : Access token}
    {--phone-number-id= : Phone number id}
    {--business-account-id= : Business account id}
    {--instance-name= : Instance name}
    {--webhook-secret= : Secret de webhook}
    {--verify-token= : Verify token}
    {--timeout=10 : Timeout do provider em segundos}
    {--retry-max= : Maximo de tentativas para outbox desse provider}
    {--retry-backoff= : Backoff do provider em segundos}
    {--capability=* : Capabilities habilitadas}
    {--setting=* : Configuracoes extras em key=value}
    {--disable : Desativa a configuracao}', function (
    string $tenant,
    string $provider,
    TenantDatabaseManager $databaseManager,
    WhatsappProviderRegistry $providerRegistry,
    WhatsappProviderConfigValidator $configValidator,
) {
    $tenantModel = Tenant::query()
        ->where('id', $tenant)
        ->orWhere('slug', $tenant)
        ->first();

    if ($tenantModel === null) {
        $this->error(sprintf('Tenant "%s" nao encontrado.', $tenant));

        return self::FAILURE;
    }

    try {
        $providerRegistry->assertRegistered($provider);
    } catch (Throwable $throwable) {
        $this->error($throwable->getMessage());

        return self::FAILURE;
    }

    $databaseManager->connect($tenantModel);

    try {
        $slot = (string) $this->option('slot');
        $capabilities = array_values(array_filter((array) $this->option('capability')));
        $settings = [];

        foreach ((array) $this->option('setting') as $setting) {
            if (! is_string($setting) || ! str_contains($setting, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $setting, 2);
            data_set($settings, trim($key), $value);
        }

        $retryProfile = array_filter([
            'max_attempts' => $this->option('retry-max') !== null ? (int) $this->option('retry-max') : null,
            'retry_backoff_seconds' => $this->option('retry-backoff') !== null ? (int) $this->option('retry-backoff') : null,
        ], static fn (mixed $value): bool => $value !== null);

        $configuration = WhatsappProviderConfig::query()->firstOrNew(['slot' => $slot]);
        $configuration->fill([
            'provider' => $provider,
            'fallback_provider' => $this->option('fallback-provider') ?: null,
            'base_url' => $this->option('base-url') ?: null,
            'api_version' => $this->option('api-version') ?: null,
            'api_key' => $this->option('api-key') ?: null,
            'access_token' => $this->option('access-token') ?: null,
            'phone_number_id' => $this->option('phone-number-id') ?: null,
            'business_account_id' => $this->option('business-account-id') ?: null,
            'instance_name' => $this->option('instance-name') ?: null,
            'webhook_secret' => $this->option('webhook-secret') ?: null,
            'verify_token' => $this->option('verify-token') ?: null,
            'timeout_seconds' => max(1, (int) $this->option('timeout')),
            'retry_profile_json' => $retryProfile !== [] ? $retryProfile : null,
            'enabled_capabilities_json' => $capabilities !== [] ? $capabilities : null,
            'settings_json' => $settings !== [] ? $settings : null,
            'enabled' => ! $this->option('disable'),
        ]);
        $configValidator->assertCanPersist($configuration);
        $configuration->last_validated_at = now();
        $configuration->save();
    } finally {
        $databaseManager->disconnect();
    }

    $this->info('Configuracao de provider WhatsApp salva com sucesso.');
    $this->line(sprintf('Tenant: %s', $tenantModel->slug));
    $this->line(sprintf('Slot: %s', $configuration->slot));
    $this->line(sprintf('Provider: %s', $configuration->provider));
    $this->line(sprintf('Ativo: %s', $configuration->enabled ? 'sim' : 'nao'));

    if ($configuration->base_url !== null) {
        $this->line(sprintf('Base URL: %s', $configuration->base_url));
    }

    return self::SUCCESS;
})->purpose('Configura provider de WhatsApp por tenant sem depender de interface visual');

Artisan::command('tenancy:whatsapp-healthcheck
    {tenant : Slug ou ULID do tenant}
    {--slot=primary : Slot do provider configurado}', function (
    string $tenant,
    TenantDatabaseManager $databaseManager,
    WhatsappProviderRegistry $providerRegistry,
    WhatsappProviderConfigValidator $configValidator,
) {
    $tenantModel = Tenant::query()
        ->where('id', $tenant)
        ->orWhere('slug', $tenant)
        ->first();

    if ($tenantModel === null) {
        $this->error(sprintf('Tenant "%s" nao encontrado.', $tenant));

        return self::FAILURE;
    }

    $databaseManager->connect($tenantModel);

    try {
        $configuration = WhatsappProviderConfig::query()
            ->where('slot', (string) $this->option('slot'))
            ->where('enabled', true)
            ->first();

        if ($configuration === null) {
            $this->error('Nenhuma configuracao ativa encontrada para o slot informado.');

            return self::FAILURE;
        }

        $configValidator->assertCanPersist($configuration);
        $provider = $providerRegistry->resolve($configuration->provider);
        $result = $provider->healthCheck($configuration);
    } catch (Throwable $throwable) {
        $this->error($throwable->getMessage());

        return self::FAILURE;
    } finally {
        $databaseManager->disconnect();
    }

    $this->line(sprintf('Provider: %s', $configuration->provider));
    $this->line(sprintf('Healthy: %s', $result->healthy ? 'sim' : 'nao'));
    $this->line(sprintf('HTTP status: %s', $result->httpStatus !== null ? (string) $result->httpStatus : 'n/a'));
    $this->line(sprintf('Latency (ms): %s', $result->latencyMs !== null ? (string) $result->latencyMs : 'n/a'));

    if ($result->error !== null) {
        $this->error(sprintf(
            'Erro normalizado: %s | %s',
            $result->error->code->value,
            $result->error->message,
        ));
    }

    return $result->healthy ? self::SUCCESS : self::FAILURE;
})->purpose('Executa health check do provider WhatsApp configurado para um tenant');
