<?php

namespace App\Infrastructure\Tenancy\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

class TenantCouldNotBeResolved extends RuntimeException
{
    public static function forRequest(Request $request): self
    {
        $host = $request->getHost();
        $slugHeader = config('tenancy.identification.tenant_slug_header', 'X-Tenant-Slug');
        $slug = $request->header($slugHeader);

        $message = $slug
            ? sprintf('Tenant "%s" nao foi encontrado.', $slug)
            : sprintf('Nenhum tenant foi encontrado para o host "%s".', $host);

        return new self($message);
    }
}
