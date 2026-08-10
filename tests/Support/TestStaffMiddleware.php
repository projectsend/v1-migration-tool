<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for the host's `staff` route middleware, which this package
 * requires but cannot provide. Tests that care about the distinction
 * assert on the alias being applied, not on its behaviour — that is the
 * host's own test surface.
 */
final class TestStaffMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
