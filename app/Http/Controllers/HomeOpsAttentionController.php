<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsAttentionController extends Controller
{
    public function index(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $items = collect();

        $this->addPlaidItems($items, $userId, $homeId);
        $this->addReceiptItems($items, $userId, $homeId);
        $this->addBillItems($items, $userId, $homeId);
        $this->addMaintenanceItems($items, $userId, $homeId);
        $this->addInventoryItems($items, $userId, $homeId);

        $limit = max(1, min((int) $request->input('limit', 12), 100));

        $sorted = $items
            ->sortBy(fn ($item) => [
                $item['kind'] === 'review' ? 0 : 1,
                $item['severity'] === 'high' ? 0 : ($item['severity'] === 'medium' ? 1 : 2),
                $item['sort'] ?? 999,
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'summary' => [
                'total' => $sorted->count(),
                'review' => $sorted->where('kind', 'review')->count(),
                'action' => $sorted->where('kind', 'action')->count(),
            ],
            'items' => $sorted->take($limit)->map(fn ($item) => collect($item)->except('sort')->all())->values(),
        ]);
    }

    private function addPlaidItems($items, int $userId, ?int $homeId): void
    {
        if (!Schema::hasTable('plaid_items')) return;

        $query = DB::table('plaid_items')
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->where('status', 'requires_update')
                    ->orWhereNotNull('last_error_code');
            });
        HomeOpsV0::unqualifiedHomeFilter($query, 'plaid_items', $homeId);

        foreach ($query->get(['id', 'institution_name', 'status', 'last_error_code']) as $row) {
            $institution = $row->institution_name ?: 'Bank';
            $items->push([
                'id' => 'plaid-'.$row->id,
                'kind' => 'review',
                'severity' => 'high',
                'title' => 'Reconnect '.$institution,
                'detail' => $row->last_error_code === 'ITEM_LOGIN_REQUIRED'
                    ? 'Your bank needs a quick sign-in before HomeOps can refresh it.'
                    : 'This financial connection needs attention.',
                'page' => 'financing',
                'action_label' => 'Review account',
                'sort' => 10,
            ]);
        }
    }

    private function addReceiptItems($items, int $userId, ?int $homeId): void
    {
        if (Schema::hasTable('receipts') && Schema::hasColumn('receipts', 'ledger_entry_id')) {
            $query = DB::table('receipts')
                ->where('user_id', $userId)
                ->whereNull('ledger_entry_id');
            HomeOpsV0::unqualifiedHomeFilter($query, 'receipts', $homeId);
            $count = $query->count();

            if ($count > 0) {
                $items->push([
                    'id' => 'receipts-unlinked',
                    'kind' => 'review',
                    'severity' => $count >= 5 ? 'high' : 'medium',
                    'title' => $count.' receipt'.($count === 1 ? '' : 's').' need a transaction match',
                    'detail' => 'Receipts should explain a transaction, not create duplicate spending.',
                    'page' => 'receipts',
                    'action_label' => 'Review receipts',
                    'sort' => 20,
                ]);
            }
        }

        if (Schema::hasTable('receipt_scans')) {
            $query = DB::table('receipt_scans')
                ->where('user_id', $userId)
                ->whereNull('receipt_id')
                ->where(function ($query) {
                    $query->whereNotNull('error_message')
                        ->orWhereIn('status', ['failed', 'error', 'review', 'manual_review']);
                });
            HomeOpsV0::unqualifiedHomeFilter($query, 'receipt_scans', $homeId);
            $count = $query->count();

            if ($count > 0) {
                $items->push([
                    'id' => 'receipt-scans-review',
                    'kind' => 'review',
                    'severity' => 'medium',
                    'title' => $count.' receipt scan'.($count === 1 ? '' : 's').' need review',
                    'detail' => 'Extraction was uncertain or incomplete. The captured image is still available.',
                    'page' => 'receipts',
                    'action_label' => 'Review scans',
                    'sort' => 25,
                ]);
            }
        }
    }

    private function addBillItems($items, int $userId, ?int $homeId): void
    {
        if (!Schema::hasTable('bill_instances')) return;

        $query = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->whereNotIn('status', ['paid', 'cleared', 'skipped'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->addDays(7)->toDateString());
        HomeOpsV0::unqualifiedHomeFilter($query, 'bill_instances', $homeId);

        $rows = $query->get(['id', 'due_date', 'expected_amount', 'status']);
        if ($rows->isEmpty()) return;

        $overdue = $rows->filter(fn ($row) => $row->due_date < now()->toDateString())->count();
        $items->push([
            'id' => 'bills-upcoming',
            'kind' => 'action',
            'severity' => $overdue ? 'high' : 'medium',
            'title' => $rows->count().' bill'.($rows->count() === 1 ? '' : 's').' need attention',
            'detail' => $overdue
                ? $overdue.' overdue · others due within 7 days'
                : 'Due within the next 7 days.',
            'page' => 'bills',
            'action_label' => 'Review bills',
            'sort' => 40,
        ]);
    }

    private function addMaintenanceItems($items, int $userId, ?int $homeId): void
    {
        if (!Schema::hasTable('maintenance_items')) return;

        $query = DB::table('maintenance_items')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', now()->toDateString());
        HomeOpsV0::unqualifiedHomeFilter($query, 'maintenance_items', $homeId);
        $count = $query->count();

        if ($count > 0) {
            $items->push([
                'id' => 'maintenance-due',
                'kind' => 'action',
                'severity' => 'medium',
                'title' => $count.' maintenance item'.($count === 1 ? '' : 's').' due',
                'detail' => 'Keep the home from degrading through neglect.',
                'page' => 'maintenance',
                'action_label' => 'Review maintenance',
                'sort' => 50,
            ]);
        }
    }

    private function addInventoryItems($items, int $userId, ?int $homeId): void
    {
        if (!Schema::hasTable('grocery_inventory_slots')) return;

        $query = DB::table('grocery_inventory_slots')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->whereIn('state', ['low', 'missing']);
        HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_inventory_slots', $homeId);

        $rows = $query->get(['id', 'state']);
        if ($rows->isEmpty()) return;

        $missing = $rows->where('state', 'missing')->count();
        $low = $rows->where('state', 'low')->count();
        $items->push([
            'id' => 'inventory-coverage',
            'kind' => 'action',
            'severity' => $missing ? 'medium' : 'low',
            'title' => ($missing + $low).' inventory slot'.(($missing + $low) === 1 ? '' : 's').' need restocking',
            'detail' => $missing.' missing · '.$low.' low',
            'page' => 'inventory',
            'action_label' => 'Open shopping list',
            'sort' => 60,
        ]);
    }
}
