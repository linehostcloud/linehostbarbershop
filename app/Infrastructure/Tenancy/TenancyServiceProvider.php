<?php

namespace App\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\Resolvers\RequestTenantResolver;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(RequestTenantResolver::class);
        $this->app->singleton(TenantDatabaseManager::class);
        $this->app->singleton(TenantDatabaseProvisioner::class);
    }
}
