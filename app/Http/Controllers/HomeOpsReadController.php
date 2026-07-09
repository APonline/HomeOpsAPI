<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsBillEngine;
use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeOpsReadController extends Controller
{
    public function bills(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);
        $monthStart = $period['month_start'];
        $monthEnd = $monthStart->copy()->endOfMonth();
        $createdInstances = HomeOpsBillEngine::ensureMonthInstances($userId, $homeId, $monthStart);

        $billInstancesQuery = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('period_month', $monthStart->toDateString());
        HomeOpsV0::unqualifiedHomeFilter($billInstancesQuery, 'bill_instances', $homeId);
        $billInstances = $billInstancesQuery->get()->keyBy('bill_id');

        $billsQuery = DB::table('bills')
            ->leftJoin('vendors', 'vendors.id', '=', 'bills.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'bills.category_id')
            ->where('bills.user_id', $userId)
            ->where('bills.status', 'active')
            ->orderByRaw('COALESCE(bills.next_due_date, "9999-12-31")')
            ->orderBy('bills.name');
        HomeOpsV0::homeFilter($billsQuery, 'bills', $homeId);

        $bills = $billsQuery
            ->get([
                'bills.*',
                'vendors.name as vendor_name',
                'categories.name as category_name',
            ])
            ->map(function ($bill) use ($billInstances, $monthStart, $monthEnd) {
                $instance = $billInstances->get($bill->id);
                $dueDate = $instance?->due_date ?? $this->resolveDueDate($bill, $monthStart, $monthEnd);
                $amount = $instance?->actual_amount ?? $instance?->expected_amount ?? $bill->expected_amount;

                return [
                    'id' => (int) $bill->id,
                    'home_id' => isset($bill->home_id) ? (int) $bill->home_id : null,
                    'instance_id' => $instance?->id ? (int) $instance->id : null,
                    'payee' => $bill->name,
                    'name' => $bill->name,
                    'vendor_name' => $bill->vendor_name,
                    'category_name' => $bill->category_name,
                    'due' => $dueDate ? Carbon::parse($dueDate)->format('M j') : 'TBD',
                    'due_date' => $dueDate,
                    'due_day' => $bill->due_day ? (int) $bill->due_day : null,
                    'status' => $instance ? $this->displayStatus($instance->status) : 'Due',
                    'amount' => $amount !== null ? (float) $amount : null,
                    'expected_amount' => $bill->expected_amount !== null ? (float) $bill->expected_amount : null,
                    'frequency' => $bill->frequency,
                    'autopay' => (bool) $bill->autopay,
                    'is_core_bill' => isset($bill->is_core_bill) ? (bool) $bill->is_core_bill : false,
                    'source_type' => $bill->source_type ?? null,
                    'source_key' => $bill->source_key ?? null,
                    'notes' => $bill->notes,
                ];
            });

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $this->periodPayload($period),
            'bills' => $bills,
            'generated_instances' => $createdInstances,
        ]);
    }

    public function ledgerEntries(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);

        $entriesQuery = DB::table('ledger_entries')
            ->leftJoin('vendors', 'vendors.id', '=', 'ledger_entries.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->leftJoin('period_ledger_entries', 'period_ledger_entries.ledger_entry_id', '=', 'ledger_entries.id')
            ->leftJoin('spending_periods', 'spending_periods.id', '=', 'period_ledger_entries.spending_period_id')
            ->where('ledger_entries.user_id', $userId)
            ->whereBetween('ledger_entries.entry_date', [$period['date_from'], $period['date_to']])
            ->orderByDesc('ledger_entries.entry_date')
            ->orderByDesc('ledger_entries.id');
        HomeOpsV0::homeFilter($entriesQuery, 'ledger_entries', $homeId);

        $entries = $entriesQuery->get([
            'ledger_entries.id',
            'ledger_entries.entry_date',
            'ledger_entries.title',
            'ledger_entries.entry_type',
            'ledger_entries.status',
            'ledger_entries.total_amount',
            'vendors.name as vendor_name',
            'categories.name as category_name',
            'spending_periods.title as period_title',
        ]);

        $periods = $this->periodsForRange($userId, $homeId, $period['date_from'], $period['date_to']);

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $this->periodPayload($period),
            'entries' => $entries,
            'periods' => $periods,
        ]);
    }

    public function spendingPeriods(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $this->periodPayload($period),
            'periods' => $this->periodsForRange($userId, $homeId, $period['date_from'], $period['date_to']),
        ]);
    }

    public function maintenanceItems(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);

        $itemsQuery = DB::table('maintenance_items')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByRaw('COALESCE(next_due_date, "9999-12-31")')
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')");
        HomeOpsV0::unqualifiedHomeFilter($itemsQuery, 'maintenance_items', $homeId);

        $items = $itemsQuery->get()->map(function ($item) use ($period) {
            $dueDate = $item->next_due_date;
            $inPeriod = $dueDate && $dueDate >= $period['date_from'] && $dueDate <= $period['date_to'];
            $overdue = $dueDate && $dueDate < $period['selected_day'];

            return (object) array_merge((array) $item, [
                'in_selected_period' => (bool) $inPeriod,
                'is_overdue' => (bool) $overdue,
                'timing_label' => $overdue ? 'Overdue' : ($inPeriod ? 'In selected period' : 'Tracked'),
            ]);
        });

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $this->periodPayload($period),
            'context' => [
                'due_in_period' => $items->where('in_selected_period', true)->count(),
                'overdue' => $items->where('is_overdue', true)->count(),
                'tracked' => $items->count(),
            ],
            'items' => $items,
        ]);
    }

    public function wishlistItems(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);

        $itemsQuery = DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->whereIn('status', ['idea', 'researching', 'planned'])
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderBy('item_type')
            ->orderBy('title');
        HomeOpsV0::unqualifiedHomeFilter($itemsQuery, 'wishlist_items', $homeId);

        $items = $itemsQuery->get()->map(function ($item) use ($period) {
            $targetDate = $item->target_date;
            $inPeriod = $targetDate && $targetDate >= $period['date_from'] && $targetDate <= $period['date_to'];
            $overdue = $targetDate && $targetDate < $period['selected_day'];

            return (object) array_merge((array) $item, [
                'in_selected_period' => (bool) $inPeriod,
                'is_overdue' => (bool) $overdue,
                'timing_label' => $overdue ? 'Past target' : ($inPeriod ? 'Targeted here' : 'Tracked'),
            ]);
        });

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $this->periodPayload($period),
            'context' => [
                'targeted_in_period' => $items->where('in_selected_period', true)->count(),
                'past_target' => $items->where('is_overdue', true)->count(),
                'tracked' => $items->count(),
            ],
            'items' => $items,
        ]);
    }

    private function periodsForRange(int $userId, ?int $homeId, string $dateFrom, string $dateTo)
    {
        $periodsQuery = DB::table('spending_periods')
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
        HomeOpsV0::unqualifiedHomeFilter($periodsQuery, 'spending_periods', $homeId);
        $periods = $periodsQuery->get();

        return $periods->map(function ($period) use ($userId, $homeId) {
            $ledger = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->where('direction', 'out')
                ->whereIn('status', ['paid', 'cleared'])
                ->whereBetween('entry_date', [$period->start_date, $period->end_date]);
            HomeOpsV0::unqualifiedHomeFilter($ledger, 'ledger_entries', $homeId);

            return [
                'id' => (int) $period->id,
                'home_id' => isset($period->home_id) ? (int) $period->home_id : null,
                'name' => $period->title,
                'title' => $period->title,
                'dates' => Carbon::parse($period->start_date)->format('M j') . '–' . Carbon::parse($period->end_date)->format('M j'),
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'amount' => (float) $ledger->sum('total_amount'),
                'entry_count' => (int) $ledger->count(),
                'notes' => $period->notes,
                'description' => $period->notes,
                'period_type' => $period->period_type,
                'tone' => $period->period_type === 'renovation' ? 'tan' : 'red',
            ];
        })->values();
    }

    private function resolveDueDate($bill, Carbon $monthStart, Carbon $monthEnd): ?string
    {
        if ($bill->due_day) {
            $day = min((int) $bill->due_day, (int) $monthEnd->format('j'));
            return $monthStart->copy()->day($day)->toDateString();
        }

        return $bill->next_due_date;
    }

    private function displayStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'Paid',
            'partial' => 'Partial',
            'missed' => 'Missed',
            'pending', 'expected' => 'Due',
            default => ucfirst($status),
        };
    }

    private function periodPayload(array $period): array
    {
        return [
            'view_mode' => $period['view_mode'],
            'selected_year' => $period['selected_year'],
            'selected_month' => $period['selected_month'],
            'selected_day' => $period['selected_day'],
            'date_from' => $period['date_from'],
            'date_to' => $period['date_to'],
        ];
    }
}
