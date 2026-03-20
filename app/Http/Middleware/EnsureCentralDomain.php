<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = mb_strtolower($request->getHost());
        $centralDomains = array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => mb_strtolower(trim($domain)),
            (array) config('tenancy.central_domains', []),
        ))));

        abort_unless(in_array($host, $centralDomains, true), 404);

        return $next($request);
    }
}
