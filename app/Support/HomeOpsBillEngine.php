<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsBillEngine
{
    public static function ensureMonthInstances(int $userId, ?int $homeId, Carbon $monthStart): int
    {
        if (!Schema::hasTable('bills') || !Schema::hasTable('bill_instances')) {
            return 0;
        }

        $monthStart = $monthStart->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $created = 0;

        $billsQuery = DB::table('bills')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('id');
        HomeOpsV0::unqualifiedHomeFilter($billsQuery, 'bills', $homeId);

        foreach ($billsQuery->get() as $bill) {
            if (!self::occursInMonth($bill, $monthStart, $monthEnd)) {
                continue;
            }

            $dueDate = self::dueDateForMonth($bill, $monthStart, $monthEnd);

            if (!$dueDate) {
                continue;
            }

            $instanceQuery = DB::table('bill_instances')
                ->where('user_id', $userId)
                ->where('bill_id', $bill->id)
                ->where('period_month', $monthStart->toDateString());
            HomeOpsV0::unqualifiedHomeFilter($instanceQuery, 'bill_instances', $homeId);

            $instance = $instanceQuery->first();
            $expectedAmount = $bill->expected_amount !== null ? (float) $bill->expected_amount : null;

            if ($instance) {
                if (!in_array($instance->status, ['paid', 'cleared', 'skipped'], true)) {
                    DB::table('bill_instances')
                        ->where('id', $instance->id)
                        ->update([
                            'due_date' => $dueDate,
                            'expected_amount' => $expectedAmount,
                            'updated_at' => now(),
                        ]);
                }

                continue;
            }

            $payload = [
                'user_id' => $userId,
                'bill_id' => $bill->id,
                'period_month' => $monthStart->toDateString(),
                'due_date' => $dueDate,
                'expected_amount' => $expectedAmount,
                'actual_amount' => null,
                'status' => 'expected',
                'paid_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $payload = HomeOpsV0::addHomeId($payload, 'bill_instances', $homeId);

            DB::table('bill_instances')->insert($payload);
            $created++;
        }

        return $created;
    }

    public static function ensureBillInstance(int $userId, ?int $homeId, int $billId, Carbon $monthStart): ?object
    {
        self::ensureMonthInstances($userId, $homeId, $monthStart);

        $query = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('bill_id', $billId)
            ->where('period_month', $monthStart->copy()->startOfMonth()->toDateString());
        HomeOpsV0::unqualifiedHomeFilter($query, 'bill_instances', $homeId);

        return $query->first();
    }

    public static function dueDateForMonth(object $bill, Carbon $monthStart, Carbon $monthEnd): ?string
    {
        if (!empty($bill->due_day)) {
            $day = min(max((int) $bill->due_day, 1), (int) $monthEnd->format('j'));
            return $monthStart->copy()->day($day)->toDateString();
        }

        if (!empty($bill->next_due_date)) {
            $anchor = Carbon::parse($bill->next_due_date);
            $day = min((int) $anchor->format('j'), (int) $monthEnd->format('j'));
            return $monthStart->copy()->day($day)->toDateString();
        }

        return null;
    }

    private static function occursInMonth(object $bill, Carbon $monthStart, Carbon $monthEnd): bool
    {
        $frequency = strtolower((string) ($bill->frequency ?? 'monthly'));

        if ($frequency === 'monthly') {
            return true;
        }

        if (in_array($frequency, ['weekly', 'biweekly'], true)) {
            return true;
        }

        if ($frequency === 'once') {
            if (!$bill->next_due_date) {
                return true;
            }

            return Carbon::parse($bill->next_due_date)->betweenIncluded($monthStart, $monthEnd);
        }

        $intervalMonths = match ($frequency) {
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };

        if (!$bill->next_due_date) {
            return $frequency === 'monthly';
        }

        $anchor = Carbon::parse($bill->next_due_date)->startOfMonth();

        if ($monthStart->lessThan($anchor)) {
            return false;
        }

        return $anchor->diffInMonths($monthStart) % $intervalMonths === 0;
    }
}
