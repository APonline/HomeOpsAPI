<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsBillEngine;
use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsV0StatusController extends Controller
{
    public function show(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);
        $monthStart = $period['month_start'];

        $counts = $this->counts($userId, $homeId, $period, $monthStart);
        $schemaChecks = $this->schemaChecks();
        $productChecks = $this->productChecks($counts, $homeId);
        $checks = array_merge($schemaChecks, $productChecks);
        $readyCount = collect($checks)->where('status', 'ready')->count();
        $blocked = collect($checks)->where('status', 'blocked')->values();
        $warning = collect($checks)->where('status', 'warning')->values();
        $v1Ready = $blocked->isEmpty() && $warning->count() <= 2;

        return response()->json([
            'phase' => 'V0 Foundation',
            'next_phase' => 'V1 Capture & Vault',
            'status' => $v1Ready ? 'v0_checkpoint_ready' : ($blocked->isNotEmpty() ? 'blocked' : 'needs_review'),
            'ready_count' => $readyCount,
            'total_count' => count($checks),
            'v1_ready' => $v1Ready,
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => [
                'view_mode' => $period['view_mode'],
                'selected_year' => $period['selected_year'],
                'selected_month' => $period['selected_month'],
                'selected_day' => $period['selected_day'],
                'date_from' => $period['date_from'],
                'date_to' => $period['date_to'],
                'month_start' => $monthStart->toDateString(),
            ],
            'counts' => $counts,
            'checks' => $checks,
            'blockers' => $blocked->pluck('label')->values(),
            'review_items' => $warning->pluck('label')->values(),
            'guidance' => $this->guidance($blocked, $warning, $counts),
        ]);
    }

    private function schemaChecks(): array
    {
        return [
            $this->check('schema.homes', 'Home Identity tables', $this->hasTable('homes') && $this->hasTable('rooms') && $this->hasTable('home_assets'), 'Required before V1 can link docs/OCR/accounts to a property.'),
            $this->check('schema.ownership_timeline', 'Ownership timeline table', $this->hasTable('ownership_events'), 'V0 history layer for move-in, repairs, setup, and upgrades.'),
            $this->check('schema.bill_home_context', 'Bills have home context', $this->hasColumn('bills', 'home_id') && $this->hasColumn('bill_instances', 'home_id'), 'Bills and monthly instances need home_id.'),
            $this->check('schema.core_bill_source', 'Core bills source metadata', $this->hasColumn('bills', 'source_type') && $this->hasColumn('bills', 'source_key') && $this->hasColumn('bills', 'is_core_bill'), 'Prevents duplicated Mortgage / HOA / Internet rows.'),
            $this->check('schema.ledger_home_context', 'Ledger/receipts have home context', $this->hasColumn('ledger_entries', 'home_id') && $this->hasColumn('receipts', 'home_id'), 'Needed before receipt OCR in V1.'),
            $this->check('schema.living_home_context', 'Living modules have home context', $this->hasColumn('maintenance_items', 'home_id') && $this->hasColumn('wishlist_items', 'home_id') && $this->hasColumn('spending_periods', 'home_id'), 'Maintenance, needs/wants and periods should not float globally.'),
            $this->check('schema.room_asset_links', 'Room/asset link columns', $this->hasColumn('maintenance_items', 'asset_id') && $this->hasColumn('wishlist_items', 'room_id') && $this->hasColumn('ledger_entries', 'room_id'), 'V1 documents and receipts need room/asset hooks.'),
            $this->check('schema.budget_profile', 'Budget Lens API table', $this->hasTable('budget_profiles'), 'Saved budget assumptions should survive browsers/devices.'),
        ];
    }

    private function productChecks(array $counts, ?int $homeId): array
    {
        return [
            $this->check('product.home_profile', 'Primary home exists', (bool) $homeId, 'Create or select a home profile.'),
            $this->check('product.baseline_costs', 'Profile has baseline costs', (float) ($counts['baseline_monthly_cost'] ?? 0) > 0, 'Mortgage/HOA/tax/insurance/internet should live in the profile.'),
            $this->check('product.core_bills', 'Core bills are synced', (int) ($counts['core_bills'] ?? 0) > 0, 'Create missing core bills from the Property Profile.'),
            $this->check('product.month_instances', 'Current month bill instances exist', (int) ($counts['bill_instances_this_month'] ?? 0) >= (int) ($counts['active_bills'] ?? 0), 'Open Bills once to generate selected-month instances.', (int) ($counts['active_bills'] ?? 0) === 0),
            $this->check('product.time_records', 'Records respect selected period', (int) ($counts['ledger_entries_in_period'] ?? 0) >= 0 && $this->hasColumn('ledger_entries', 'home_id'), 'Ledger and receipts are queried by Home + Time.'),
            $this->check('product.period_context', 'Spending periods available', (int) ($counts['spending_periods_in_context'] ?? 0) > 0, 'Optional but useful: create Moving Chaos / AC Repair / setup periods.', false, true),
            $this->check('product.rooms_assets', 'Rooms/assets started', ((int) ($counts['rooms'] ?? 0) + (int) ($counts['assets'] ?? 0)) > 0, 'Add starter rooms/assets so V1 docs and OCR have anchors.', false, true),
            $this->check('product.living_ops', 'Living ops started', ((int) ($counts['maintenance_items'] ?? 0) + (int) ($counts['wishlist_items'] ?? 0)) > 0, 'Add at least one maintenance item or need/want before V1 demo.', false, true),
        ];
    }

    private function counts(int $userId, ?int $homeId, array $period, Carbon $monthStart): array
    {
        $counts = [
            'homes' => $this->countRows('homes', $userId, $homeId),
            'rooms' => $this->countRows('rooms', $userId, $homeId),
            'assets' => $this->countRows('home_assets', $userId, $homeId),
            'active_bills' => $this->countRows('bills', $userId, $homeId, fn ($query) => $query->where('status', 'active')),
            'core_bills' => $this->hasColumn('bills', 'is_core_bill')
                ? $this->countRows('bills', $userId, $homeId, fn ($query) => $query->where('is_core_bill', 1))
                : 0,
            'bill_instances_this_month' => $this->countRows('bill_instances', $userId, $homeId, fn ($query) => $query->where('period_month', $monthStart->toDateString())),
            'ledger_entries_in_period' => $this->countRows('ledger_entries', $userId, $homeId, fn ($query) => $query->whereBetween('entry_date', [$period['date_from'], $period['date_to']])),
            'receipts_in_period' => $this->countRows('receipts', $userId, $homeId, fn ($query) => $query->whereBetween('receipt_date', [$period['date_from'], $period['date_to']])),
            'spending_periods_in_context' => $this->countRows('spending_periods', $userId, $homeId, function ($query) use ($period) {
                $query->where('active', 1)->where(function ($nested) use ($period) {
                    $nested->whereBetween('start_date', [$period['date_from'], $period['date_to']])
                        ->orWhereBetween('end_date', [$period['date_from'], $period['date_to']])
                        ->orWhere(function ($inner) use ($period) {
                            $inner->where('start_date', '<=', $period['date_from'])
                                ->where('end_date', '>=', $period['date_to']);
                        });
                });
            }),
            'maintenance_items' => $this->countRows('maintenance_items', $userId, $homeId, fn ($query) => $query->where('status', 'active')),
            'maintenance_due_in_context' => $this->countRows('maintenance_items', $userId, $homeId, fn ($query) => $query->where('status', 'active')->whereNotNull('next_due_date')->whereDate('next_due_date', '<=', $period['date_to'])),
            'wishlist_items' => $this->countRows('wishlist_items', $userId, $homeId, fn ($query) => $query->whereIn('status', ['idea', 'researching', 'planned'])),
            'wishlist_targeted_in_context' => $this->countRows('wishlist_items', $userId, $homeId, fn ($query) => $query->whereIn('status', ['idea', 'researching', 'planned'])->whereBetween('target_date', [$period['date_from'], $period['date_to']])),
        ];

        $counts['baseline_monthly_cost'] = (float) (HomeOpsV0::homeSummary($homeId)['baseline_monthly_cost'] ?? 0);

        if ($homeId && $this->hasTable('bill_instances') && $this->hasTable('bills')) {
            HomeOpsBillEngine::ensureMonthInstances($userId, $homeId, $monthStart);
            $counts['bill_instances_this_month'] = $this->countRows('bill_instances', $userId, $homeId, fn ($query) => $query->where('period_month', $monthStart->toDateString()));
        }

        return $counts;
    }

    private function countRows(string $table, int $userId, ?int $homeId, ?callable $callback = null): int
    {
        if (!$this->hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        if ($this->hasColumn($table, 'user_id')) {
            $query->where('user_id', $userId);
        }

        if ($homeId && $this->hasColumn($table, 'home_id')) {
            $query->where('home_id', $homeId);
        }

        if ($callback) {
            $callback($query);
        }

        return (int) $query->count();
    }

    private function check(string $key, string $label, bool $ready, string $help, bool $allowEmpty = false, bool $warningOnly = false): array
    {
        $status = $ready ? 'ready' : ($allowEmpty || $warningOnly ? 'warning' : 'blocked');

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'help' => $help,
        ];
    }

    private function guidance($blocked, $warning, array $counts): array
    {
        if ($blocked->isNotEmpty()) {
            return [
                'headline' => 'V0 still has blockers.',
                'body' => 'Fix the red checks before starting V1 OCR/documents/accounts. Most blockers are migration/context wiring issues.',
            ];
        }

        if ($warning->isNotEmpty()) {
            return [
                'headline' => 'V0 is structurally ready, but seed a little more real data.',
                'body' => 'You can start V1 soon. Add rooms/assets, one spending period, or a maintenance item so V1 has useful anchors.',
            ];
        }

        return [
            'headline' => 'V0 checkpoint is ready.',
            'body' => 'Home + Time + Records are in place. Next phase can start with Receipt OCR, Document Vault, Account Keeper, and richer reports.',
        ];
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
