<?php

namespace App\Infrastructure\Auth;

use App\Models\User;

class LandlordPanelAccess
{
    public function canAccess(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        return in_array(
            mb_strtolower(trim($user->email)),
            $this->adminEmails(),
            true,
        );
    }

    /**
     * @return list<string>
     */
    public function adminEmails(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            (array) config('landlord.admin_emails', []),
        ))));
    }
}
