<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsV0
{
    public static function userId(Request $request): int
    {
        return optional($request->user())->id ?? 1;
    }

    public static function resolveHomeId(Request $request, int $userId): ?int
    {
        $requestedHomeId = $request->input('home_id', $request->query('home_id'));

        if ($requestedHomeId) {
            return (int) $requestedHomeId;
        }

        return self::primaryHomeId($userId);
    }

    public static function primaryHomeId(int $userId): ?int
    {
        if (!Schema::hasTable('homes')) {
            return null;
        }

        $home = DB::table('homes')
            ->where('user_id', $userId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($home) {
            return (int) $home->id;
        }

        return (int) DB::table('homes')->insertGetId([
            'user_id' => $userId,
            'name' => 'My Home',
            'property_type' => 'townhouse',
            'city_region' => 'Toronto, ON',
            'purchase_date' => '2026-06-05',
            'purchase_price' => 425000,
            'square_footage' => 700,
            'currency' => 'CAD',
            'mortgage_payment' => 1985,
            'hoa_fee' => 727,
            'property_tax' => 220,
            'occupancy_status' => 'owner_occupied',
            'primary_use' => 'primary_residence',
            'is_primary' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function homeSummary(?int $homeId): ?array
    {
        if (!$homeId || !Schema::hasTable('homes')) {
            return null;
        }

        $home = DB::table('homes')->where('id', $homeId)->first();

        if (!$home) {
            return null;
        }

        return [
            'id' => (int) $home->id,
            'name' => $home->name,
            'property_type' => $home->property_type,
            'city_region' => $home->city_region,
            'purchase_date' => $home->purchase_date,
            'purchase_price' => $home->purchase_price !== null ? (float) $home->purchase_price : null,
            'square_footage' => $home->square_footage !== null ? (int) $home->square_footage : null,
            'currency' => $home->currency ?: 'CAD',
            'baseline_monthly_cost' => self::baselineMonthlyCost($home),
            'is_primary' => (bool) $home->is_primary,
        ];
    }

    public static function baselineMonthlyCost(object $home): float
    {
        return (float) ($home->mortgage_payment ?? 0)
            + (float) ($home->hoa_fee ?? 0)
            + (float) ($home->property_tax ?? 0)
            + (float) ($home->insurance ?? 0)
            + (float) ($home->utilities ?? 0)
            + (float) ($home->internet ?? 0)
            + (float) ($home->other_baseline_costs ?? 0);
    }

    public static function homeFilter(Builder $query, string $table, ?int $homeId): Builder
    {
        if ($homeId && Schema::hasColumn($table, 'home_id')) {
            $query->where("{$table}.home_id", $homeId);
        }

        return $query;
    }

    public static function unqualifiedHomeFilter(Builder $query, string $table, ?int $homeId): Builder
    {
        if ($homeId && Schema::hasColumn($table, 'home_id')) {
            $query->where('home_id', $homeId);
        }

        return $query;
    }

    public static function addHomeId(array $payload, string $table, ?int $homeId): array
    {
        if ($homeId && Schema::hasColumn($table, 'home_id')) {
            $payload['home_id'] = $homeId;
        }

        return $payload;
    }

    public static function addRoomId(array $payload, string $table, mixed $roomId): array
    {
        if ($roomId && Schema::hasColumn($table, 'room_id')) {
            $payload['room_id'] = (int) $roomId;
        }

        return $payload;
    }

    public static function addAssetId(array $payload, string $table, mixed $assetId): array
    {
        if ($assetId && Schema::hasColumn($table, 'asset_id')) {
            $payload['asset_id'] = (int) $assetId;
        }

        return $payload;
    }

    public static function period(Request $request): array
    {
        $viewMode = $request->query('view_mode', $request->input('view_mode', 'month'));
        $viewMode = in_array($viewMode, ['year', 'month', 'day', 'all-time'], true) ? $viewMode : 'month';

        $selectedDay = $request->query('selected_day', $request->input('selected_day'));
        $dateFrom = $request->query('date_from', $request->input('date_from'));
        $dateTo = $request->query('date_to', $request->input('date_to'));

        $monthInput = $request->query('month', $request->input('month'));
        $yearInput = $request->query('year', $request->input('year'));

        $anchor = $selectedDay
            ? Carbon::parse($selectedDay)
            : Carbon::parse($monthInput ?: now()->format('Y-m-01'));

        $selectedYear = (int) ($yearInput ?: $anchor->year);
        $selectedMonth = (int) ($request->query('selected_month', $request->input('selected_month', $anchor->month)));

        if (!$dateFrom || !$dateTo) {
            if ($viewMode === 'day') {
                $start = Carbon::parse($selectedDay ?: $anchor->toDateString())->startOfDay();
                $end = $start->copy()->endOfDay();
            } elseif ($viewMode === 'year') {
                $start = Carbon::create($selectedYear, 1, 1)->startOfDay();
                $end = $start->copy()->endOfYear();
            } elseif ($viewMode === 'all-time') {
                $start = Carbon::create(1900, 1, 1)->startOfDay();
                $end = Carbon::create(2100, 12, 31)->endOfDay();
            } else {
                $start = Carbon::create($selectedYear, $selectedMonth, 1)->startOfMonth();
                $end = $start->copy()->endOfMonth();
            }
        } else {
            $start = Carbon::parse($dateFrom)->startOfDay();
            $end = Carbon::parse($dateTo)->endOfDay();
        }

        $monthStart = Carbon::create($selectedYear, $selectedMonth, 1)->startOfMonth();

        return [
            'view_mode' => $viewMode,
            'selected_year' => $selectedYear,
            'selected_month' => $selectedMonth,
            'selected_day' => $selectedDay ?: $anchor->toDateString(),
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'month_start' => $monthStart,
            'month_end' => $monthStart->copy()->endOfMonth(),
        ];
    }
}
