<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Schema::hasTable('homeops_api_tokens')) {
            return response()->json([
                'message' => 'HomeOps auth is not installed yet. Run the latest migrations.',
            ], 503);
        }

        $plainToken = $request->bearerToken();

        if (!$plainToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = DB::table('homeops_api_tokens')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->first();

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($token->expires_at && Carbon::parse($token->expires_at)->isPast()) {
            return response()->json(['message' => 'Session expired. Please log in again.'], 401);
        }

        $user = User::find($token->user_id);

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (Schema::hasColumn('users', 'account_status') && ($user->account_status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'This HomeOps account is not currently active. Contact support if you need help.',
            ], 403);
        }

        $request->attributes->set('homeops_token_hash', $token->token_hash);
        $request->setUserResolver(fn () => $user);

        DB::table('homeops_api_tokens')
            ->where('id', $token->id)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

        return $next($request);
    }
}
