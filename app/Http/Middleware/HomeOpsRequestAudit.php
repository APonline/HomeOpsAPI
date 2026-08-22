<?php

namespace App\Http\Middleware;

use App\Support\HomeOpsAdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class HomeOpsRequestAudit
{
    private const REDACT_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token', 'authorization',
        'secret', 'api_key', 'access_token', 'refresh_token', 'receipt_file', 'file',
        'raw_ocr_text',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('homeops_request_id', $requestId);
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $status = method_exists($exception, 'getStatusCode')
                ? (int) $exception->getStatusCode()
                : (property_exists($exception, 'status') && is_numeric($exception->status) ? (int) $exception->status : 500);
            $this->writeLog($request, $requestId, $status, $started, $exception->getMessage());
            throw $exception;
        }

        $response->headers->set('X-HomeOps-Request-Id', $requestId);
        $this->writeLog($request, $requestId, $response->getStatusCode(), $started, $this->responseError($response));

        return $response;
    }

    private function writeLog(Request $request, string $requestId, int $status, int $started, ?string $error): void
    {
        if (!Schema::hasTable('homeops_request_logs')) {
            return;
        }

        $user = $request->user();
        $durationMs = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
        $route = $request->route()?->uri() ?: $request->path();
        $category = $this->categoryFor($request);
        $isAdmin = HomeOpsAdminAccess::isAdmin($user);
        $homeId = $request->input('home_id')
            ?? $request->query('home_id')
            ?? $request->route('homeId')
            ?? null;

        $payload = $this->redact($request->except(self::REDACT_KEYS));
        $query = $this->redact($request->query());

        try {
            DB::table('homeops_request_logs')->insert([
                'request_id' => $requestId,
                'user_id' => $user?->id,
                'admin_user_id' => $isAdmin && str_contains($request->path(), '/admin') ? $user?->id : null,
                'home_id' => is_numeric($homeId) ? (int) $homeId : null,
                'category' => $category,
                'action' => $request->method().' '.$route,
                'route' => Str::limit($route, 500, ''),
                'method' => $request->method(),
                'response_status' => $status,
                'duration_ms' => $durationMs,
                'ip_address' => Str::limit((string) $request->ip(), 45, ''),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'query_data' => empty($query) ? null : json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'request_data' => empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'error_message' => $error ? Str::limit($error, 1000, '') : null,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($request->method() !== 'GET' && Schema::hasTable('homeops_audit_logs')) {
                $this->writeAuditLog($request, $requestId, $status, $payload, $homeId, $isAdmin);
            }

            if ($user && Schema::hasColumn('users', 'last_seen_at')) {
                DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
            }
        } catch (\Throwable $loggingFailure) {
            // Audit logging must never be able to break the customer action it is observing.
            report($loggingFailure);
        }
    }

    private function writeAuditLog(Request $request, string $requestId, int $status, array $payload, mixed $homeId, bool $isAdmin): void
    {
        $user = $request->user();
        $routeParameters = $request->route()?->parameters() ?? [];
        $entityId = collect($routeParameters)
            ->first(fn ($value, $key) => $key !== 'homeId' && (is_numeric($value) || is_string($value)));
        $entityType = $this->categoryFor($request);
        $targetUserId = $request->route('userId') ?? $request->input('user_id') ?? $user?->id;
        $successful = $status >= 200 && $status < 400;
        $action = strtolower($request->method());

        DB::table('homeops_audit_logs')->insert([
            'request_id' => $requestId,
            'actor_type' => $isAdmin && str_contains($request->path(), '/admin') ? 'admin' : 'user',
            'actor_user_id' => $user?->id,
            'target_user_id' => is_numeric($targetUserId) ? (int) $targetUserId : null,
            'home_id' => is_numeric($homeId) ? (int) $homeId : null,
            'event_type' => $successful ? 'request.completed' : 'request.failed',
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? Str::limit((string) $entityId, 100, '') : null,
            'action' => $action,
            'summary' => Str::limit(sprintf('%s %s returned %d', $request->method(), $request->route()?->uri() ?: $request->path(), $status), 500, ''),
            'before_data' => null,
            'after_data' => empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'metadata' => json_encode([
                'status' => $status,
                'route_parameters' => $routeParameters,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => Str::limit((string) $request->ip(), 45, ''),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function categoryFor(Request $request): string
    {
        $segments = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        $homeopsIndex = array_search('homeops', $segments, true);
        $candidate = $homeopsIndex !== false ? ($segments[$homeopsIndex + 1] ?? 'application') : 'application';

        if ($candidate === 'admin') {
            $candidate = $segments[$homeopsIndex + 2] ?? 'admin';
        }

        return Str::limit(str_replace(['-', '_'], ' ', (string) $candidate), 80, '');
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = $this->redact($childValue, (string) $childKey);
            }
            return $clean;
        }

        if (is_string($value) && strlen($value) > 4000) {
            return Str::limit($value, 4000, '…');
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        return collect(self::REDACT_KEYS)->contains(fn ($blocked) => str_contains($normalized, $blocked));
    }

    private function responseError(Response $response): ?string
    {
        if ($response->getStatusCode() < 400 || !method_exists($response, 'getContent')) {
            return null;
        }

        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);
        $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;

        return $message ? Str::limit((string) $message, 1000, '') : null;
    }
}
