<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\HomeOpsAdminAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HomeOpsAdminController extends Controller
{
    public function overview(Request $request)
    {
        $this->requireAdminSchema();
        $days = $this->rangeDays($request);
        $since = now()->subDays($days - 1)->startOfDay();
        $dayKeys = collect(range($days - 1, 0))->map(fn ($offset) => now()->subDays($offset)->format('Y-m-d'));

        $requestRows = DB::table('homeops_request_logs')
            ->where('occurred_at', '>=', $since)
            ->get(['occurred_at', 'response_status', 'duration_ms', 'user_id', 'category']);

        $scanRows = Schema::hasTable('receipt_scans')
            ? DB::table('receipt_scans')->where('created_at', '>=', $since)->get(['created_at', 'status', 'provider', 'confidence', 'error_message'])
            : collect();

        $signupRows = DB::table('users')->where('created_at', '>=', $since)->pluck('created_at');
        $receiptRows = Schema::hasTable('receipts')
            ? DB::table('receipts')->where('created_at', '>=', $since)->pluck('created_at')
            : collect();

        $requestByDay = $this->dailySeries($dayKeys, $requestRows, 'occurred_at');
        $errorsByDay = $this->dailySeries($dayKeys, $requestRows->filter(fn ($row) => (int) $row->response_status >= 400), 'occurred_at');
        $signupsByDay = $this->dailySeries($dayKeys, $signupRows);
        $receiptsByDay = $this->dailySeries($dayKeys, $receiptRows);
        $scansByDay = $this->dailySeries($dayKeys, $scanRows, 'created_at');

        $durations = $requestRows->pluck('duration_ms')->map(fn ($value) => (int) $value)->sort()->values();
        $successfulScans = $scanRows->filter(fn ($row) => in_array($row->status, ['ready', 'committed'], true) && empty($row->error_message))->count();
        $automaticScans = $scanRows->filter(fn ($row) => !empty($row->provider) && $row->provider !== 'manual')->count();
        $failedScans = $scanRows->filter(fn ($row) => !empty($row->error_message))->count();
        $requestCount = $requestRows->count();
        $errorCount = $requestRows->filter(fn ($row) => (int) $row->response_status >= 400)->count();

        $active24h = (int) DB::table('homeops_request_logs')
            ->whereNotNull('user_id')
            ->where('occurred_at', '>=', now()->subDay())
            ->distinct()
            ->count('user_id');

        $active30d = (int) DB::table('homeops_request_logs')
            ->whereNotNull('user_id')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->distinct()
            ->count('user_id');

        $openCases = Schema::hasTable('homeops_support_cases')
            ? (int) DB::table('homeops_support_cases')->whereNotIn('status', ['resolved', 'closed'])->count()
            : 0;

        $metrics = [
            'customers_total' => (int) DB::table('users')->count(),
            'customers_active' => Schema::hasColumn('users', 'account_status') ? (int) DB::table('users')->where('account_status', 'active')->count() : (int) DB::table('users')->count(),
            'active_24h' => $active24h,
            'active_30d' => $active30d,
            'properties_total' => $this->tableCount('homes'),
            'receipts_total' => $this->tableCount('receipts'),
            'receipts_today' => Schema::hasTable('receipts') ? (int) DB::table('receipts')->where('created_at', '>=', now()->startOfDay())->count() : 0,
            'scan_success_rate' => $scanRows->count() ? round(($successfulScans / $scanRows->count()) * 100, 1) : null,
            'scan_automation_rate' => $scanRows->count() ? round(($automaticScans / $scanRows->count()) * 100, 1) : null,
            'scan_failures' => $failedScans,
            'requests' => $requestCount,
            'marketing_views' => (int) $requestRows->filter(fn ($row) => ($row->category ?? null) === 'public')->count(),
            'error_rate' => $requestCount ? round(($errorCount / $requestCount) * 100, 2) : 0,
            'p95_ms' => $this->percentile($durations, 0.95),
            'support_open' => $openCases,
            'support_urgent' => Schema::hasTable('homeops_support_cases') ? (int) DB::table('homeops_support_cases')->whereNotIn('status', ['resolved', 'closed'])->where('priority', 'urgent')->count() : 0,
            'failed_jobs' => $this->tableCount('failed_jobs'),
            'receipt_storage_bytes' => Schema::hasTable('receipts') && Schema::hasColumn('receipts', 'file_size') ? (int) DB::table('receipts')->sum('file_size') : 0,
        ];

        $endpointStats = DB::table('homeops_request_logs')
            ->where('occurred_at', '>=', $since)
            ->select('route', 'method', DB::raw('COUNT(*) as requests'), DB::raw('AVG(duration_ms) as avg_ms'), DB::raw('SUM(CASE WHEN response_status >= 400 THEN 1 ELSE 0 END) as errors'))
            ->groupBy('route', 'method')
            ->orderByDesc('requests')
            ->limit(12)
            ->get();

        $recentErrors = DB::table('homeops_request_logs as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.response_status', '>=', 400)
            ->orderByDesc('r.occurred_at')
            ->limit(12)
            ->get(['r.request_id', 'r.user_id', 'u.name as user_name', 'u.email as user_email', 'r.category', 'r.method', 'r.route', 'r.response_status', 'r.duration_ms', 'r.error_message', 'r.occurred_at']);

        $recentSupport = Schema::hasTable('homeops_support_cases')
            ? DB::table('homeops_support_cases as c')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->whereNotIn('c.status', ['closed'])
                ->orderByRaw("CASE c.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
                ->orderByDesc('c.updated_at')
                ->limit(10)
                ->get(['c.id', 'c.subject', 'c.status', 'c.priority', 'c.channel', 'c.updated_at', 'u.id as user_id', 'u.name as user_name', 'u.email as user_email'])
            : collect();

        return response()->json([
            'range_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'series' => [
                'requests' => $requestByDay,
                'errors' => $errorsByDay,
                'signups' => $signupsByDay,
                'receipts' => $receiptsByDay,
                'scans' => $scansByDay,
            ],
            'endpoint_stats' => $endpointStats,
            'recent_errors' => $recentErrors,
            'recent_support' => $recentSupport,
            'record_inventory' => $this->recordInventory(),
            'health' => [
                'app_env' => app()->environment(),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'database' => DB::connection()->getDriverName(),
                'queue' => config('queue.default'),
                'filesystem' => config('filesystems.default'),
                'latest_request_at' => DB::table('homeops_request_logs')->max('occurred_at'),
            ],
        ]);
    }

    public function customers(Request $request)
    {
        $this->requireAdminSchema();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'max:30'],
            'plan' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = DB::table('users as u')
            ->select([
                'u.id', 'u.name', 'u.email', 'u.created_at', 'u.updated_at',
                'u.is_admin', 'u.account_status', 'u.plan_key', 'u.last_seen_at', 'u.suspended_at',
            ])
            ->selectSub(fn ($q) => $q->from('homes')->whereColumn('homes.user_id', 'u.id')->selectRaw('COUNT(*)'), 'home_count')
            ->selectSub(fn ($q) => $q->from('receipts')->whereColumn('receipts.user_id', 'u.id')->selectRaw('COUNT(*)'), 'receipt_count')
            ->selectSub(fn ($q) => $q->from('homeops_request_logs')->whereColumn('homeops_request_logs.user_id', 'u.id')->where('occurred_at', '>=', now()->subDays(30))->selectRaw('COUNT(*)'), 'request_count_30d')
            ->selectSub(fn ($q) => $q->from('homeops_support_cases')->whereColumn('homeops_support_cases.user_id', 'u.id')->whereNotIn('status', ['resolved', 'closed'])->selectRaw('COUNT(*)'), 'open_case_count');

        if (!empty($data['q'])) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['q'])).'%';
            $query->where(function ($where) use ($needle) {
                $where->where('u.name', 'like', $needle)
                    ->orWhere('u.email', 'like', $needle)
                    ->orWhere('u.id', trim($needle, '%'));
            });
        }

        if (!empty($data['status'])) {
            $query->where('u.account_status', $data['status']);
        }
        if (!empty($data['plan'])) {
            $query->where('u.plan_key', $data['plan']);
        }

        $perPage = (int) ($data['per_page'] ?? 40);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->count('u.id');
        $rows = $query->orderByDesc(DB::raw('COALESCE(u.last_seen_at, u.created_at)'))
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'customers' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function customer(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        $user = User::find($userId);
        abort_if(!$user, 404, 'Customer not found.');

        $homes = Schema::hasTable('homes') ? DB::table('homes')->where('user_id', $userId)->orderByDesc('is_primary')->orderBy('id')->get() : collect();
        $notes = DB::table('homeops_customer_notes as n')
            ->leftJoin('users as a', 'a.id', '=', 'n.admin_user_id')
            ->where('n.user_id', $userId)
            ->orderByDesc('n.pinned')
            ->orderByDesc('n.created_at')
            ->limit(100)
            ->get(['n.*', 'a.name as admin_name']);

        $cases = DB::table('homeops_support_cases as c')
            ->leftJoin('users as a', 'a.id', '=', 'c.assigned_admin_user_id')
            ->where('c.user_id', $userId)
            ->orderByDesc('c.updated_at')
            ->get(['c.*', 'a.name as assigned_admin_name']);

        $tokens = Schema::hasTable('homeops_api_tokens')
            ? DB::table('homeops_api_tokens')->where('user_id', $userId)->orderByDesc('created_at')->limit(30)->get(['id', 'name', 'ip_address', 'user_agent', 'last_used_at', 'expires_at', 'revoked_at', 'created_at'])
            : collect();

        $featureOverrides = DB::table('homeops_feature_flag_overrides as o')
            ->join('homeops_feature_flags as f', 'f.id', '=', 'o.feature_flag_id')
            ->where('o.user_id', $userId)
            ->orderBy('f.name')
            ->get(['o.id', 'o.enabled', 'o.reason', 'o.updated_at', 'f.id as feature_flag_id', 'f.key', 'f.name']);

        $dataRequests = DB::table('homeops_data_requests')->where('user_id', $userId)->orderByDesc('requested_at')->get();

        return response()->json([
            'customer' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => HomeOpsAdminAccess::isAdmin($user),
                'account_status' => $user->account_status ?? 'active',
                'plan_key' => $user->plan_key ?? 'core',
                'last_seen_at' => $user->last_seen_at,
                'suspended_at' => $user->suspended_at,
                'suspension_reason' => $user->suspension_reason,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'homes' => $homes,
            'record_counts' => $this->recordCountsForUser($userId),
            'recent_receipts' => $this->recentReceiptsForUser($userId),
            'recent_scans' => $this->recentScansForUser($userId),
            'recent_requests' => DB::table('homeops_request_logs')->where('user_id', $userId)->orderByDesc('occurred_at')->limit(50)->get(),
            'recent_audit' => DB::table('homeops_audit_logs')->where('target_user_id', $userId)->orderByDesc('occurred_at')->limit(50)->get(),
            'support_cases' => $cases,
            'notes' => $notes,
            'sessions' => $tokens,
            'feature_overrides' => $featureOverrides,
            'data_requests' => $dataRequests,
        ]);
    }

    public function customerTimeline(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404, 'Customer not found.');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'source' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:500'],
        ]);
        $limit = (int) ($data['limit'] ?? 160);
        $events = collect();

        if (empty($data['source']) || $data['source'] === 'request') {
            DB::table('homeops_request_logs')->where('user_id', $userId)->orderByDesc('occurred_at')->limit($limit)->get()->each(function ($row) use ($events) {
                $events->push([
                    'source' => 'request', 'id' => $row->id, 'request_id' => $row->request_id,
                    'type' => $row->category ?: 'request', 'title' => $row->action,
                    'detail' => $row->error_message ?: $row->route, 'status' => $row->response_status,
                    'timestamp' => $row->occurred_at, 'metadata' => ['duration_ms' => $row->duration_ms, 'method' => $row->method],
                ]);
            });
        }

        if (empty($data['source']) || $data['source'] === 'audit') {
            DB::table('homeops_audit_logs')->where('target_user_id', $userId)->orderByDesc('occurred_at')->limit($limit)->get()->each(function ($row) use ($events) {
                $events->push([
                    'source' => 'audit', 'id' => $row->id, 'request_id' => $row->request_id,
                    'type' => $row->event_type, 'title' => $row->summary,
                    'detail' => trim(($row->entity_type ?: '').' '.($row->entity_id ?: '')), 'status' => null,
                    'timestamp' => $row->occurred_at, 'metadata' => ['action' => $row->action, 'entity_type' => $row->entity_type, 'entity_id' => $row->entity_id],
                ]);
            });
        }

        if (empty($data['source']) || $data['source'] === 'support') {
            DB::table('homeops_support_messages as m')
                ->join('homeops_support_cases as c', 'c.id', '=', 'm.support_case_id')
                ->where('c.user_id', $userId)
                ->orderByDesc('m.happened_at')->limit($limit)->get(['m.*', 'c.subject'])
                ->each(function ($row) use ($events) {
                    $events->push([
                        'source' => 'support', 'id' => $row->id, 'request_id' => null,
                        'type' => $row->direction, 'title' => $row->subject,
                        'detail' => $row->body, 'status' => null, 'timestamp' => $row->happened_at,
                        'metadata' => ['channel' => $row->channel, 'author_type' => $row->author_type, 'case_id' => $row->support_case_id],
                    ]);
                });
        }

        if ((empty($data['source']) || $data['source'] === 'receipt') && Schema::hasTable('receipts')) {
            DB::table('receipts')->where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get(['id', 'vendor_name_raw', 'receipt_date', 'total_amount', 'status', 'capture_source', 'extraction_provider', 'extraction_confidence', 'created_at'])
                ->each(function ($row) use ($events) {
                    $events->push([
                        'source' => 'receipt', 'id' => $row->id, 'request_id' => null,
                        'type' => 'receipt.saved', 'title' => 'Receipt: '.$row->vendor_name_raw,
                        'detail' => sprintf('%s · %s · %s', $row->receipt_date, $row->total_amount, $row->status),
                        'status' => null, 'timestamp' => $row->created_at,
                        'metadata' => ['capture_source' => $row->capture_source, 'provider' => $row->extraction_provider, 'confidence' => $row->extraction_confidence],
                    ]);
                });
        }

        if (!empty($data['q'])) {
            $needle = mb_strtolower(trim($data['q']));
            $events = $events->filter(function ($event) use ($needle) {
                $haystack = mb_strtolower(implode(' ', [(string) ($event['title'] ?? ''), (string) ($event['detail'] ?? ''), (string) ($event['request_id'] ?? ''), (string) ($event['type'] ?? '')]));
                return str_contains($haystack, $needle);
            });
        }

        return response()->json([
            'events' => $events->sortByDesc(fn ($event) => $event['timestamp'])->take($limit)->values(),
        ]);
    }

    public function updateCustomer(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        $admin = $request->user();
        $user = User::find($userId);
        abort_if(!$user, 404, 'Customer not found.');

        $data = $request->validate([
            'account_status' => ['nullable', Rule::in(['active', 'suspended'])],
            'plan_key' => ['nullable', Rule::in(['core', 'command', 'household', 'internal', 'legacy'])],
            'suspension_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if (($data['account_status'] ?? null) === 'suspended' && (int) $admin->id === $userId) {
            abort(422, 'You cannot suspend your own administrator account.');
        }

        $before = $user->only(['account_status', 'plan_key', 'suspended_at', 'suspension_reason']);
        $updates = [];

        if (array_key_exists('plan_key', $data)) {
            $updates['plan_key'] = $data['plan_key'];
        }
        if (array_key_exists('account_status', $data)) {
            $updates['account_status'] = $data['account_status'];
            $updates['suspended_at'] = $data['account_status'] === 'suspended' ? now() : null;
            $updates['suspension_reason'] = $data['account_status'] === 'suspended' ? trim((string) ($data['suspension_reason'] ?? '')) ?: null : null;
        }
        $updates['updated_at'] = now();

        DB::table('users')->where('id', $userId)->update($updates);
        if (($updates['account_status'] ?? null) === 'suspended') {
            $this->revokeUserTokens($userId);
        }

        $fresh = User::find($userId);
        $this->writeAdminAudit($request, $userId, 'customer.account_updated', 'users', (string) $userId, 'update', 'Customer account settings updated.', $before, $fresh->only(['account_status', 'plan_key', 'suspended_at', 'suspension_reason']));

        return response()->json(['ok' => true, 'customer' => $this->customerSummary($fresh)]);
    }

    public function revokeSessions(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404, 'Customer not found.');
        $count = $this->revokeUserTokens($userId);
        $this->writeAdminAudit($request, $userId, 'customer.sessions_revoked', 'users', (string) $userId, 'revoke', "Revoked {$count} active customer session(s).", null, ['revoked_sessions' => $count]);

        return response()->json(['ok' => true, 'revoked_sessions' => $count]);
    }

    public function addNote(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404, 'Customer not found.');
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'note_type' => ['nullable', Rule::in(['general', 'billing', 'technical', 'security', 'retention', 'legal'])],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $id = DB::table('homeops_customer_notes')->insertGetId([
            'user_id' => $userId,
            'admin_user_id' => $request->user()->id,
            'note_type' => $data['note_type'] ?? 'general',
            'body' => trim($data['body']),
            'pinned' => !empty($data['pinned']) ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->writeAdminAudit($request, $userId, 'support.note_added', 'customer_note', (string) $id, 'create', 'Internal support note added.', null, ['note_type' => $data['note_type'] ?? 'general', 'pinned' => !empty($data['pinned'])]);

        return response()->json(['ok' => true, 'note_id' => (int) $id], 201);
    }

    public function supportCases(Request $request)
    {
        $this->requireAdminSchema();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'max:30'],
            'priority' => ['nullable', 'string', 'max:20'],
            'user_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:200'],
        ]);

        $query = DB::table('homeops_support_cases as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'c.assigned_admin_user_id')
            ->select(['c.*', 'u.name as user_name', 'u.email as user_email', 'a.name as assigned_admin_name']);

        if (!empty($data['q'])) {
            $needle = '%'.trim($data['q']).'%';
            $query->where(fn ($where) => $where->where('c.subject', 'like', $needle)->orWhere('c.summary', 'like', $needle)->orWhere('c.external_reference', 'like', $needle)->orWhere('u.email', 'like', $needle)->orWhere('u.name', 'like', $needle));
        }
        if (!empty($data['status'])) $query->where('c.status', $data['status']);
        if (!empty($data['priority'])) $query->where('c.priority', $data['priority']);
        if (!empty($data['user_id'])) $query->where('c.user_id', $data['user_id']);

        return response()->json(['cases' => $query->orderByDesc('c.updated_at')->limit((int) ($data['limit'] ?? 100))->get()]);
    }

    public function createCase(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404, 'Customer not found.');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:220'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'channel' => ['nullable', Rule::in(['internal', 'email', 'phone', 'chat', 'social', 'app'])],
            'external_reference' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:20000'],
            'message_direction' => ['nullable', Rule::in(['inbound', 'outbound', 'internal'])],
        ]);

        $now = now();
        $caseId = DB::table('homeops_support_cases')->insertGetId([
            'user_id' => $userId,
            'assigned_admin_user_id' => $request->user()->id,
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
            'channel' => $data['channel'] ?? 'internal',
            'subject' => trim($data['subject']),
            'summary' => trim((string) ($data['summary'] ?? '')) ?: null,
            'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null,
            'opened_at' => $now,
            'last_customer_contact_at' => ($data['message_direction'] ?? null) === 'inbound' ? $now : null,
            'last_admin_contact_at' => in_array(($data['message_direction'] ?? null), ['outbound', 'internal'], true) ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!empty($data['message'])) {
            $this->insertSupportMessage($request, $caseId, $data['message'], $data['message_direction'] ?? 'internal', $data['channel'] ?? 'internal', null);
        }

        $this->writeAdminAudit($request, $userId, 'support.case_created', 'support_case', (string) $caseId, 'create', 'Support case created: '.trim($data['subject']), null, ['priority' => $data['priority'] ?? 'normal', 'channel' => $data['channel'] ?? 'internal']);

        return response()->json(['ok' => true, 'case_id' => (int) $caseId], 201);
    }

    public function supportCase(Request $request, int $caseId)
    {
        $this->requireAdminSchema();
        $case = DB::table('homeops_support_cases as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('c.id', $caseId)->first(['c.*', 'u.name as user_name', 'u.email as user_email']);
        abort_if(!$case, 404, 'Support case not found.');
        $messages = DB::table('homeops_support_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.author_user_id')->where('m.support_case_id', $caseId)->orderBy('m.happened_at')->get(['m.*', 'u.name as author_name']);

        return response()->json(['case' => $case, 'messages' => $messages]);
    }

    public function updateCase(Request $request, int $caseId)
    {
        $this->requireAdminSchema();
        $case = DB::table('homeops_support_cases')->where('id', $caseId)->first();
        abort_if(!$case, 404, 'Support case not found.');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_admin_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'external_reference' => ['nullable', 'string', 'max:160'],
        ]);

        $updates = array_filter($data, fn ($value) => $value !== null);
        if (($data['status'] ?? null) === 'resolved' || ($data['status'] ?? null) === 'closed') {
            $updates['resolved_at'] = now();
        } elseif (array_key_exists('status', $data)) {
            $updates['resolved_at'] = null;
        }
        $updates['updated_at'] = now();
        DB::table('homeops_support_cases')->where('id', $caseId)->update($updates);
        $this->writeAdminAudit($request, (int) $case->user_id, 'support.case_updated', 'support_case', (string) $caseId, 'update', 'Support case updated.', (array) $case, $updates);

        return response()->json(['ok' => true]);
    }

    public function addCaseMessage(Request $request, int $caseId)
    {
        $this->requireAdminSchema();
        $case = DB::table('homeops_support_cases')->where('id', $caseId)->first();
        abort_if(!$case, 404, 'Support case not found.');
        $data = $request->validate([
            'body' => ['required', 'string', 'max:30000'],
            'direction' => ['required', Rule::in(['inbound', 'outbound', 'internal'])],
            'channel' => ['nullable', Rule::in(['internal', 'email', 'phone', 'chat', 'social', 'app'])],
            'external_message_id' => ['nullable', 'string', 'max:180'],
        ]);

        $messageId = $this->insertSupportMessage($request, $caseId, $data['body'], $data['direction'], $data['channel'] ?? $case->channel, $data['external_message_id'] ?? null);
        $this->writeAdminAudit($request, (int) $case->user_id, 'support.message_logged', 'support_message', (string) $messageId, 'create', ucfirst($data['direction']).' support message logged.', null, ['case_id' => $caseId, 'channel' => $data['channel'] ?? $case->channel]);

        return response()->json(['ok' => true, 'message_id' => (int) $messageId], 201);
    }

    public function logs(Request $request)
    {
        $this->requireAdminSchema();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'integer'],
            'method' => ['nullable', 'string', 'max:12'],
            'category' => ['nullable', 'string', 'max:80'],
            'errors_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:200'],
        ]);

        $query = DB::table('homeops_request_logs as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->select(['r.*', 'u.name as user_name', 'u.email as user_email']);
        if (!empty($data['q'])) {
            $needle = '%'.trim($data['q']).'%';
            $query->where(fn ($where) => $where->where('r.request_id', 'like', $needle)->orWhere('r.route', 'like', $needle)->orWhere('r.action', 'like', $needle)->orWhere('r.error_message', 'like', $needle)->orWhere('u.email', 'like', $needle)->orWhere('u.name', 'like', $needle));
        }
        if (!empty($data['user_id'])) $query->where('r.user_id', $data['user_id']);
        if (!empty($data['status'])) $query->where('r.response_status', $data['status']);
        if (!empty($data['method'])) $query->where('r.method', strtoupper($data['method']));
        if (!empty($data['category'])) $query->where('r.category', $data['category']);
        if (!empty($data['errors_only'])) $query->where('r.response_status', '>=', 400);

        return response()->json($this->paginateQuery($query, $data, 'r.occurred_at', 'logs'));
    }

    public function auditLogs(Request $request)
    {
        $this->requireAdminSchema();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'user_id' => ['nullable', 'integer'],
            'actor_type' => ['nullable', Rule::in(['user', 'admin', 'system'])],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:200'],
        ]);

        $query = DB::table('homeops_audit_logs as a')
            ->leftJoin('users as actor', 'actor.id', '=', 'a.actor_user_id')
            ->leftJoin('users as target', 'target.id', '=', 'a.target_user_id')
            ->select(['a.*', 'actor.name as actor_name', 'actor.email as actor_email', 'target.name as target_name', 'target.email as target_email']);

        if (!empty($data['q'])) {
            $needle = '%'.trim($data['q']).'%';
            $query->where(fn ($where) => $where->where('a.request_id', 'like', $needle)->orWhere('a.summary', 'like', $needle)->orWhere('a.entity_id', 'like', $needle)->orWhere('target.email', 'like', $needle));
        }
        if (!empty($data['user_id'])) $query->where('a.target_user_id', $data['user_id']);
        if (!empty($data['actor_type'])) $query->where('a.actor_type', $data['actor_type']);
        if (!empty($data['entity_type'])) $query->where('a.entity_type', $data['entity_type']);

        return response()->json($this->paginateQuery($query, $data, 'a.occurred_at', 'audit'));
    }

    public function featureFlags()
    {
        $this->requireAdminSchema();
        $flags = DB::table('homeops_feature_flags as f')
            ->leftJoin('homeops_feature_flag_overrides as o', 'o.feature_flag_id', '=', 'f.id')
            ->groupBy('f.id', 'f.key', 'f.name', 'f.description', 'f.enabled', 'f.rollout_percentage', 'f.config', 'f.updated_by', 'f.created_at', 'f.updated_at')
            ->orderBy('f.name')
            ->get(['f.*', DB::raw('COUNT(o.id) as override_count')]);
        return response()->json(['flags' => $flags]);
    }

    public function updateFeatureFlag(Request $request, int $flagId)
    {
        $this->requireAdminSchema();
        $flag = DB::table('homeops_feature_flags')->where('id', $flagId)->first();
        abort_if(!$flag, 404, 'Feature flag not found.');
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'rollout_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:4000'],
            'config' => ['nullable', 'array'],
        ]);
        $updates = [];
        foreach (['enabled', 'rollout_percentage', 'description'] as $key) if (array_key_exists($key, $data)) $updates[$key] = $data[$key];
        if (array_key_exists('config', $data)) $updates['config'] = json_encode($data['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $updates['updated_by'] = $request->user()->id;
        $updates['updated_at'] = now();
        DB::table('homeops_feature_flags')->where('id', $flagId)->update($updates);
        $this->writeAdminAudit($request, null, 'feature_flag.updated', 'feature_flag', (string) $flagId, 'update', 'Feature flag updated: '.$flag->key, (array) $flag, $updates);
        return response()->json(['ok' => true]);
    }

    public function setFeatureOverride(Request $request, int $flagId, int $userId)
    {
        $this->requireAdminSchema();
        abort_unless(DB::table('homeops_feature_flags')->where('id', $flagId)->exists(), 404, 'Feature flag not found.');
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404, 'Customer not found.');
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        DB::table('homeops_feature_flag_overrides')->updateOrInsert(
            ['feature_flag_id' => $flagId, 'user_id' => $userId],
            ['enabled' => $data['enabled'] ? 1 : 0, 'reason' => $data['reason'] ?? null, 'admin_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()],
        );
        $this->writeAdminAudit($request, $userId, 'feature_flag.override_set', 'feature_flag', (string) $flagId, 'update', 'Customer feature override updated.', null, ['enabled' => $data['enabled'], 'reason' => $data['reason'] ?? null]);
        return response()->json(['ok' => true]);
    }

    public function deleteFeatureOverride(Request $request, int $flagId, int $userId)
    {
        $this->requireAdminSchema();
        DB::table('homeops_feature_flag_overrides')->where('feature_flag_id', $flagId)->where('user_id', $userId)->delete();
        $this->writeAdminAudit($request, $userId, 'feature_flag.override_removed', 'feature_flag', (string) $flagId, 'delete', 'Customer feature override removed.', null, null);
        return response()->json(['ok' => true]);
    }

    public function cmsEntries(Request $request)
    {
        $this->requireAdminSchema();
        $area = trim((string) $request->query('area', ''));
        $query = DB::table('homeops_cms_entries')->orderBy('area')->orderBy('label');
        if ($area !== '') $query->where('area', $area);
        return response()->json(['entries' => $query->get()]);
    }

    public function updateCmsEntry(Request $request, int $entryId)
    {
        $this->requireAdminSchema();
        $entry = DB::table('homeops_cms_entries')->where('id', $entryId)->first();
        abort_if(!$entry, 404, 'CMS entry not found.');
        $data = $request->validate([
            'value' => ['required', 'array'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
        $updates = [
            'value_json' => json_encode($data['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => $data['status'],
            'published_at' => $data['status'] === 'published' ? now() : null,
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ];
        DB::table('homeops_cms_entries')->where('id', $entryId)->update($updates);
        $this->writeAdminAudit($request, null, 'cms.entry_updated', 'cms_entry', (string) $entryId, 'update', 'CMS entry updated: '.$entry->key, (array) $entry, $updates);
        return response()->json(['ok' => true]);
    }

    public function systemEvents(Request $request)
    {
        $this->requireAdminSchema();
        $data = $request->validate([
            'severity' => ['nullable', Rule::in(['debug', 'info', 'warning', 'error', 'critical'])],
            'category' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:300'],
        ]);
        $query = DB::table('homeops_system_events')->orderByDesc('occurred_at');
        if (!empty($data['severity'])) $query->where('severity', $data['severity']);
        if (!empty($data['category'])) $query->where('category', $data['category']);
        return response()->json(['events' => $query->limit((int) ($data['limit'] ?? 120))->get()]);
    }

    public function createDataRequest(Request $request, int $userId)
    {
        $this->requireAdminSchema();
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404, 'Customer not found.');
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['export', 'delete', 'correct', 'access'])],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $id = DB::table('homeops_data_requests')->insertGetId([
            'user_id' => $userId,
            'request_type' => $data['request_type'],
            'status' => 'open',
            'reason' => $data['reason'] ?? null,
            'opened_by_admin_user_id' => $request->user()->id,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->writeAdminAudit($request, $userId, 'privacy.request_opened', 'data_request', (string) $id, 'create', 'Customer data request opened: '.$data['request_type'], null, ['request_type' => $data['request_type']]);
        return response()->json(['ok' => true, 'data_request_id' => (int) $id], 201);
    }

    public function updateDataRequest(Request $request, int $requestId)
    {
        $this->requireAdminSchema();
        $item = DB::table('homeops_data_requests')->where('id', $requestId)->first();
        abort_if(!$item, 404, 'Data request not found.');
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])]]);
        $updates = ['status' => $data['status'], 'updated_at' => now()];
        if ($data['status'] === 'completed') {
            $updates['completed_at'] = now();
            $updates['completed_by_admin_user_id'] = $request->user()->id;
        }
        DB::table('homeops_data_requests')->where('id', $requestId)->update($updates);
        $this->writeAdminAudit($request, (int) $item->user_id, 'privacy.request_updated', 'data_request', (string) $requestId, 'update', 'Customer data request updated.', (array) $item, $updates);
        return response()->json(['ok' => true]);
    }

    private function requireAdminSchema(): void
    {
        abort_unless(Schema::hasTable('homeops_request_logs') && Schema::hasTable('homeops_audit_logs') && Schema::hasTable('homeops_support_cases'), 503, 'Run the HomeOps admin observability migration first.');
    }

    private function rangeDays(Request $request): int
    {
        $days = (int) $request->query('days', 30);
        return in_array($days, [7, 14, 30, 60, 90], true) ? $days : 30;
    }

    private function dailySeries(Collection $days, Collection $rows, ?string $dateField = null): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = $dateField ? ($row->{$dateField} ?? null) : $row;
            if (!$value) continue;
            $key = Carbon::parse($value)->format('Y-m-d');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $days->map(fn ($day) => ['date' => $day, 'value' => $counts[$day] ?? 0])->values()->all();
    }

    private function percentile(Collection $values, float $percentile): int
    {
        if ($values->isEmpty()) return 0;
        $index = (int) floor(($values->count() - 1) * $percentile);
        return (int) $values->get($index, 0);
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
    }

    private function recordInventory(): array
    {
        $tables = [
            'homes' => 'Properties', 'rooms' => 'Rooms', 'home_assets' => 'Assets', 'bills' => 'Bills',
            'bill_instances' => 'Bill instances', 'ledger_entries' => 'Transactions', 'receipts' => 'Receipts',
            'receipt_items' => 'Receipt lines', 'receipt_scans' => 'Receipt scans', 'documents' => 'Documents',
            'maintenance_items' => 'Maintenance items', 'maintenance_logs' => 'Maintenance logs',
            'wishlist_items' => 'Projects & plans', 'spending_periods' => 'Spending periods',
            'financial_accounts' => 'Financial accounts', 'monthly_closeouts' => 'Month closeouts',
            'homeops_request_logs' => 'Request logs', 'homeops_audit_logs' => 'Audit records',
            'homeops_support_cases' => 'Support cases', 'homeops_support_messages' => 'Support messages',
        ];

        return collect($tables)->map(fn ($label, $table) => ['table' => $table, 'label' => $label, 'count' => $this->tableCount($table)])->values()->all();
    }

    private function recordCountsForUser(int $userId): array
    {
        $tables = ['homes', 'rooms', 'home_assets', 'bills', 'bill_instances', 'ledger_entries', 'receipts', 'receipt_scans', 'documents', 'maintenance_items', 'maintenance_logs', 'wishlist_items', 'spending_periods', 'financial_accounts', 'monthly_closeouts'];
        $counts = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'user_id')) continue;
            $counts[$table] = (int) DB::table($table)->where('user_id', $userId)->count();
        }
        if (Schema::hasTable('receipt_items') && Schema::hasTable('receipts')) {
            $counts['receipt_items'] = (int) DB::table('receipt_items as i')
                ->join('receipts as r', 'r.id', '=', 'i.receipt_id')
                ->where('r.user_id', $userId)->count();
        }
        $counts['request_logs'] = (int) DB::table('homeops_request_logs')->where('user_id', $userId)->count();
        $counts['audit_logs'] = (int) DB::table('homeops_audit_logs')->where('target_user_id', $userId)->count();
        $counts['support_cases'] = (int) DB::table('homeops_support_cases')->where('user_id', $userId)->count();
        if (Schema::hasTable('homeops_support_messages')) {
            $counts['support_messages'] = (int) DB::table('homeops_support_messages as m')
                ->join('homeops_support_cases as c', 'c.id', '=', 'm.support_case_id')
                ->where('c.user_id', $userId)->count();
        }
        $counts['customer_notes'] = Schema::hasTable('homeops_customer_notes')
            ? (int) DB::table('homeops_customer_notes')->where('user_id', $userId)->count() : 0;
        $counts['data_requests'] = Schema::hasTable('homeops_data_requests')
            ? (int) DB::table('homeops_data_requests')->where('user_id', $userId)->count() : 0;
        $counts['sessions'] = Schema::hasTable('homeops_api_tokens')
            ? (int) DB::table('homeops_api_tokens')->where('user_id', $userId)->count() : 0;
        return $counts;
    }

    private function recentReceiptsForUser(int $userId): Collection
    {
        if (!Schema::hasTable('receipts')) return collect();
        $columns = ['id', 'receipt_date', 'vendor_name_raw', 'total_amount', 'status', 'created_at'];
        foreach (['capture_source', 'extraction_provider', 'extraction_confidence', 'file_name'] as $column) {
            if (Schema::hasColumn('receipts', $column)) $columns[] = $column;
        }
        return DB::table('receipts')->where('user_id', $userId)->orderByDesc('created_at')->limit(30)->get($columns);
    }

    private function recentScansForUser(int $userId): Collection
    {
        if (!Schema::hasTable('receipt_scans')) return collect();
        return DB::table('receipt_scans')->where('user_id', $userId)->orderByDesc('created_at')->limit(30)->get(['id', 'receipt_id', 'status', 'provider', 'confidence', 'file_name', 'mime_type', 'file_size', 'error_message', 'committed_at', 'created_at']);
    }

    private function revokeUserTokens(int $userId): int
    {
        if (!Schema::hasTable('homeops_api_tokens')) return 0;
        return DB::table('homeops_api_tokens')->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    private function insertSupportMessage(Request $request, int $caseId, string $body, string $direction, string $channel, ?string $externalMessageId): int
    {
        $now = now();
        $authorType = $direction === 'inbound' ? 'customer' : 'admin';
        $messageId = DB::table('homeops_support_messages')->insertGetId([
            'support_case_id' => $caseId,
            'author_type' => $authorType,
            'author_user_id' => $direction === 'inbound' ? null : $request->user()->id,
            'direction' => $direction,
            'channel' => $channel,
            'body' => trim($body),
            'external_message_id' => $externalMessageId,
            'happened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $caseUpdates = ['updated_at' => $now];
        if ($direction === 'inbound') $caseUpdates['last_customer_contact_at'] = $now;
        else $caseUpdates['last_admin_contact_at'] = $now;
        DB::table('homeops_support_cases')->where('id', $caseId)->update($caseUpdates);
        return (int) $messageId;
    }

    private function writeAdminAudit(Request $request, ?int $targetUserId, string $eventType, ?string $entityType, ?string $entityId, string $action, string $summary, mixed $before, mixed $after): void
    {
        if (!Schema::hasTable('homeops_audit_logs')) return;
        DB::table('homeops_audit_logs')->insert([
            'request_id' => $request->attributes->get('homeops_request_id'),
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'target_user_id' => $targetUserId,
            'home_id' => null,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'summary' => Str::limit($summary, 500, ''),
            'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'metadata' => null,
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customerSummary(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => HomeOpsAdminAccess::isAdmin($user),
            'account_status' => $user->account_status ?? 'active',
            'plan_key' => $user->plan_key ?? 'core',
            'last_seen_at' => $user->last_seen_at,
            'suspended_at' => $user->suspended_at,
            'suspension_reason' => $user->suspension_reason,
        ];
    }

    private function paginateQuery($query, array $data, string $orderColumn, string $key): array
    {
        $perPage = (int) ($data['per_page'] ?? 80);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc($orderColumn)->forPage($page, $perPage)->get();
        return [
            $key => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))],
        ];
    }
}
