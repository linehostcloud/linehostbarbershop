<?php

namespace App\Infrastructure\Tenancy\Resolvers;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;

class RequestTenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        return $this->resolveFromSlugHeader($request)
            ?? $this->resolveFromHost($request)
            ?? $this->resolveFromLocalBrowserHost($request);
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

    private function resolveFromLocalBrowserHost(Request $request): ?Tenant
    {
        if ((string) config('app.env', env('APP_ENV', 'production')) !== 'local') {
            return null;
        }

        $host = mb_strtolower($request->getHost());
        $browserSuffix = mb_strtolower(trim((string) config('tenancy.identification.local_browser_domain_suffix', '')));

        if ($browserSuffix === '' || $host === $browserSuffix) {
            return null;
        }

        $suffix = '.'.$browserSuffix;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return null;
        }

        return Tenant::query()
            ->with('domains')
            ->where('slug', $slug)
            ->first();
    }
}
