<?php

namespace App\Infrastructure\Auth;

use App\Domain\Tenant\Models\TenantMembership;

class TenantPermissionMatrix
{
    /**
     * @var array<string, list<string>>
     */
    private const ROLE_ABILITIES = [
        'owner' => ['*'],
        'manager' => [
            'tenant.read',
            'tenant.users.*',
            'clients.*',
            'professionals.*',
            'services.*',
            'appointments.*',
            'orders.*',
            'finance.*',
        ],
        'finance' => [
            'tenant.read',
            'finance.*',
        ],
        'automation_admin' => [
            'tenant.read',
            'clients.read',
            'appointments.read',
            'orders.read',
        ],
        'receptionist' => [
            'tenant.read',
            'clients.*',
            'professionals.read',
            'services.read',
            'appointments.*',
            'orders.*',
            'finance.read',
        ],
        'professional' => [
            'tenant.read',
            'clients.read',
            'professionals.read',
            'services.read',
            'appointments.read',
            'orders.read',
        ],
        'barber' => [
            'tenant.read',
            'clients.read',
            'professionals.read',
            'services.read',
            'appointments.read',
            'orders.read',
        ],
    ];

    /**
     * @return list<string>
     */
    public function grantedAbilities(TenantMembership $membership): array
    {
        $baseAbilities = self::ROLE_ABILITIES[$membership->role] ?? [];
        $customAbilities = array_values(array_filter($membership->permissions_json ?? [], 'is_string'));

        return array_values(array_unique([...$baseAbilities, ...$customAbilities]));
    }

    public function hasAbility(TenantMembership $membership, string $ability): bool
    {
        if (! $membership->isActive()) {
            return false;
        }

        foreach ($this->grantedAbilities($membership) as $grantedAbility) {
            if (
                $grantedAbility === '*'
                || $grantedAbility === $ability
                || (str_ends_with($grantedAbility, '.*') && str_starts_with($ability, substr($grantedAbility, 0, -1)))
            ) {
                return true;
            }
        }

        return false;
    }
}
