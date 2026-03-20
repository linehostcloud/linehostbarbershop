<?php

namespace App\Application\Actions\Tenancy;

use App\Application\Actions\Auth\RecordAuditLogAction;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransitionLandlordTenantOnboardingStageAction
{
    public function __construct(
        private readonly BuildLandlordTenantStateGovernanceAction $buildStateGovernance,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{onboarding_stage:string,label:string}
     */
    public function execute(Tenant $tenant, User $actor, array $input): array
    {
        $targetStage = (string) $input['onboarding_stage'];
        $reason = trim((string) $input['onboarding_transition_reason']);
        $transition = $this->buildStateGovernance->findOnboardingTransition($tenant, $targetStage);

        if ($transition === null) {
            $governance = $this->buildStateGovernance->execute($tenant);
            $available = collect(data_get($governance, 'onboarding_stage.available', []));

            throw new RuntimeException(
                $available->isEmpty()
                    ? (string) data_get($governance, 'onboarding_stage.unavailable_reason', 'A transição de onboarding solicitada não é permitida para o tenant.')
                    : 'A transição de onboarding solicitada não é permitida para o estágio atual do tenant.',
            );
        }

        return DB::connection(config('tenancy.landlord_connection', 'landlord'))
            ->transaction(function () use ($tenant, $actor, $targetStage, $reason): array {
                $before = [
                    'onboarding_stage' => $tenant->onboarding_stage,
                ];

                $tenant->forceFill([
                    'onboarding_stage' => $targetStage,
                ]);
                $tenant->save();

                $this->recordAuditLog->execute(
                    action: 'landlord_tenant.onboarding_stage_transitioned',
                    tenant: $tenant,
                    actor: $actor,
                    auditableType: 'tenant',
                    auditableId: $tenant->id,
                    before: $before,
                    after: [
                        'onboarding_stage' => $tenant->onboarding_stage,
                    ],
                    metadata: [
                        'source' => 'landlord_web_panel',
                        'reason' => $reason,
                        'from' => $before['onboarding_stage'],
                        'to' => $tenant->onboarding_stage,
                    ],
                );

                return [
                    'onboarding_stage' => $tenant->onboarding_stage,
                    'label' => $this->stageLabel($tenant->onboarding_stage),
                ];
            }, 3);
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'created' => 'Criado',
            'provisioned' => 'Provisionado',
            'completed' => 'Concluído',
            default => ucfirst(str_replace('_', ' ', $stage)),
        };
    }
}
