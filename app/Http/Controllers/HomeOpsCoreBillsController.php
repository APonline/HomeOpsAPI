<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsCoreBillsController extends Controller
{
    public function index(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessReady($userId, $homeId);

        $home = DB::table('homes')
            ->where('id', $homeId)
            ->where('user_id', $userId)
            ->first();

        abort_if(!$home, 404, 'Property not found.');

        return response()->json([
            'home_id' => $homeId,
            'items' => $this->coreItems($home, $userId, $homeId),
        ]);
    }

    public function sync(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessReady($userId, $homeId);

        $period = HomeOpsV0::period($request);
        $monthStart = $period['month_start'];
        $monthEnd = $period['month_end'];

        $home = DB::table('homes')
            ->where('id', $homeId)
            ->where('user_id', $userId)
            ->first();

        abort_if(!$home, 404, 'Property not found.');

        return DB::transaction(function () use ($home, $userId, $homeId, $monthStart, $monthEnd) {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $billIds = [];

            foreach ($this->coreMap() as $item) {
                $amount = $this->amountFromHome($home, $item['key']);

                if ($amount === null || $amount <= 0) {
                    $skipped++;
                    continue;
                }

                $bill = $this->findLinkedBill($userId, $homeId, $item);
                $dueDay = $bill?->due_day ? (int) $bill->due_day : $item['due_day'];
                $dueDate = $this->dueDateForMonth($monthStart, $monthEnd, $dueDay);
                $categoryId = $this->firstOrCreateCategory($userId, 'Bills', 'bill');

                if ($bill) {
                    $this->updateExistingCoreBill($bill, $item, $amount, $categoryId, $dueDay, $dueDate);
                    $billId = (int) $bill->id;
                    $updated++;
                } else {
                    $vendorId = $this->firstOrCreateVendor($userId, $item['label'], 'payee', $categoryId);
                    $billId = $this->createCoreBill($userId, $homeId, $vendorId, $categoryId, $item, $amount, $dueDate);
                    $created++;
                }

                $this->ensureCurrentMonthInstance($userId, $homeId, $billId, $monthStart, $dueDate, $amount);
                $billIds[] = $billId;
            }

            return response()->json([
                'ok' => true,
                'home_id' => $homeId,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'bill_ids' => $billIds,
                'items' => $this->coreItems($home, $userId, $homeId),
                'message' => $created > 0
                    ? 'Core ownership bills created from Property Profile.'
                    : 'Core ownership bills are already linked.',
            ]);
        });
    }

    private function abortUnlessReady(int $userId, int $homeId): void
    {
        abort_unless(Schema::hasTable('homes'), 500, 'Run V0 Property Identity migrations first.');
        abort_unless(Schema::hasTable('bills'), 500, 'Bills table is not available yet.');
        abort_unless(Schema::hasColumn('bills', 'home_id'), 500, 'Run the V0 migration that adds home_id to bills first.');
        abort_unless(Schema::hasColumn('bills', 'source_type') && Schema::hasColumn('bills', 'source_key') && Schema::hasColumn('bills', 'is_core_bill'), 500, 'Run the Core Bills Sync migration first.');
        abort_unless(HomeOpsV0::userCanAccessHome($userId, $homeId), 404, 'Property not found.');
    }

    private function coreItems(object $home, int $userId, int $homeId): array
    {
        return collect($this->coreMap())->map(function ($item) use ($home, $userId, $homeId) {
            $amount = $this->amountFromHome($home, $item['key']);
            $bill = $this->findLinkedBill($userId, $homeId, $item);

            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'source_type' => 'home_baseline',
                'source_key' => $item['key'],
                'amount' => $amount,
                'default_due_day' => $item['due_day'],
                'linked' => (bool) $bill,
                'bill_id' => $bill?->id ? (int) $bill->id : null,
                'bill_name' => $bill?->name,
                'bill_status' => $bill?->status,
                'action' => ($amount && $amount > 0) ? ($bill ? 'linked' : 'create') : 'empty',
            ];
        })->values()->all();
    }

    private function coreMap(): array
    {
        return [
            [
                'key' => 'mortgage_payment',
                'label' => 'Mortgage',
                'due_day' => 1,
                'aliases' => ['Mortgage', 'Mortgage Payment'],
            ],
            [
                'key' => 'hoa_fee',
                'label' => 'HOA / Condo',
                'due_day' => 1,
                'aliases' => ['HOA / Condo', 'HOA', 'Condo Fee', 'Condo Fees', 'Condo'],
            ],
            [
                'key' => 'property_tax',
                'label' => 'Property Tax',
                'due_day' => 1,
                'aliases' => ['Property Tax', 'Property Taxes', 'Tax'],
            ],
            [
                'key' => 'insurance',
                'label' => 'Insurance',
                'due_day' => 1,
                'aliases' => ['Insurance', 'Home Insurance', 'Condo Insurance'],
            ],
            [
                'key' => 'utilities',
                'label' => 'Utilities',
                'due_day' => 15,
                'aliases' => ['Utilities', 'Utility'],
            ],
            [
                'key' => 'internet',
                'label' => 'Internet',
                'due_day' => 15,
                'aliases' => ['Internet', 'Beanfield', 'Rogers', 'Bell'],
            ],
        ];
    }

    private function amountFromHome(object $home, string $key): ?float
    {
        if (!property_exists($home, $key) || $home->{$key} === null || $home->{$key} === '') {
            return null;
        }

        return round((float) $home->{$key}, 2);
    }

    private function findLinkedBill(int $userId, int $homeId, array $item): ?object
    {
        $sourceQuery = DB::table('bills')
            ->where('user_id', $userId)
            ->where('source_type', 'home_baseline')
            ->where('source_key', $item['key'])
            ->orderByRaw("FIELD(status, 'active') DESC")
            ->orderBy('id');
        HomeOpsV0::unqualifiedHomeFilter($sourceQuery, 'bills', $homeId);

        $sourceBill = $sourceQuery->first();

        if ($sourceBill) {
            return $sourceBill;
        }

        $aliasQuery = DB::table('bills')
            ->where('user_id', $userId)
            ->whereIn('name', $item['aliases'])
            ->orderByRaw("FIELD(status, 'active') DESC")
            ->orderBy('id');
        HomeOpsV0::unqualifiedHomeFilter($aliasQuery, 'bills', $homeId);

        return $aliasQuery->first();
    }

    private function updateExistingCoreBill(object $bill, array $item, float $amount, int $categoryId, int $dueDay, string $dueDate): void
    {
        $payload = [
            'category_id' => $bill->category_id ?: $categoryId,
            'frequency' => $bill->frequency ?: 'monthly',
            'expected_amount' => $amount,
            'variable_amount' => 0,
            'due_day' => $bill->due_day ?: $dueDay,
            'next_due_date' => $bill->next_due_date ?: $dueDate,
            'status' => 'active',
            'source_type' => 'home_baseline',
            'source_key' => $item['key'],
            'is_core_bill' => 1,
            'updated_at' => now(),
        ];

        if (!$bill->notes) {
            $payload['notes'] = 'Core ownership bill synced from Property Profile baseline.';
        }

        DB::table('bills')
            ->where('id', $bill->id)
            ->update($payload);
    }

    private function createCoreBill(int $userId, int $homeId, int $vendorId, int $categoryId, array $item, float $amount, string $dueDate): int
    {
        $payload = [
            'user_id' => $userId,
            'vendor_id' => $vendorId,
            'category_id' => $categoryId,
            'name' => $item['label'],
            'frequency' => 'monthly',
            'expected_amount' => $amount,
            'variable_amount' => 0,
            'due_day' => $item['due_day'],
            'next_due_date' => $dueDate,
            'autopay' => 0,
            'status' => 'active',
            'source_type' => 'home_baseline',
            'source_key' => $item['key'],
            'is_core_bill' => 1,
            'notes' => 'Core ownership bill synced from Property Profile baseline. Edit due day/autopay on the Bills page.',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload = HomeOpsV0::addHomeId($payload, 'bills', $homeId);

        return (int) DB::table('bills')->insertGetId($payload);
    }

    private function ensureCurrentMonthInstance(int $userId, int $homeId, int $billId, Carbon $monthStart, string $dueDate, float $amount): void
    {
        $instanceQuery = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('bill_id', $billId)
            ->where('period_month', $monthStart->toDateString());
        HomeOpsV0::unqualifiedHomeFilter($instanceQuery, 'bill_instances', $homeId);

        $instance = $instanceQuery->first();

        if ($instance) {
            if (!in_array($instance->status, ['paid', 'cleared'], true)) {
                DB::table('bill_instances')
                    ->where('id', $instance->id)
                    ->update([
                        'due_date' => $dueDate,
                        'expected_amount' => $amount,
                        'updated_at' => now(),
                    ]);
            }

            return;
        }

        $payload = [
            'user_id' => $userId,
            'bill_id' => $billId,
            'period_month' => $monthStart->toDateString(),
            'due_date' => $dueDate,
            'expected_amount' => $amount,
            'status' => 'expected',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload = HomeOpsV0::addHomeId($payload, 'bill_instances', $homeId);

        DB::table('bill_instances')->insert($payload);
    }

    private function dueDateForMonth(Carbon $monthStart, Carbon $monthEnd, int $dueDay): string
    {
        $day = min(max($dueDay, 1), (int) $monthEnd->format('j'));

        return $monthStart->copy()->day($day)->toDateString();
    }

    private function firstOrCreateCategory(int $userId, string $name, string $type): int
    {
        $existingId = DB::table('categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('categories')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'type' => $type,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function firstOrCreateVendor(int $userId, string $name, string $vendorType, ?int $categoryId = null): int
    {
        $existingId = DB::table('vendors')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('vendors')->insertGetId([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $name,
            'vendor_type' => $vendorType,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
