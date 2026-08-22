<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'homeops.feature' => \App\Http\Middleware\HomeOpsFeatureGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Keep a searchable operational breadcrumb for unhandled application failures.
        // Intentionally store only class + basename + line rather than a full stack trace so
        // customer data, credentials, and request payloads are not duplicated into the log.
        $exceptions->report(function (\Throwable $exception): void {
            try {
                if (!\Illuminate\Support\Facades\Schema::hasTable('homeops_system_events')) {
                    return;
                }

                $request = request();
                $user = $request?->user();
                $message = trim((string) $exception->getMessage()) ?: class_basename($exception);

                \Illuminate\Support\Facades\DB::table('homeops_system_events')->insert([
                    'request_id' => $request?->attributes->get('homeops_request_id'),
                    'user_id' => $user?->id,
                    'severity' => $exception instanceof \Error ? 'critical' : 'error',
                    'category' => 'exception',
                    'source' => class_basename($exception),
                    'message' => \Illuminate\Support\Str::limit($message, 1000, ''),
                    'context' => json_encode([
                        'exception_class' => get_class($exception),
                        'file' => basename($exception->getFile()),
                        'line' => $exception->getLine(),
                        'route' => $request?->route()?->uri(),
                        'method' => $request?->method(),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable) {
                // Exception reporting must never recursively cause another application failure.
            }
        });
    })->create();
