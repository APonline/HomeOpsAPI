<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsReceiptExtractor;
use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HomeOpsReceiptScanController extends Controller
{
    public function scan(Request $request, HomeOpsReceiptExtractor $extractor)
    {
        abort_unless($this->schemaReady(), 503, 'Run the latest HomeOps migrations to enable receipt scanning.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'receipt_file' => ['required', 'file', 'max:12288', 'mimetypes:image/jpeg,image/png,image/webp,image/gif'],
        ]);

        $file = $data['receipt_file'];
        $mimeType = (string) ($file->getMimeType() ?: 'image/jpeg');
        $extension = strtolower((string) ($file->guessExtension() ?: 'jpg'));
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'jpg';
        $hash = hash_file('sha256', $file->getRealPath());
        $disk = 'local';
        $scanUuid = (string) Str::uuid();
        $directory = sprintf('homeops/receipt-scans/%d/%s', $userId, $homeId ?: 'shared');
        $path = $file->storeAs($directory, $scanUuid.'.'.$extension, $disk);
        abort_if(!$path, 500, 'The receipt image could not be stored.');

        $scanId = DB::table('receipt_scans')->insertGetId([
            'user_id' => $userId,
            'home_id' => $homeId,
            'status' => 'processing',
            'storage_disk' => $disk,
            'file_path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName() ?: 'receipt.'.$extension, 255, ''),
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'file_hash' => $hash,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $result = $extractor->extract(Storage::disk($disk)->path($path), $mimeType);
            $extracted = $result['data'] ?? [];
            $duplicates = $this->duplicateCandidates($userId, $homeId, $hash, $extracted);

            DB::table('receipt_scans')->where('id', $scanId)->update([
                'status' => 'ready',
                'provider' => $result['provider'] ?? 'manual',
                'confidence' => $result['confidence'] ?? 0,
                'extracted_data' => json_encode($extracted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'raw_ocr_text' => $result['raw_text'] ?? null,
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'scan' => [
                    'id' => (int) $scanId,
                    'status' => 'ready',
                    'provider' => $result['provider'] ?? 'manual',
                    'confidence' => (float) ($result['confidence'] ?? 0),
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'expires_at' => now()->addDay()->toIso8601String(),
                ],
                'extracted' => $extracted,
                'duplicate_candidates' => $duplicates,
                'warnings' => $result['warnings'] ?? [],
                'automatic_extraction' => ($result['provider'] ?? 'manual') !== 'manual',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            DB::table('receipt_scans')->where('id', $scanId)->update([
                'status' => 'ready',
                'provider' => 'manual',
                'confidence' => 0,
                'error_message' => 'Automatic extraction failed. The uploaded image is still ready for manual review.',
                'extracted_data' => json_encode([], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            $this->recordSystemEvent($request, $userId, 'warning', 'receipt_scanner', 'extractor',
                'Automatic receipt extraction fell back to manual review.', [
                    'scan_id' => $scanId,
                    'home_id' => $homeId,
                    'exception' => class_basename($exception),
                ]);

            return response()->json([
                'ok' => true,
                'scan' => [
                    'id' => (int) $scanId,
                    'status' => 'ready',
                    'provider' => 'manual',
                    'confidence' => 0,
                    'file_name' => $file->getClientOriginalName(),
                ],
                'extracted' => [],
                'duplicate_candidates' => $this->duplicateCandidates($userId, $homeId, $hash, []),
                'warnings' => ['Automatic extraction failed. Enter or correct the visible values before saving.'],
                'automatic_extraction' => false,
            ]);
        }
    }

    public function commit(Request $request, int $scanId)
    {
        abort_unless($this->schemaReady(), 503, 'Run the latest HomeOps migrations to enable receipt scanning.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'],
            'vendor' => ['required', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'tip' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'receipt_number' => ['nullable', 'string', 'max:120'],
            'confirm_duplicate' => ['nullable', 'boolean'],
            'line_items' => ['nullable', 'array', 'max:80'],
            'line_items.*.description' => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.category_hint' => ['nullable', 'string', 'max:120'],
        ]);

        $scanQuery = DB::table('receipt_scans')->where('user_id', $userId)->where('id', $scanId);
        HomeOpsV0::unqualifiedHomeFilter($scanQuery, 'receipt_scans', $homeId);
        $scan = $scanQuery->first();
        abort_if(!$scan, 404, 'Receipt scan not found.');
        abort_if($scan->status === 'committed' || $scan->receipt_id, 422, 'This receipt scan has already been saved.');
        abort_if($scan->expires_at && Carbon::parse($scan->expires_at)->isPast(), 410, 'This receipt scan expired. Upload it again.');
        abort_unless(Storage::disk($scan->storage_disk ?: 'local')->exists($scan->file_path), 410, 'The temporary receipt image is no longer available.');

        $exactDuplicate = DB::table('receipts')->where('user_id', $userId)->where('file_hash', $scan->file_hash);
        HomeOpsV0::unqualifiedHomeFilter($exactDuplicate, 'receipts', $homeId);
        if ($exactDuplicate->exists() && empty($data['confirm_duplicate'])) {
            abort(422, 'This exact receipt image is already saved. Confirm the duplicate only when it is intentionally a separate record.');
        }

        [$roomId, $assetId] = $this->resolveOwnedContext($userId, $homeId, $data['room_id'] ?? null, $data['asset_id'] ?? null);
        $disk = $scan->storage_disk ?: 'local';
        $date = Carbon::parse($data['date'])->toDateString();
        $extension = pathinfo($scan->file_path, PATHINFO_EXTENSION) ?: 'jpg';
        $finalPath = sprintf(
            'homeops/receipts/%d/%s/%s/%s.%s',
            $userId,
            $homeId ?: 'shared',
            Carbon::parse($date)->format('Y/m'),
            (string) Str::uuid(),
            $extension,
        );

        abort_unless(Storage::disk($disk)->copy($scan->file_path, $finalPath), 500, 'The receipt image could not be finalized.');

        try {
            $result = DB::transaction(function () use ($data, $userId, $homeId, $roomId, $assetId, $scan, $scanId, $disk, $finalPath, $date) {
                $category = trim((string) ($data['category'] ?? '')) ?: 'Uncategorized Spending';
                $categoryId = $this->firstOrCreateCategory($userId, $category);
                $vendorId = $this->firstOrCreateVendor($userId, $data['vendor'], $categoryId);
                $notes = trim((string) ($data['notes'] ?? '')) ?: null;
                $extracted = json_decode((string) ($scan->extracted_data ?? '{}'), true) ?: [];
                if (!empty($data['receipt_number'])) {
                    $extracted['receipt_number'] = $data['receipt_number'];
                }

                $ledgerPayload = [
                    'user_id' => $userId,
                    'vendor_id' => $vendorId,
                    'category_id' => $categoryId,
                    'entry_type' => 'purchase',
                    'direction' => 'out',
                    'entry_date' => $date,
                    'title' => $data['vendor'],
                    'total_amount' => $data['total'],
                    'status' => 'paid',
                    'source' => 'receipt_upload',
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $ledgerPayload = HomeOpsV0::addHomeId($ledgerPayload, 'ledger_entries', $homeId);
                $ledgerPayload = HomeOpsV0::addRoomId($ledgerPayload, 'ledger_entries', $roomId);
                $ledgerPayload = HomeOpsV0::addAssetId($ledgerPayload, 'ledger_entries', $assetId);
                $ledgerId = DB::table('ledger_entries')->insertGetId($ledgerPayload);

                $receiptPayload = [
                    'user_id' => $userId,
                    'vendor_id' => $vendorId,
                    'ledger_entry_id' => $ledgerId,
                    'receipt_date' => $date,
                    'vendor_name_raw' => $data['vendor'],
                    'total_amount' => $data['total'],
                    'status' => 'approved',
                    'file_name' => $scan->file_name,
                    'notes' => $notes,
                    'subtotal_amount' => $data['subtotal'] ?? null,
                    'tax_amount' => $data['tax'] ?? null,
                    'tip_amount' => $data['tip'] ?? null,
                    'currency' => strtoupper($data['currency'] ?? 'CAD'),
                    'payment_method' => $data['payment_method'] ?? null,
                    'storage_disk' => $disk,
                    'file_path' => $finalPath,
                    'mime_type' => $scan->mime_type,
                    'file_size' => $scan->file_size,
                    'file_hash' => $scan->file_hash,
                    'capture_source' => 'scan',
                    'extraction_provider' => $scan->provider,
                    'extraction_confidence' => $scan->confidence,
                    'raw_ocr_text' => $scan->raw_ocr_text,
                    'extracted_data' => json_encode($extracted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $receiptPayload = HomeOpsV0::addHomeId($receiptPayload, 'receipts', $homeId);
                $receiptPayload = HomeOpsV0::addRoomId($receiptPayload, 'receipts', $roomId);
                $receiptPayload = HomeOpsV0::addAssetId($receiptPayload, 'receipts', $assetId);
                $receiptId = DB::table('receipts')->insertGetId($receiptPayload);

                foreach (array_values($data['line_items'] ?? []) as $index => $item) {
                    DB::table('receipt_items')->insert($this->receiptItemPayload($receiptId, $index, $item));
                }

                $this->linkLedgerToPeriods($userId, $homeId, $ledgerId, $date);

                DB::table('receipt_scans')->where('id', $scanId)->update([
                    'receipt_id' => $receiptId,
                    'status' => 'committed',
                    'committed_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['receipt_id' => (int) $receiptId, 'ledger_entry_id' => (int) $ledgerId];
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($finalPath);
            throw $exception;
        }

        Storage::disk($disk)->delete($scan->file_path);

        return response()->json([
            'ok' => true,
            'message' => 'Receipt scanned, verified, and logged as a transaction.',
            ...$result,
        ], 201);
    }

    public function cancel(Request $request, int $scanId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('receipt_scans')->where('user_id', $userId)->where('id', $scanId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'receipt_scans', $homeId);
        $scan = $query->first();
        abort_if(!$scan, 404, 'Receipt scan not found.');
        abort_if($scan->status === 'committed', 422, 'A saved receipt cannot be cancelled from the scan queue.');

        Storage::disk($scan->storage_disk ?: 'local')->delete($scan->file_path);
        DB::table('receipt_scans')->where('id', $scanId)->delete();

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, int $receiptId)
    {
        abort_unless(Schema::hasTable('receipts') && Schema::hasColumn('receipts', 'file_path'), 404, 'Receipt image not found.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('receipts')->where('user_id', $userId)->where('id', $receiptId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'receipts', $homeId);
        $receipt = $query->first();
        abort_if(!$receipt || empty($receipt->file_path), 404, 'Receipt image not found.');

        $disk = $receipt->storage_disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($receipt->file_path), 404, 'The stored receipt image is missing.');

        return Storage::disk($disk)->response(
            $receipt->file_path,
            $receipt->file_name ?: basename($receipt->file_path),
            [
                'Content-Type' => $receipt->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($receipt->file_name ?: 'receipt').'"',
            ],
        );
    }

    private function receiptItemPayload(int $receiptId, int $index, array $item): array
    {
        $payload = [
            'receipt_id' => $receiptId,
            'quantity' => $item['quantity'] ?? null,
            'unit_price' => $item['unit_price'] ?? null,
            'line_total' => $item['line_total'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $categoryHint = !empty($item['category_hint']) ? (string) $item['category_hint'] : null;
        $description = (string) ($item['description'] ?? '');

        if (Schema::hasColumn('receipt_items', 'line_order')) $payload['line_order'] = $index + 1;
        if (Schema::hasColumn('receipt_items', 'item_name')) $payload['item_name'] = $description;
        if (Schema::hasColumn('receipt_items', 'notes')) $payload['notes'] = $categoryHint ? 'Category: '.$categoryHint : null;

        // Legacy columns remain dual-written so installs that received the original receipt
        // migration cannot reject new rows because description was created NOT NULL.
        if (Schema::hasColumn('receipt_items', 'line_number')) $payload['line_number'] = $index + 1;
        if (Schema::hasColumn('receipt_items', 'description')) $payload['description'] = $description;
        if (Schema::hasColumn('receipt_items', 'category_hint')) $payload['category_hint'] = $categoryHint;

        return $payload;
    }

    private function recordSystemEvent(Request $request, ?int $userId, string $severity, string $category, string $source, string $message, array $context = []): void
    {
        if (!Schema::hasTable('homeops_system_events')) return;

        try {
            DB::table('homeops_system_events')->insert([
                'request_id' => $request->attributes->get('homeops_request_id'),
                'user_id' => $userId,
                'severity' => $severity,
                'category' => $category,
                'source' => $source,
                'message' => Str::limit($message, 1000, ''),
                'context' => empty($context) ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $loggingFailure) {
            report($loggingFailure);
        }
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('receipt_scans')
            && Schema::hasTable('receipt_items')
            && Schema::hasTable('receipts')
            && Schema::hasColumn('receipts', 'file_path')
            && Schema::hasColumn('receipts', 'extracted_data');
    }

    private function duplicateCandidates(int $userId, ?int $homeId, string $hash, array $extracted): array
    {
        $query = DB::table('receipts')
            ->leftJoin('vendors', 'vendors.id', '=', 'receipts.vendor_id')
            ->where('receipts.user_id', $userId)
            ->where(function ($candidate) use ($hash, $extracted) {
                $candidate->where('receipts.file_hash', $hash);

                if (!empty($extracted['receipt_date']) && isset($extracted['total']) && is_numeric($extracted['total'])) {
                    $candidate->orWhere(function ($near) use ($extracted) {
                        $near->whereDate('receipts.receipt_date', $extracted['receipt_date'])
                            ->whereBetween('receipts.total_amount', [
                                max(0, (float) $extracted['total'] - 0.01),
                                (float) $extracted['total'] + 0.01,
                            ]);
                    });
                }
            });
        HomeOpsV0::homeFilter($query, 'receipts', $homeId);

        return $query->orderByDesc('receipts.id')->limit(5)->get([
            'receipts.id', 'receipts.receipt_date', 'receipts.total_amount', 'receipts.file_hash',
            'vendors.name as vendor_name', 'receipts.vendor_name_raw',
        ])->map(fn ($row) => [
            'id' => (int) $row->id,
            'vendor' => $row->vendor_name ?: $row->vendor_name_raw,
            'date' => $row->receipt_date,
            'total' => (float) $row->total_amount,
            'match_type' => $row->file_hash === $hash ? 'exact_file' : 'same_date_total',
        ])->values()->all();
    }

    private function resolveOwnedContext(int $userId, ?int $homeId, mixed $roomId, mixed $assetId): array
    {
        $resolvedRoomId = null;
        $resolvedAssetId = null;

        if ($roomId && Schema::hasTable('rooms')) {
            $room = DB::table('rooms')->where('user_id', $userId)->where('id', (int) $roomId);
            if ($homeId) $room->where('home_id', $homeId);
            abort_unless($room->exists(), 422, 'The selected room does not belong to this property.');
            $resolvedRoomId = (int) $roomId;
        }

        if ($assetId && Schema::hasTable('home_assets')) {
            $asset = DB::table('home_assets')->where('user_id', $userId)->where('id', (int) $assetId);
            if ($homeId) $asset->where('home_id', $homeId);
            $ownedAsset = $asset->first();
            abort_unless($ownedAsset, 422, 'The selected asset does not belong to this property.');
            $resolvedAssetId = (int) $assetId;
            if (!$resolvedRoomId && !empty($ownedAsset->room_id)) $resolvedRoomId = (int) $ownedAsset->room_id;
        }

        return [$resolvedRoomId, $resolvedAssetId];
    }

    private function firstOrCreateCategory(int $userId, string $name): int
    {
        $id = DB::table('categories')->where('user_id', $userId)->where('name', $name)->value('id');
        return $id ? (int) $id : (int) DB::table('categories')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'type' => 'spending',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function firstOrCreateVendor(int $userId, string $name, int $categoryId): int
    {
        $cleanName = trim($name);
        $id = DB::table('vendors')->where('user_id', $userId)->whereRaw('LOWER(name) = ?', [mb_strtolower($cleanName)])->value('id');
        return $id ? (int) $id : (int) DB::table('vendors')->insertGetId([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $cleanName,
            'vendor_type' => 'store',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkLedgerToPeriods(int $userId, ?int $homeId, int $ledgerId, string $date): void
    {
        if (!Schema::hasTable('spending_periods') || !Schema::hasTable('period_ledger_entries')) return;

        $periods = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
        HomeOpsV0::unqualifiedHomeFilter($periods, 'spending_periods', $homeId);

        foreach ($periods->pluck('id') as $periodId) {
            DB::table('period_ledger_entries')->insertOrIgnore([
                'spending_period_id' => $periodId,
                'ledger_entry_id' => $ledgerId,
                'link_type' => 'auto_date_match',
                'created_at' => now(),
            ]);
        }
    }
}
