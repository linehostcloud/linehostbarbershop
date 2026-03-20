<?php

namespace App\Application\Actions\Tenancy;

use App\Application\DTOs\TenantProvisioningData;
use Illuminate\Support\Str;

class BuildTenantProvisioningDataAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): TenantProvisioningData
    {
        $slug = Str::slug((string) ($input['slug'] ?? ''));
        $tradeName = trim((string) ($input['trade_name'] ?? ''));

        return new TenantProvisioningData(
            slug: $slug,
            tradeName: $tradeName,
            legalName: trim((string) ($input['legal_name'] ?? '')) ?: $tradeName,
            domain: $this->resolveDomain($slug, $input['domain'] ?? null),
            databaseName: $this->nullableString($input['database_name'] ?? null),
            timezone: $this->nullableString($input['timezone'] ?? null)
                ?: (string) config('landlord.tenants.defaults.timezone', 'America/Sao_Paulo'),
            currency: $this->nullableString($input['currency'] ?? null)
                ?: (string) config('landlord.tenants.defaults.currency', 'BRL'),
            planCode: $this->nullableString($input['plan_code'] ?? null)
                ?: (string) config('landlord.tenants.defaults.plan_code', 'starter'),
            ownerName: $this->nullableString($input['owner_name'] ?? null),
            ownerEmail: $this->normalizeEmail($input['owner_email'] ?? null),
            ownerPassword: $this->nullableString($input['owner_password'] ?? null),
        );
    }

    public function defaultDomainSuffix(): string
    {
        $localBrowserSuffix = ltrim((string) config('tenancy.identification.local_browser_domain_suffix', ''), '.');

        return app()->environment('local') && $localBrowserSuffix !== ''
            ? $localBrowserSuffix
            : ltrim((string) config('tenancy.provisioning.default_domain_suffix', 'sistema-barbearia.localhost'), '.');
    }

    private function resolveDomain(string $slug, mixed $domain): string
    {
        $normalizedDomain = $this->nullableString($domain);

        if ($normalizedDomain !== null) {
            return mb_strtolower($normalizedDomain);
        }

        return sprintf('%s.%s', $slug, $this->defaultDomainSuffix());
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = $this->nullableString($value);

        return $email !== null ? mb_strtolower($email) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
