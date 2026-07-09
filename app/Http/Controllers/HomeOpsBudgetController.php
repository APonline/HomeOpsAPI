<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsBudgetController extends Controller
{
    public function show(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $periodMonth = $this->periodMonth($request);

        if (!Schema::hasTable('budget_profiles')) {
            return response()->json([
                'budget_profile' => $this->defaultProfile($homeId, $periodMonth, 'migration_missing'),
                'message' => 'Run migrations to enable saved Budget Lens profiles.',
            ]);
        }

        $profile = $this->findProfile($userId, $homeId, $periodMonth)
            ?: $this->findHomeDefaultProfile($userId, $homeId);

        if (!$profile) {
            return response()->json([
                'budget_profile' => $this->defaultProfile($homeId, $periodMonth, 'default'),
            ]);
        }

        return response()->json([
            'budget_profile' => $this->serializeProfile($profile, 'saved'),
        ]);
    }

    public function update(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        abort_unless(Schema::hasTable('budget_profiles'), 503, 'Run migrations to enable saved Budget Lens profiles.');

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'profile_name' => ['nullable', 'string', 'max:140'],
            'period_month' => ['nullable', 'date'],
            'monthly_take_home' => ['nullable', 'numeric', 'min:0'],
            'savings_target' => ['nullable', 'numeric', 'min:0'],
            'discretionary_cap' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'in:home,month'],
        ]);

        $scope = $data['scope'] ?? 'home';
        $periodMonth = $scope === 'month'
            ? $this->periodMonth($request, $data['period_month'] ?? null)
            : null;

        $existing = $scope === 'month'
            ? $this->findProfile($userId, $homeId, $periodMonth)
            : $this->findHomeDefaultProfile($userId, $homeId);

        $payload = [
            'user_id' => $userId,
            'home_id' => $homeId,
            'profile_name' => $data['profile_name'] ?? 'Monthly operating plan',
            'period_month' => $periodMonth,
            'monthly_take_home' => $data['monthly_take_home'] ?? null,
            'savings_target' => $data['savings_target'] ?? null,
            'discretionary_cap' => $data['discretionary_cap'] ?? null,
            'currency' => strtoupper($data['currency'] ?? 'CAD'),
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('budget_profiles')
                ->where('id', $existing->id)
                ->update($payload);

            $profileId = (int) $existing->id;
        } else {
            $payload['created_at'] = now();
            $profileId = (int) DB::table('budget_profiles')->insertGetId($payload);
        }

        $profile = DB::table('budget_profiles')->where('id', $profileId)->first();

        return response()->json([
            'ok' => true,
            'message' => 'Budget Lens saved.',
            'budget_profile' => $this->serializeProfile($profile, 'saved'),
        ]);
    }

    private function findProfile(int $userId, ?int $homeId, ?string $periodMonth): ?object
    {
        return DB::table('budget_profiles')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->when($homeId, fn ($query) => $query->where('home_id', $homeId))
            ->whereDate('period_month', $periodMonth)
            ->orderByDesc('updated_at')
            ->first();
    }

    private function findHomeDefaultProfile(int $userId, ?int $homeId): ?object
    {
        return DB::table('budget_profiles')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->when($homeId, fn ($query) => $query->where('home_id', $homeId))
            ->whereNull('period_month')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function periodMonth(Request $request, ?string $explicitMonth = null): string
    {
        $raw = $explicitMonth
            ?: $request->query('month')
            ?: $request->input('month')
            ?: $request->query('period_month')
            ?: $request->input('period_month')
            ?: now()->format('Y-m-01');

        return Carbon::parse($raw)->startOfMonth()->toDateString();
    }

    private function defaultProfile(?int $homeId, ?string $periodMonth, string $source): array
    {
        return [
            'id' => null,
            'home_id' => $homeId,
            'profile_name' => 'Monthly operating plan',
            'period_month' => $periodMonth,
            'monthly_take_home' => null,
            'savings_target' => null,
            'discretionary_cap' => null,
            'currency' => 'CAD',
            'notes' => null,
            'source' => $source,
        ];
    }

    private function serializeProfile(object $profile, string $source): array
    {
        return [
            'id' => (int) $profile->id,
            'home_id' => $profile->home_id !== null ? (int) $profile->home_id : null,
            'profile_name' => $profile->profile_name,
            'period_month' => $profile->period_month,
            'monthly_take_home' => $profile->monthly_take_home !== null ? (float) $profile->monthly_take_home : null,
            'savings_target' => $profile->savings_target !== null ? (float) $profile->savings_target : null,
            'discretionary_cap' => $profile->discretionary_cap !== null ? (float) $profile->discretionary_cap : null,
            'currency' => $profile->currency ?: 'CAD',
            'notes' => $profile->notes,
            'source' => $source,
            'updated_at' => $profile->updated_at,
        ];
    }
}
