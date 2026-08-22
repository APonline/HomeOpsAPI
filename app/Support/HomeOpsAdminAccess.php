<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class HomeOpsAdminAccess
{
    public static function isAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if (Schema::hasColumn('users', 'is_admin') && (bool) $user->is_admin) {
            return true;
        }

        $configured = collect(explode(',', (string) env('HOMEOPS_ADMIN_EMAILS', '')))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->values();

        return $configured->contains(strtolower((string) $user->email));
    }
}
