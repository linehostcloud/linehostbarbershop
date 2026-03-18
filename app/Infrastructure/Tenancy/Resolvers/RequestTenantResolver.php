<?php

namespace App\Infrastructure\Tenancy\Resolvers;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;

class RequestTenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        return $this->resolveFromSlugHeader($request)
            ?? $this->resolveFromHost($request);
    }

    private function resolveFromSlugHeader(Request $request): ?Tenant
    {
        $header = config('tenancy.identification.tenant_slug_header', 'X-Tenant-Slug');
        $slug = trim((string) $request->header($header, ''));

        if ($slug === '') {
            return null;
        }

        return Tenant::query()
            ->with('domains')
            ->where('slug', $slug)
            ->first();
    }

    private function resolveFromHost(Request $request): ?Tenant
    {
        $host = mb_strtolower($request->getHost());
        $centralDomains = config('tenancy.central_domains', []);

        if (in_array($host, $centralDomains, true)) {
            return null;
        }

        return Tenant::query()
            ->with('domains')
            ->whereHas('domains', fn ($query) => $query->where('domain', $host))
            ->first();
    }
}
