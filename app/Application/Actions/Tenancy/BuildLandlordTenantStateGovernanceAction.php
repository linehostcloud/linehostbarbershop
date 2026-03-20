<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;

class BuildLandlordTenantStateGovernanceAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
        private readonly BuildLandlordTenantOperationalHealthAction $buildOperationalHealth,
    ) {}

    /**
     * @param  array<string, mixed>|null  $summary
     * @param  array<string, mixed>|null  $operational
     * @return array{
     *     status:array{
     *         current:array{code:string,label:string},
     *         available:list<array{target:string,label:string,description:string}>,
     *         unavailable_reason:string|null
     *     },
     *     onboarding_stage:array{
     *         current:array{code:string,label:string},
     *         available:list<array{target:string,label:string,description:string}>,
     *         unavailable_reason:string|null
     *     }
     * }
     */
    public function execute(Tenant $tenant, ?array $summary = null, ?array $operational = null): array
    {
        $tenant->loadMissing([
            'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
            'memberships.user' => fn ($query) => $query->orderBy('name'),
        ]);

        $summary ??= $this->mapTenantSummary->execute($tenant);
        $operational ??= $this->buildOperationalHealth->execute($tenant, $summary);

        $statusTransitions = $this->statusTransitions((string) data_get($summary, 'status.code'));
        $onboardingTransitions = $this->onboardingTransitions(
            currentStage: (string) data_get($summary, 'onboarding_stage.code'),
            summary: $summary,
            operational: $operational,
        );

        return [
            'status' => [
                'current' => data_get($summary, 'status'),
                'available' => $statusTransitions,
                'unavailable_reason' => $statusTransitions === []
                    ? $this->statusUnavailableReason((string) data_get($summary, 'status.code'))
                    : null,
            ],
            'onboarding_stage' => [
                'current' => data_get($summary, 'onboarding_stage'),
                'available' => $onboardingTransitions,
                'unavailable_reason' => $onboardingTransitions === []
                    ? $this->onboardingUnavailableReason(
                        currentStage: (string) data_get($summary, 'onboarding_stage.code'),
                        summary: $summary,
                        operational: $operational,
                    )
                    : null,
            ],
        ];
    }

    /**
     * @return array{target:string,label:string,description:string}|null
     */
    public function findStatusTransition(Tenant $tenant, string $target): ?array
    {
        return collect(data_get($this->execute($tenant), 'status.available', []))
            ->first(fn (array $transition): bool => $transition['target'] === $target);
    }

    /**
     * @return array{target:string,label:string,description:string}|null
     */
    public function findOnboardingTransition(Tenant $tenant, string $target): ?array
    {
        return collect(data_get($this->execute($tenant), 'onboarding_stage.available', []))
            ->first(fn (array $transition): bool => $transition['target'] === $target);
    }

    /**
     * @return list<array{target:string,label:string,description:string}>
     */
    private function statusTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            'trial' => [
                [
                    'target' => 'active',
                    'label' => 'Ativar tenant',
                    'description' => 'Encerra o estado de trial e marca o tenant como operacionalmente ativo.',
                ],
                [
                    'target' => 'suspended',
                    'label' => 'Suspender tenant',
                    'description' => 'Bloqueia administrativamente o tenant sem exclusão nem billing.',
                ],
            ],
            'active' => [[
                'target' => 'suspended',
                'label' => 'Suspender tenant',
                'description' => 'Bloqueia administrativamente o tenant sem exclusão nem billing.',
            ]],
            'suspended' => [[
                'target' => 'active',
                'label' => 'Reativar tenant',
                'description' => 'Remove a suspensão administrativa e devolve o tenant ao estado ativo.',
            ]],
            default => [],
        };
    }

    private function statusUnavailableReason(string $currentStatus): string
    {
        return in_array($currentStatus, ['trial', 'active', 'suspended'], true)
            ? 'Nenhuma transição de status está disponível neste momento.'
            : 'O status atual do tenant não está mapeado para transições administrativas seguras.';
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $operational
     * @return list<array{target:string,label:string,description:string}>
     */
    private function onboardingTransitions(string $currentStage, array $summary, array $operational): array
    {
        return match ($currentStage) {
            'created' => $this->canMarkProvisioned($summary)
                ? [[
                    'target' => 'provisioned',
                    'label' => 'Marcar como provisionado',
                    'description' => 'Confirma que banco, schema, domínio principal e owner ativo já estão prontos.',
                ]]
                : [],
            'provisioned' => $this->canMarkCompleted($operational)
                ? [[
                    'target' => 'completed',
                    'label' => 'Concluir onboarding',
                    'description' => 'Confirma que a governança operacional básica do tenant foi concluída.',
                ]]
                : [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $operational
     */
    private function onboardingUnavailableReason(string $currentStage, array $summary, array $operational): string
    {
        return match ($currentStage) {
            'created' => $this->canMarkProvisioned($summary)
                ? 'Nenhuma transição de onboarding está disponível neste momento.'
                : 'O tenant só pode ser marcado como provisionado quando banco, schema, domínio principal e owner ativo estiverem prontos.',
            'provisioned' => $this->canMarkCompleted($operational)
                ? 'Nenhuma transição de onboarding está disponível neste momento.'
                : 'O onboarding só pode ser concluído quando todos os itens da saúde operacional estiverem OK.',
            'completed' => 'Nenhuma transição de onboarding está disponível para o estágio atual.',
            default => 'O estágio atual de onboarding não está mapeado para transições administrativas seguras.',
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function canMarkProvisioned(array $summary): bool
    {
        return (string) data_get($summary, 'provisioning.code') === 'provisioned';
    }

    /**
     * @param  array<string, mixed>  $operational
     */
    private function canMarkCompleted(array $operational): bool
    {
        return (int) data_get($operational, 'summary.pending_count', 1) === 0;
    }
}
