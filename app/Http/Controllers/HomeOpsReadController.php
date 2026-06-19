<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeOpsReadController extends Controller
{
    public function bills(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;
        $monthStart = Carbon::parse($request->query('month', now()->format('Y-m-01')))->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $billInstances = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('period_month', $monthStart->toDateString())
            ->get()
            ->keyBy('bill_id');

        $bills = DB::table('bills')
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
            ])
            ->map(function ($bill) use ($billInstances, $monthStart, $monthEnd) {
                $instance = $billInstances->get($bill->id);
                $dueDate = $instance?->due_date ?? $this->resolveDueDate($bill, $monthStart, $monthEnd);
                $amount = $instance?->actual_amount ?? $instance?->expected_amount ?? $bill->expected_amount;

                return [
                    'id' => (int) $bill->id,
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
                    'notes' => $bill->notes,
                ];
            });

        return response()->json(['bills' => $bills]);
    }

    public function ledgerEntries(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;
        $monthStart = Carbon::parse($request->query('month', now()->format('Y-m-01')))->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $entries = DB::table('ledger_entries')
            ->leftJoin('vendors', 'vendors.id', '=', 'ledger_entries.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->leftJoin('period_ledger_entries', 'period_ledger_entries.ledger_entry_id', '=', 'ledger_entries.id')
            ->leftJoin('spending_periods', 'spending_periods.id', '=', 'period_ledger_entries.spending_period_id')
            ->where('ledger_entries.user_id', $userId)
            ->whereBetween('ledger_entries.entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderByDesc('ledger_entries.entry_date')
            ->orderByDesc('ledger_entries.id')
            ->get([
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

        $periods = $this->periodsForMonth($userId, $monthStart, $monthEnd);

        return response()->json([
            'entries' => $entries,
            'periods' => $periods,
        ]);
    }

    public function spendingPeriods(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;
        $monthStart = Carbon::parse($request->query('month', now()->format('Y-m-01')))->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        return response()->json([
            'periods' => $this->periodsForMonth($userId, $monthStart, $monthEnd),
        ]);
    }

    public function maintenanceItems(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $items = DB::table('maintenance_items')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByRaw('COALESCE(next_due_date, "9999-12-31")')
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->get();

        return response()->json(['items' => $items]);
    }

    public function wishlistItems(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $items = DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->whereIn('status', ['idea', 'researching', 'planned'])
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderBy('item_type')
            ->orderBy('title')
            ->get();

        return response()->json(['items' => $items]);
    }

    private function periodsForMonth(int $userId, Carbon $monthStart, Carbon $monthEnd)
    {
        $periods = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where(function ($query) use ($monthStart, $monthEnd) {
                $query->whereBetween('start_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('end_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhere(function ($nested) use ($monthStart, $monthEnd) {
                        $nested->where('start_date', '<=', $monthStart->toDateString())
                            ->where('end_date', '>=', $monthEnd->toDateString());
                    });
            })
            ->orderBy('start_date')
            ->get();

        return $periods->map(function ($period) use ($userId) {
            $ledger = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->where('direction', 'out')
                ->whereIn('status', ['paid', 'cleared'])
                ->whereBetween('entry_date', [$period->start_date, $period->end_date]);

            return [
                'id' => (int) $period->id,
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
}
