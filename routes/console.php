<?php

use App\Application\Actions\Agent\RunScheduledWhatsappAgentAction;
use App\Application\Actions\Automation\RunScheduledWhatsappAutomationsAction;
use App\Application\Actions\Observability\ReclaimStaleOutboxEventsAction;
use App\Application\Actions\Observability\RunWhatsappOperationalHousekeepingAction;
use App\Application\Actions\Tenancy\MigrateTenantSchemaAction;
use App\Application\Actions\Tenancy\ProvisionTenantAction;
use App\Application\DTOs\TenantProvisioningData;
use App\Domain\Communication\Models\WhatsappProviderConfig;
use App\Domain\Observability\Models\OutboxEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Application\Actions\Observability\ProcessOutboxEventAction;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderConfigValidator;
use App\Infrastructure\Integration\Whatsapp\WhatsappProviderRegistry;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use App\Infrastructure\Tenancy\TenantExecutionLockManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

if (! function_exists('resolveTenantCommandTargets')) {
    /**
     * @param  list<string>  $tenantIdentifiers
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    function resolveTenantCommandTargets(array $tenantIdentifiers)
    {
        return Tenant::query()
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
    }
}

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
    string $tenant,
    MigrateTenantSchemaAction $migrateTenantSchema,
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
        $migrateTenantSchema->execute($tenantModel, (bool) $this->option('fresh'));
    } catch (Throwable $throwable) {
        $this->error($throwable->getMessage());

        return self::FAILURE;
    }

    $this->info(sprintf('Migrations do tenant "%s" executadas com sucesso.', $tenantModel->slug));

    return self::SUCCESS;
})->purpose('Executa as migrations do banco do tenant informado');

Artisan::command('tenancy:migrate-tenants {--tenant=* : Slugs ou ULIDs de tenants especificos} {--fresh : Recria os bancos dos tenants antes de migrar}', function (
    MigrateTenantSchemaAction $migrateTenantSchema,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $tenants = resolveTenantCommandTargets($tenantIdentifiers);

    if ($tenants->isEmpty()) {
        $this->warn('Nenhum tenant encontrado para migracao.');

        return self::SUCCESS;
    }

    $success = 0;
    $failed = 0;

    foreach ($tenants as $tenant) {
        try {
            $migrateTenantSchema->execute($tenant, (bool) $this->option('fresh'));
            $success++;
            $this->line(sprintf('[%s] migrado com sucesso.', $tenant->slug));
        } catch (Throwable $throwable) {
            $failed++;
            $this->error(sprintf('[%s] %s', $tenant->slug, $throwable->getMessage()));
        }
    }

    $this->newLine();
    $this->info(sprintf('Totais migrations: success=%d failed=%d', $success, $failed));

    return $failed > 0 ? self::FAILURE : self::SUCCESS;
})->purpose('Executa as migrations dos tenants existentes de forma consistente');

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
    $localBrowserSuffix = ltrim((string) config('tenancy.identification.local_browser_domain_suffix', ''), '.');
    $defaultSuffix = app()->environment('local') && $localBrowserSuffix !== ''
        ? $localBrowserSuffix
        : ltrim((string) config('tenancy.provisioning.default_domain_suffix', 'sistema-barbearia.localhost'), '.');

    $domain = $this->option('domain')
        ?: sprintf('%s.%s', $slug, $defaultSuffix);

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
    $tenants = resolveTenantCommandTargets($tenantIdentifiers);

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
    TenantExecutionLockManager $lockManager,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $limit = max(1, (int) $this->option('limit'));
    $tenants = resolveTenantCommandTargets($tenantIdentifiers);

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
        $lock = $lockManager->executeForTenant(
            tenant: $tenant,
            operation: 'whatsapp_reclaim_stale_outbox',
            seconds: max(30, (int) config('communication.whatsapp.execution_locks.reclaim_seconds', 120)),
            callback: function () use ($databaseManager, $reclaimStaleOutboxEvents, $limit, $tenant): array {
                $databaseManager->connect($tenant);

                try {
                    return $reclaimStaleOutboxEvents->execute($limit);
                } finally {
                    $databaseManager->disconnect();
                }
            },
        );

        if (! $lock['acquired']) {
            $this->warn(sprintf('[%s] reclaim ignorado por lock ativo (%s).', $tenant->slug, $lock['lock_key']));

            continue;
        }

        $summary = (array) $lock['result'];

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

Artisan::command('tenancy:process-whatsapp-automations {--tenant=* : Slugs ou ULIDs de tenants especificos} {--type=* : Tipos especificos de automacao} {--limit=100 : Quantidade maxima de candidatos por automacao}', function (
    TenantDatabaseManager $databaseManager,
    RunScheduledWhatsappAutomationsAction $runScheduledWhatsappAutomations,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $types = array_values(array_filter((array) $this->option('type'), 'is_string'));
    $limit = max(1, (int) $this->option('limit'));
    $tenants = resolveTenantCommandTargets($tenantIdentifiers);

    if ($tenants->isEmpty()) {
        $this->warn('Nenhum tenant encontrado para processamento de automacoes WhatsApp.');

        return self::SUCCESS;
    }

    $totals = [
        'automations' => 0,
        'candidates' => 0,
        'queued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'locked' => 0,
    ];

    foreach ($tenants as $tenant) {
        $databaseManager->connect($tenant);

        try {
            $summary = $runScheduledWhatsappAutomations->execute($tenant, $types !== [] ? $types : null, $limit);

            $totals['automations'] += $summary['processed_automations'];
            $totals['candidates'] += $summary['candidates_found'];
            $totals['queued'] += $summary['messages_queued'];
            $totals['skipped'] += $summary['skipped_total'];
            $totals['failed'] += $summary['failed_total'];
            $totals['locked'] += (int) ($summary['skipped_due_to_lock'] ?? false);

            $this->line(sprintf(
                '[%s] automations=%d candidates=%d queued=%d skipped=%d failed=%d scheduler=%s lock=%s',
                $tenant->slug,
                $summary['processed_automations'],
                $summary['candidates_found'],
                $summary['messages_queued'],
                $summary['skipped_total'],
                $summary['failed_total'],
                $summary['scheduler_status'],
                (bool) ($summary['skipped_due_to_lock'] ?? false) ? 'sim' : 'nao',
            ));
        } finally {
            $databaseManager->disconnect();
        }
    }

    $this->newLine();
    $this->info(sprintf(
        'Totais automacoes: automations=%d candidates=%d queued=%d skipped=%d failed=%d locked=%d',
        $totals['automations'],
        $totals['candidates'],
        $totals['queued'],
        $totals['skipped'],
        $totals['failed'],
        $totals['locked'],
    ));

    return self::SUCCESS;
})->purpose('Processa automacoes deterministicas de WhatsApp por tenant sem bypass do pipeline oficial');

Artisan::command('tenancy:run-whatsapp-agent {--tenant=* : Slugs ou ULIDs de tenants especificos}', function (
    TenantDatabaseManager $databaseManager,
    RunScheduledWhatsappAgentAction $runScheduledWhatsappAgent,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $tenants = resolveTenantCommandTargets($tenantIdentifiers);

    if ($tenants->isEmpty()) {
        $this->warn('Nenhum tenant encontrado para execucao do agente operacional de WhatsApp.');

        return self::SUCCESS;
    }

    $totals = [
        'runs' => 0,
        'created' => 0,
        'refreshed' => 0,
        'resolved' => 0,
        'ignored' => 0,
        'active' => 0,
        'locked' => 0,
    ];

    foreach ($tenants as $tenant) {
        $databaseManager->connect($tenant);

        try {
            $summary = $runScheduledWhatsappAgent->execute($tenant);

            $totals['runs']++;
            $totals['created'] += (int) $summary['insights_created'];
            $totals['refreshed'] += (int) $summary['insights_refreshed'];
            $totals['resolved'] += (int) $summary['insights_resolved'];
            $totals['ignored'] += (int) $summary['insights_ignored'];
            $totals['active'] += (int) $summary['active_insights_total'];
            $totals['locked'] += (int) ($summary['skipped_due_to_lock'] ?? false);

            $this->line(sprintf(
                '[%s] run=%s created=%d refreshed=%d resolved=%d ignored=%d active=%d scheduler=%s lock=%s',
                $tenant->slug,
                $summary['agent_run_id'],
                $summary['insights_created'],
                $summary['insights_refreshed'],
                $summary['insights_resolved'],
                $summary['insights_ignored'],
                $summary['active_insights_total'],
                $summary['scheduler_status'],
                (bool) ($summary['skipped_due_to_lock'] ?? false) ? 'sim' : 'nao',
            ));
        } finally {
            $databaseManager->disconnect();
        }
    }

    $this->newLine();
    $this->info(sprintf(
        'Totais agente: runs=%d created=%d refreshed=%d resolved=%d ignored=%d active=%d locked=%d',
        $totals['runs'],
        $totals['created'],
        $totals['refreshed'],
        $totals['resolved'],
        $totals['ignored'],
        $totals['active'],
        $totals['locked'],
    ));

    return self::SUCCESS;
})->purpose('Analisa a operacao WhatsApp por tenant e gera insights auditaveis para o agente operacional');

Artisan::command('tenancy:whatsapp-housekeeping {--tenant=* : Slugs ou ULIDs de tenants especificos} {--limit=200 : Quantidade maxima de registros por limpeza}', function (
    TenantDatabaseManager $databaseManager,
    RunWhatsappOperationalHousekeepingAction $runWhatsappOperationalHousekeeping,
) {
    $tenantIdentifiers = array_values(array_filter((array) $this->option('tenant')));
    $limit = max(1, (int) $this->option('limit'));
    $tenants = resolveTenantCommandTargets($tenantIdentifiers);

    if ($tenants->isEmpty()) {
        $this->warn('Nenhum tenant encontrado para housekeeping operacional do WhatsApp.');

        return self::SUCCESS;
    }

    $totals = [
        'reclaimed' => 0,
        'reconciled' => 0,
        'failed' => 0,
        'pruned_outbox_events' => 0,
        'pruned_automation_runs' => 0,
        'pruned_agent_runs' => 0,
        'pruned_agent_insights' => 0,
        'pruned_event_logs' => 0,
        'pruned_integration_attempts' => 0,
        'locked' => 0,
    ];

    foreach ($tenants as $tenant) {
        $databaseManager->connect($tenant);

        try {
            $summary = $runWhatsappOperationalHousekeeping->execute($tenant, $limit);

            $totals['reclaimed'] += (int) data_get($summary, 'reclaim.reclaimed', 0);
            $totals['reconciled'] += (int) data_get($summary, 'reclaim.reconciled', 0);
            $totals['failed'] += (int) data_get($summary, 'reclaim.failed', 0);
            $totals['pruned_outbox_events'] += (int) data_get($summary, 'pruned.outbox_events', 0);
            $totals['pruned_automation_runs'] += (int) data_get($summary, 'pruned.automation_runs', 0);
            $totals['pruned_agent_runs'] += (int) data_get($summary, 'pruned.agent_runs', 0);
            $totals['pruned_agent_insights'] += (int) data_get($summary, 'pruned.agent_insights', 0);
            $totals['pruned_event_logs'] += (int) data_get($summary, 'pruned.event_logs', 0);
            $totals['pruned_integration_attempts'] += (int) data_get($summary, 'pruned.integration_attempts', 0);
            $totals['locked'] += (int) ($summary['skipped_due_to_lock'] ?? false);

            $this->line(sprintf(
                '[%s] scheduler=%s reclaim=%d/%d failed=%d prune[outbox=%d runs=%d agent_runs=%d insights=%d logs=%d attempts=%d] lock=%s',
                $tenant->slug,
                $summary['scheduler_status'],
                (int) data_get($summary, 'reclaim.reclaimed', 0),
                (int) data_get($summary, 'reclaim.reconciled', 0),
                (int) data_get($summary, 'reclaim.failed', 0),
                (int) data_get($summary, 'pruned.outbox_events', 0),
                (int) data_get($summary, 'pruned.automation_runs', 0),
                (int) data_get($summary, 'pruned.agent_runs', 0),
                (int) data_get($summary, 'pruned.agent_insights', 0),
                (int) data_get($summary, 'pruned.event_logs', 0),
                (int) data_get($summary, 'pruned.integration_attempts', 0),
                (bool) ($summary['skipped_due_to_lock'] ?? false) ? 'sim' : 'nao',
            ));
        } finally {
            $databaseManager->disconnect();
        }
    }

    $this->newLine();
    $this->info(sprintf(
        'Totais housekeeping: reclaimed=%d reconciled=%d failed=%d outbox=%d automation_runs=%d agent_runs=%d insights=%d logs=%d attempts=%d locked=%d',
        $totals['reclaimed'],
        $totals['reconciled'],
        $totals['failed'],
        $totals['pruned_outbox_events'],
        $totals['pruned_automation_runs'],
        $totals['pruned_agent_runs'],
        $totals['pruned_agent_insights'],
        $totals['pruned_event_logs'],
        $totals['pruned_integration_attempts'],
        $totals['locked'],
    ));

    return self::SUCCESS;
})->purpose('Executa reclaim e limpeza segura da observabilidade operacional do WhatsApp por tenant');

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

Schedule::command('tenancy:process-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('tenancy:process-whatsapp-automations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tenancy:run-whatsapp-agent')->everyTenMinutes()->withoutOverlapping();
Schedule::command('tenancy:whatsapp-housekeeping')->hourly()->withoutOverlapping();
