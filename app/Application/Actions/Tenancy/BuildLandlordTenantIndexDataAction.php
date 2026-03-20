<?php

namespace App\Application\Actions\Tenancy;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BuildLandlordTenantIndexDataAction
{
    public function __construct(
        private readonly MapLandlordTenantSummaryAction $mapTenantSummary,
    ) {
    }

    public function execute(): LengthAwarePaginator
    {
        $paginator = Tenant::query()
            ->with([
                'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('domain'),
                'memberships.user' => fn ($query) => $query->orderBy('name'),
            ])
            ->latest('created_at')
            ->paginate((int) config('landlord.tenants.list_per_page', 15));

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (Tenant $tenant): array => $this->mapTenantSummary->execute($tenant)
            ),
        );

        return $paginator;
    }
}
