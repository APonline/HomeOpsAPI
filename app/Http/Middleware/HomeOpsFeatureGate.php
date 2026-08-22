<?php

namespace App\Http\Middleware;

use App\Support\HomeOpsFeatureAccess;
use Closure;
use Illuminate\Http\Request;

class HomeOpsFeatureGate
{
    public function handle(Request $request, Closure $next, string $featureKey)
    {
        abort_unless(
            HomeOpsFeatureAccess::isEnabled($request->user(), $featureKey),
            403,
            'This HomeOps feature is not enabled for this account.',
        );

        return $next($request);
    }
}
