<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeOpsDashboardController extends Controller
{
    public function index(Request $request)
    {
        // MVP dev fallback until auth is wired.
        $userId = optional($request->user())->id ?? 1;

        $month = $request->query('month', now()->format('Y-m-01'));
        $monthStart = Carbon::parse($month)->startOfMonth();
        $monthEnd = Carbon::parse($month)->endOfMonth();

        $monthStartString = $monthStart->toDateString();
        $monthEndString = $monthEnd->toDateString();

        $billInstances = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('period_month', $monthStartString)
            ->get()
            ->keyBy('bill_id');

        $billRows = DB::table('bills')
            ->leftJoin('vendors', 'vendors.id', '=', 'bills.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'bills.category_id')
            ->where('bills.user_id', $userId)
            ->where('bills.status', 'active')
            ->orderByRaw('COALESCE(bills.next_due_date, "9999-12-31")')
            ->orderBy('bills.name')
            ->get([
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
                'payee' => $bill->name,
                'vendor_name' => $bill->vendor_name,
                'category_name' => $bill->category_name,
                'due' => $dueDate ? Carbon::parse($dueDate)->format('M j') : 'TBD',
                'due_date' => $dueDate,
                'status' => $status,
                'amount' => $amount !== null ? (float) $amount : null,
            ];
        })->values();

        $expectedBillsTotal = $bills->sum(fn ($bill) => (float) ($bill['amount'] ?? 0));

        $paidBillInstancesTotal = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('period_month', $monthStartString)
            ->where('status', 'paid')
            ->sum('actual_amount');

        $paidBillLedgerTotal = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->where('entry_type', 'bill_payment')
            ->whereIn('status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$monthStartString, $monthEndString])
            ->sum('total_amount');

        $paidTotal = max((float) $paidBillInstancesTotal, (float) $paidBillLedgerTotal);
        $stillDueTotal = max($expectedBillsTotal - $paidTotal, 0);

        $paidBillCount = $bills->filter(fn ($bill) => strtolower($bill['status']) === 'paid')->count();
        $unpaidBillCount = $bills->count() - $paidBillCount;

        $recentLedger = DB::table('ledger_entries')
            ->leftJoin('vendors', 'vendors.id', '=', 'ledger_entries.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->where('ledger_entries.user_id', $userId)
            ->whereBetween('entry_date', [$monthStartString, $monthEndString])
            ->orderByDesc('entry_date')
            ->orderByDesc('ledger_entries.id')
            ->limit(10)
            ->get([
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

        $categoryTotals = DB::table('ledger_entries')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->where('ledger_entries.user_id', $userId)
            ->where('ledger_entries.direction', 'out')
            ->whereIn('ledger_entries.status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$monthStartString, $monthEndString])
            ->groupBy('categories.id', 'categories.name', 'categories.color')
            ->orderByDesc(DB::raw('SUM(total_amount)'))
            ->get([
                'categories.id as category_id',
                'categories.name as category_name',
                'categories.color as category_color',
                DB::raw('SUM(total_amount) as total_amount'),
                DB::raw('COUNT(*) as entry_count'),
            ]);

        $activePeriodsRaw = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where(function ($query) use ($monthStartString, $monthEndString) {
                $query->whereBetween('start_date', [$monthStartString, $monthEndString])
                    ->orWhereBetween('end_date', [$monthStartString, $monthEndString])
                    ->orWhere(function ($nested) use ($monthStartString, $monthEndString) {
                        $nested->where('start_date', '<=', $monthStartString)
                            ->where('end_date', '>=', $monthEndString);
                    });
            })
            ->orderBy('start_date')
            ->get();

        $activePeriods = $activePeriodsRaw->map(function ($period) use ($userId) {
            $amount = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->where('direction', 'out')
                ->whereIn('status', ['paid', 'cleared'])
                ->whereBetween('entry_date', [$period->start_date, $period->end_date])
                ->sum('total_amount');

            return [
                'id' => (int) $period->id,
                'name' => $period->title,
                'title' => $period->title,
                'dates' => Carbon::parse($period->start_date)->format('M j') . '–' . Carbon::parse($period->end_date)->format('M j'),
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'amount' => (float) $amount,
                'description' => $period->notes,
                'tone' => $period->period_type === 'renovation' ? 'tan' : 'red',
                'color' => $period->color,
                'period_type' => $period->period_type,
            ];
        })->values();

        $markedSpendingTotal = $activePeriods->sum(fn ($period) => (float) ($period['amount'] ?? 0));

        $maintenanceDue = DB::table('maintenance_items')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereDate('next_due_date', '<=', $monthEnd->copy()->addDays(30)->toDateString())
            ->orderBy('next_due_date')
            ->limit(8)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'category' => $item->location_label ?: ($item->frequency_count ? 'Every ' . $item->frequency_count . ' ' . $item->frequency_unit : 'Maintenance'),
                    'priority' => ucfirst($item->priority),
                    'amount' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
                    'next_due_date' => $item->next_due_date,
                ];
            });

        $needs = DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->where('item_type', 'need')
            ->whereIn('status', ['idea', 'researching', 'planned'])
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->limit(8)
            ->get();

        $dailyTotals = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->where('direction', 'out')
            ->whereIn('status', ['paid', 'cleared'])
            ->whereBetween('entry_date', [$monthStartString, $monthEndString])
            ->selectRaw('DAY(entry_date) as day_number, SUM(total_amount) as total_amount')
            ->groupBy(DB::raw('DAY(entry_date)'))
            ->pluck('total_amount', 'day_number');

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

        return response()->json([
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
        ]);
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
