<?php

namespace App\Application\Actions\Tenancy;

class ResolveLandlordTenantIndexFiltersAction
{
    public const PROVISIONING_PENDING = 'pending';

    public const PRESSURE_SUSPENDED_RECENT = 'suspended_recent';

    /**
     * @return array{
     *     status:string,
     *     onboarding_stage:string,
     *     provisioning:string,
     *     pressure:string
     * }
     */
    public function execute(array $input): array
    {
        return [
            'status' => $this->normalize(
                (string) ($input['status'] ?? ''),
                array_keys($this->statusOptions()),
            ),
            'onboarding_stage' => $this->normalize(
                (string) ($input['onboarding_stage'] ?? ''),
                array_keys($this->onboardingOptions()),
            ),
            'provisioning' => $this->normalize(
                (string) ($input['provisioning'] ?? ''),
                array_keys($this->provisioningOptions()),
            ),
            'pressure' => $this->normalize(
                (string) ($input['pressure'] ?? ''),
                array_keys($this->pressureOptions()),
            ),
        ];
    }

    /**
     * @return array{
     *     status:array<string, string>,
     *     onboarding_stage:array<string, string>,
     *     provisioning:array<string, string>,
     *     pressure:array<string, string>
     * }
     */
    public function options(): array
    {
        return [
            'status' => $this->statusOptions(),
            'onboarding_stage' => $this->onboardingOptions(),
            'provisioning' => $this->provisioningOptions(),
            'pressure' => $this->pressureOptions(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            'trial' => 'Trial',
            'active' => 'Ativo',
            'suspended' => 'Suspenso',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function onboardingOptions(): array
    {
        return [
            'created' => 'Criado',
            'provisioned' => 'Provisionado',
            'completed' => 'Concluído',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function provisioningOptions(): array
    {
        return [
            self::PROVISIONING_PENDING => 'Qualquer pendência operacional',
            'database_missing' => 'Banco pendente',
            'connection_failed' => 'Falha de conexão',
            'schema_pending' => 'Schema pendente',
            'domain_missing' => 'Domínio pendente',
            'owner_missing' => 'Owner pendente',
            'provisioned' => 'Provisionado',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pressureOptions(): array
    {
        return [
            self::PRESSURE_SUSPENDED_RECENT => 'Suspensos com pressão recente',
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalize(string $value, array $allowed): string
    {
        $normalized = trim(mb_strtolower($value));

        return in_array($normalized, $allowed, true) ? $normalized : '';
    }
}
