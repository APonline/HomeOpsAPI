<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsFeatureAccess
{
    public static function isEnabled(?User $user, string $key): bool
    {
        // During a rolling deploy, never break customer traffic simply because the feature
        // control tables have not migrated yet.
        if (!$user || !Schema::hasTable('homeops_feature_flags')) {
            return true;
        }

        $flag = DB::table('homeops_feature_flags')->where('key', $key)->first();
        if (!$flag) return true;

        if (Schema::hasTable('homeops_feature_flag_overrides')) {
            $override = DB::table('homeops_feature_flag_overrides')
                ->where('feature_flag_id', $flag->id)
                ->where('user_id', $user->id)
                ->first();
            if ($override) return (bool) $override->enabled;
        }

        if (!(bool) $flag->enabled) return false;

        $rollout = max(0, min(100, (int) ($flag->rollout_percentage ?? 100)));
        if ($rollout >= 100) return true;
        if ($rollout <= 0) return false;

        // Stable bucketing: the same customer remains in or out of a rollout between requests.
        $bucket = (int) sprintf('%u', crc32($key.':'.$user->id)) % 100;
        return $bucket < $rollout;
    }

    public static function resolvedFor(?User $user): array
    {
        if (!$user || !Schema::hasTable('homeops_feature_flags')) return [];

        return DB::table('homeops_feature_flags')->orderBy('key')->get(['key', 'name'])->map(fn ($flag) => [
            'key' => $flag->key,
            'name' => $flag->name,
            'enabled' => self::isEnabled($user, $flag->key),
        ])->values()->all();
    }
}
