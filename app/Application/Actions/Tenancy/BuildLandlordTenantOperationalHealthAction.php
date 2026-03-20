<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Automation\Enums\WhatsappAutomationType;
use App\Domain\Automation\Models\Automation;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BuildLandlordTenantOperationalHealthAction
{
    public function __construct(
        private readonly TenantDatabaseManager $tenantDatabaseManager,
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @return array{
     *     checks:list<array{key:string,label:string,ok:bool,detail:string}>,
     *     schema_missing_tables:list<string>,
     *     summary:array{ok_count:int,total_count:int,pending_count:int}
     * }
     */
    public function execute(Tenant $tenant, array $summary): array
    {
        $inspection = $this->inspectTenantRuntime($tenant, $summary);
        $basicData = $this->inspectBasicData($tenant);

        $checks = [
            [
                'key' => 'database',
                'label' => 'Banco do tenant',
                'ok' => $inspection['database_exists'],
                'detail' => $inspection['database_exists']
                    ? 'O banco do tenant está acessível para operação.'
                    : 'O banco do tenant ainda não está disponível.',
            ],
            [
                'key' => 'schema',
                'label' => 'Schema mínimo',
                'ok' => $inspection['schema_ok'],
                'detail' => $inspection['schema_ok']
                    ? 'As tabelas mínimas do tenant estão presentes.'
                    : $inspection['schema_detail'],
            ],
            [
                'key' => 'primary_domain',
                'label' => 'Domínio principal',
                'ok' => $inspection['domain_ready'],
                'detail' => $inspection['domain_ready']
                    ? 'Há um domínio principal configurado para o tenant.'
                    : 'Ainda não existe domínio principal configurado.',
            ],
            [
                'key' => 'owner',
                'label' => 'Owner ativo',
                'ok' => $inspection['owner_ready'],
                'detail' => $inspection['owner_ready']
                    ? 'Existe um owner ativo vinculado ao tenant.'
                    : 'Ainda não existe owner ativo vinculado ao tenant.',
            ],
            [
                'key' => 'automation_defaults',
                'label' => 'Automações default',
                'ok' => $inspection['automation_defaults_ready'],
                'detail' => $inspection['automation_defaults_ready']
                    ? 'As automações padrão de WhatsApp estão disponíveis.'
                    : $inspection['automation_defaults_detail'],
            ],
            [
                'key' => 'basic_data',
                'label' => 'Dados básicos mínimos',
                'ok' => $basicData['ok'],
                'detail' => $basicData['detail'],
            ],
        ];

        $okCount = collect($checks)->where('ok', true)->count();
        $totalCount = count($checks);

        return [
            'checks' => $checks,
            'schema_missing_tables' => $inspection['missing_tables'],
            'summary' => [
                'ok_count' => $okCount,
                'total_count' => $totalCount,
                'pending_count' => $totalCount - $okCount,
            ],
        ];
    }

    /**
     * @return array{
     *     ok:bool,
     *     detail:string
     * }
     */
    private function inspectBasicData(Tenant $tenant): array
    {
        $missingFields = [];

        if (blank($tenant->trade_name)) {
            $missingFields[] = 'nome fantasia';
        }

        if (blank($tenant->legal_name)) {
            $missingFields[] = 'razão social';
        }

        if (blank($tenant->timezone)) {
            $missingFields[] = 'timezone';
        }

        if (blank($tenant->currency)) {
            $missingFields[] = 'moeda';
        }

        if ($missingFields === []) {
            return [
                'ok' => true,
                'detail' => 'Nome fantasia, razão social, timezone e moeda estão preenchidos.',
            ];
        }

        return [
            'ok' => false,
            'detail' => sprintf('Pendências no cadastro básico: %s.', implode(', ', $missingFields)),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{
     *     database_exists:bool,
     *     schema_ok:bool,
     *     missing_tables:list<string>,
     *     schema_detail:string,
     *     domain_ready:bool,
     *     owner_ready:bool,
     *     automation_defaults_ready:bool,
     *     automation_defaults_detail:string
     * }
     */
    private function inspectTenantRuntime(Tenant $tenant, array $summary): array
    {
        $databaseReady = (bool) data_get($summary, 'provisioning.database_exists', false);
        $domainReady = (bool) data_get($summary, 'provisioning.domain_ready', false);
        $ownerReady = (bool) data_get($summary, 'provisioning.owner_ready', false);

        if (! $databaseReady) {
            return [
                'database_exists' => false,
                'schema_ok' => false,
                'missing_tables' => (array) config('landlord.tenants.schema_required_tables', []),
                'schema_detail' => 'O schema não pode ser validado porque o banco não existe.',
                'domain_ready' => $domainReady,
                'owner_ready' => $ownerReady,
                'automation_defaults_ready' => false,
                'automation_defaults_detail' => 'As automações default dependem de schema válido.',
            ];
        }

        try {
            $this->tenantDatabaseManager->connect($tenant);

            $requiredTables = collect((array) config('landlord.tenants.schema_required_tables', []));
            $missingTables = $requiredTables
                ->filter(fn (string $table): bool => ! Schema::connection('tenant')->hasTable($table))
                ->values()
                ->all();
            $schemaOk = $missingTables === [];

            $automationDefaultsReady = false;

            if (Schema::connection('tenant')->hasTable('automations')) {
                $automationDefaultsReady = Automation::query()
                    ->where('channel', 'whatsapp')
                    ->whereIn('trigger_event', WhatsappAutomationType::values())
                    ->count() === count(WhatsappAutomationType::values());
            }

            return [
                'database_exists' => true,
                'schema_ok' => $schemaOk,
                'missing_tables' => $missingTables,
                'schema_detail' => $schemaOk
                    ? 'As tabelas mínimas do tenant estão presentes.'
                    : sprintf('Faltam tabelas mínimas no tenant: %s.', implode(', ', $missingTables)),
                'domain_ready' => $domainReady,
                'owner_ready' => $ownerReady,
                'automation_defaults_ready' => $automationDefaultsReady,
                'automation_defaults_detail' => $automationDefaultsReady
                    ? 'As automações padrão de WhatsApp estão disponíveis.'
                    : 'As automações padrão de WhatsApp ainda não foram garantidas no tenant.',
            ];
        } catch (Throwable) {
            return [
                'database_exists' => true,
                'schema_ok' => false,
                'missing_tables' => [],
                'schema_detail' => 'Não foi possível validar o schema do tenant neste momento.',
                'domain_ready' => $domainReady,
                'owner_ready' => $ownerReady,
                'automation_defaults_ready' => false,
                'automation_defaults_detail' => 'Não foi possível validar as automações padrão do tenant.',
            ];
        } finally {
            $this->tenantDatabaseManager->disconnect();
        }
    }
}
