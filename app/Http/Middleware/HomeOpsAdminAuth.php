<?php

namespace App\Http\Middleware;

use App\Support\HomeOpsAdminAccess;
use Closure;
use Illuminate\Http\Request;

class HomeOpsAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(HomeOpsAdminAccess::isAdmin($request->user()), 403, 'HomeOps administrator access is required.');

        return $next($request);
    }
}
