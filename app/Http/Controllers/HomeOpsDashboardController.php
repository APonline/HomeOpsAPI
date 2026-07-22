<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsBillEngine;
use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeOpsDashboardController extends Controller
{
    public function index(Request $request)
    {
        // MVP dev fallback until auth is wired.
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);

        $monthStart = $period['month_start'];
        $monthEnd = $period['month_end'];

        HomeOpsBillEngine::ensureMonthInstances($userId, $homeId, $monthStart);

        $monthStartString = $monthStart->toDateString();
        $monthEndString = $monthEnd->toDateString();
        $dateFrom = $period['date_from'];
        $dateTo = $period['date_to'];

        $billInstancesQuery = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('period_month', $monthStartString);
        HomeOpsV0::unqualifiedHomeFilter($billInstancesQuery, 'bill_instances', $homeId);

        $billInstances = $billInstancesQuery->get()->keyBy('bill_id');

        $billRowsQuery = DB::table('bills')
            ->leftJoin('vendors', 'vendors.id', '=', 'bills.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'bills.category_id')
            ->where('bills.user_id', $userId)
            ->where('bills.status', 'active')
            ->orderByRaw('COALESCE(bills.next_due_date, "9999-12-31")')
            ->orderBy('bills.name');
        HomeOpsV0::homeFilter($billRowsQuery, 'bills', $homeId);

        $billRows = $billRowsQuery->get([
            'bills.*',
            'vendors.name as vendor_name',
            'categories.name as category_name',
        ]);

        $bills = $billRows->map(function ($bill) use ($billInstances, $monthStart, $monthEnd) {
            $instance = $billInstances->get($bill->id);

            $amount = $instance?->actual_amount
                ?? $instance?->expected_amount
                ?? $bill->expected_amount;

            $dueDate = $instance?->due_date
                ?? $this->resolveBillDueDate($bill, $monthStart, $monthEnd);

            $status = $this->resolveBillStatus($bill, $instance, $dueDate, $monthEnd);

            return [
                'id' => (int) $bill->id,
                'home_id' => isset($bill->home_id) ? (int) $bill->home_id : null,
                'payee' => $bill->name,
                'vendor_name' => $bill->vendor_name,
                'category_name' => $bill->category_name,
                'due' => $dueDate ? Carbon::parse($dueDate)->format('M j') : 'TBD',
                'due_date' => $dueDate,
                'status' => $status,
                'amount' => $amount !== null ? (float) $amount : null,
                'frequency' => $bill->frequency,
                'bill_type' => $bill->bill_type ?? ((isset($bill->is_core_bill) && (bool) $bill->is_core_bill) ? 'core' : (($bill->frequency ?? null) === 'once' ? 'one_time' : 'recurring')),
                'is_core_bill' => isset($bill->is_core_bill) ? (bool) $bill->is_core_bill : false,
                'source_type' => $bill->source_type ?? null,
                'source_key' => $bill->source_key ?? null,
                'notes' => $bill->notes,
            ];
        })->values();

        $expectedBillsTotal = $bills->sum(fn ($bill) => (float) ($bill['amount'] ?? 0));

        $paidBillInstancesQuery = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('period_month', $monthStartString)
            ->where('status', 'paid');
        HomeOpsV0::unqualifiedHomeFilter($paidBillInstancesQuery, 'bill_instances', $homeId);
        $paidBillInstancesTotal = $paidBillInstancesQuery->sum('actual_amount');

        $paidBillLedgerQuery = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->where('entry_type', 'bill_payment')
            ->whereIn('status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$monthStartString, $monthEndString]);
        HomeOpsV0::unqualifiedHomeFilter($paidBillLedgerQuery, 'ledger_entries', $homeId);
        $paidBillLedgerTotal = $paidBillLedgerQuery->sum('total_amount');

        $paidTotal = max((float) $paidBillInstancesTotal, (float) $paidBillLedgerTotal);
        $stillDueTotal = max($expectedBillsTotal - $paidTotal, 0);

        $paidBillCount = $bills->filter(fn ($bill) => strtolower($bill['status']) === 'paid')->count();
        $unpaidBillCount = $bills->count() - $paidBillCount;

        $recentLedgerQuery = DB::table('ledger_entries')
            ->leftJoin('vendors', 'vendors.id', '=', 'ledger_entries.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->where('ledger_entries.user_id', $userId)
            ->whereBetween('entry_date', [$dateFrom, $dateTo])
            ->orderByDesc('entry_date')
            ->orderByDesc('ledger_entries.id')
            ->limit(10);
        HomeOpsV0::homeFilter($recentLedgerQuery, 'ledger_entries', $homeId);
        $recentLedger = $recentLedgerQuery->get([
            'ledger_entries.id',
            'ledger_entries.entry_date',
            'ledger_entries.title',
            'ledger_entries.total_amount',
            'ledger_entries.entry_type',
            'ledger_entries.status',
            'vendors.name as vendor_name',
            'categories.name as category_name',
            'categories.color as category_color',
        ]);

        $categoryTotalsQuery = DB::table('ledger_entries')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->where('ledger_entries.user_id', $userId)
            ->where('ledger_entries.direction', 'out')
            ->whereIn('ledger_entries.status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$dateFrom, $dateTo])
            ->groupBy('categories.id', 'categories.name', 'categories.color')
            ->orderByDesc(DB::raw('SUM(total_amount)'));
        HomeOpsV0::homeFilter($categoryTotalsQuery, 'ledger_entries', $homeId);
        $categoryTotals = $categoryTotalsQuery->get([
            'categories.id as category_id',
            'categories.name as category_name',
            'categories.color as category_color',
            DB::raw('SUM(total_amount) as total_amount'),
            DB::raw('COUNT(*) as entry_count'),
        ]);

        $activePeriodsRawQuery = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where(function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('start_date', [$dateFrom, $dateTo])
                    ->orWhereBetween('end_date', [$dateFrom, $dateTo])
                    ->orWhere(function ($nested) use ($dateFrom, $dateTo) {
                        $nested->where('start_date', '<=', $dateFrom)
                            ->where('end_date', '>=', $dateTo);
                    });
            })
            ->orderBy('start_date');
        HomeOpsV0::unqualifiedHomeFilter($activePeriodsRawQuery, 'spending_periods', $homeId);
        $activePeriodsRaw = $activePeriodsRawQuery->get();

        $activePeriods = $activePeriodsRaw->map(function ($period) use ($userId, $homeId) {
            $amountQuery = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->where('direction', 'out')
                ->whereIn('status', ['paid', 'cleared'])
                ->whereBetween('entry_date', [$period->start_date, $period->end_date]);
            HomeOpsV0::unqualifiedHomeFilter($amountQuery, 'ledger_entries', $homeId);
            $amount = $amountQuery->sum('total_amount');

            return [
                'id' => (int) $period->id,
                'home_id' => isset($period->home_id) ? (int) $period->home_id : null,
                'name' => $period->title,
                'title' => $period->title,
                'dates' => Carbon::parse($period->start_date)->format('M j') . '–' . Carbon::parse($period->end_date)->format('M j'),
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'amount' => (float) $amount,
                'description' => $period->notes,
                'tone' => $period->period_type === 'renovation' ? 'tan' : 'red',
                'color' => $period->color ?? null,
                'period_type' => $period->period_type,
            ];
        })->values();

        $markedSpendingTotal = $activePeriods->sum(fn ($period) => (float) ($period['amount'] ?? 0));

        $maintenanceDueQuery = DB::table('maintenance_items')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereDate('next_due_date', '<=', $monthEnd->copy()->addDays(30)->toDateString())
            ->orderBy('next_due_date')
            ->limit(8);
        HomeOpsV0::unqualifiedHomeFilter($maintenanceDueQuery, 'maintenance_items', $homeId);
        $maintenanceDue = $maintenanceDueQuery->get()->map(function ($item) {
            return [
                'id' => (int) $item->id,
                'home_id' => isset($item->home_id) ? (int) $item->home_id : null,
                'asset_id' => isset($item->asset_id) ? (int) $item->asset_id : null,
                'name' => $item->name,
                'category' => $item->location_label ?: ($item->frequency_count ? 'Every ' . $item->frequency_count . ' ' . $item->frequency_unit : 'Maintenance'),
                'priority' => ucfirst($item->priority),
                'amount' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
                'next_due_date' => $item->next_due_date,
            ];
        });

        $needsQuery = DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->where('item_type', 'need')
            ->whereIn('status', ['idea', 'researching', 'planned'])
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->limit(8);
        HomeOpsV0::unqualifiedHomeFilter($needsQuery, 'wishlist_items', $homeId);
        $needs = $needsQuery->get();

        $dailyTotalsQuery = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->whereIn('status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$monthStartString, $monthEndString])
            ->selectRaw('DAY(entry_date) as day_number, SUM(total_amount) as total_amount')
            ->groupBy(DB::raw('DAY(entry_date)'));
        HomeOpsV0::unqualifiedHomeFilter($dailyTotalsQuery, 'ledger_entries', $homeId);
        $dailyTotals = $dailyTotalsQuery->pluck('total_amount', 'day_number');

        $chartDays = [];
        for ($day = 1; $day <= (int) $monthEnd->format('j'); $day++) {
            $date = $monthStart->copy()->day($day)->toDateString();
            $marked = $activePeriodsRaw->contains(function ($period) use ($date) {
                return $date >= $period->start_date && $date <= $period->end_date;
            });

            $chartDays[] = [
                'day' => $day,
                'amount' => (float) ($dailyTotals[$day] ?? 0),
                'marked' => $marked,
            ];
        }

        $annual = $this->annualSummary($userId, $homeId, $period['selected_year'], $expectedBillsTotal, $stillDueTotal);
        $today = $this->todaySummary($userId, $homeId, $period['selected_day']);

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => [
                'view_mode' => $period['view_mode'],
                'selected_year' => $period['selected_year'],
                'selected_month' => $period['selected_month'],
                'selected_day' => $period['selected_day'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'month' => $monthStartString,
            'month_label' => $monthStart->format('F Y'),
            'expected_bills_total' => round($expectedBillsTotal, 2),
            'paid_total' => round($paidTotal, 2),
            'still_due_total' => round($stillDueTotal, 2),
            'marked_spending_total' => round($markedSpendingTotal, 2),
            'paid_bill_count' => $paidBillCount,
            'unpaid_bill_count' => $unpaidBillCount,
            'bills' => $bills,
            'recent_ledger' => $recentLedger,
            'category_totals' => $categoryTotals,
            'active_spending_periods' => $activePeriods,
            'maintenance_due' => $maintenanceDue,
            'needs' => $needs,
            'chart_days' => $chartDays,
            'annual' => $annual,
            'today' => $today,
        ]);
    }

    private function annualSummary(int $userId, ?int $homeId, int $year, float $expectedBillsTotal, float $stillDueTotal): array
    {
        $yearStart = Carbon::create($year, 1, 1)->toDateString();
        $yearEnd = Carbon::create($year, 12, 31)->toDateString();

        $spendQuery = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->whereIn('status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$yearStart, $yearEnd]);
        HomeOpsV0::unqualifiedHomeFilter($spendQuery, 'ledger_entries', $homeId);
        $spendTotal = (float) $spendQuery->sum('total_amount');

        $periodCountQuery = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->whereBetween('start_date', [$yearStart, $yearEnd]);
        HomeOpsV0::unqualifiedHomeFilter($periodCountQuery, 'spending_periods', $homeId);
        $periodCount = (int) $periodCountQuery->count();

        $status = $stillDueTotal > 0 ? 'Tight' : ($spendTotal > ($expectedBillsTotal * 2) ? 'Warning' : 'Good');

        return [
            'year' => $year,
            'status' => $status,
            'spend_total' => round($spendTotal, 2),
            'major_period_count' => $periodCount,
            'expected_monthly_bills' => round($expectedBillsTotal, 2),
        ];
    }

    private function todaySummary(int $userId, ?int $homeId, string $selectedDay): array
    {
        $day = Carbon::parse($selectedDay)->toDateString();

        $spentQuery = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->whereIn('status', ['paid', 'cleared'])
            ->whereDate('entry_date', $day);
        HomeOpsV0::unqualifiedHomeFilter($spentQuery, 'ledger_entries', $homeId);
        $spentToday = (float) $spentQuery->sum('total_amount');

        $dueBillsQuery = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->whereDate('due_date', $day)
            ->whereIn('status', ['expected', 'pending', 'partial']);
        HomeOpsV0::unqualifiedHomeFilter($dueBillsQuery, 'bill_instances', $homeId);

        $maintenanceQuery = DB::table('maintenance_items')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereDate('next_due_date', '<=', $day);
        HomeOpsV0::unqualifiedHomeFilter($maintenanceQuery, 'maintenance_items', $homeId);

        return [
            'date' => $day,
            'spent_total' => round($spentToday, 2),
            'due_bill_count' => (int) $dueBillsQuery->count(),
            'maintenance_due_count' => (int) $maintenanceQuery->count(),
        ];
    }

    private function resolveBillDueDate(object $bill, Carbon $monthStart, Carbon $monthEnd): ?string
    {
        if ($bill->next_due_date) {
            $nextDueDate = Carbon::parse($bill->next_due_date);

            if ($nextDueDate->betweenIncluded($monthStart, $monthEnd)) {
                return $nextDueDate->toDateString();
            }
        }

        if ($bill->due_day) {
            $day = min((int) $bill->due_day, (int) $monthEnd->format('j'));
            return $monthStart->copy()->day($day)->toDateString();
        }

        return $bill->next_due_date ?: null;
    }

    private function resolveBillStatus(object $bill, ?object $instance, ?string $dueDate, Carbon $monthEnd): string
    {
        if ($instance) {
            return match ($instance->status) {
                'paid' => 'Paid',
                'partial' => 'Partial',
                'missed' => 'Missed',
                'pending' => 'Due',
                'expected' => 'Due',
                default => ucfirst($instance->status),
            };
        }

        if ($bill->expected_amount === null) {
            return 'Need amount';
        }

        if ($dueDate && Carbon::parse($dueDate)->greaterThan($monthEnd)) {
            return 'Future';
        }

        return 'Due';
    }
}
