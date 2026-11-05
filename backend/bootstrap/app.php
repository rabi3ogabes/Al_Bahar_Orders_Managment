<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetApiLocale;
use App\Support\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [SetApiLocale::class]);
        $middleware->web(append: [SetAdminLocale::class]);
        $middleware->alias([
            'staff' => EnsureStaff::class,
            'customer' => EnsureCustomer::class,
            'permission' => RequirePermission::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin*') || $request->is('driver*') ? route('admin.login') : null);
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'payments/sadad/return']);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([DomainException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );

        /*
         * Uniform error envelope for the API:
         * { "message": string, "code": string, "errors"?: {field: [msg]}, "request_id": string }
         * Production responses never contain stack traces or exception internals.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson())) {
                return null; // web: default rendering (custom error views in resources/views/errors)
            }
            $requestId = $request->attributes->get('request_id');
            $payload = fn (string $code, string $message, int $status, array $extra = []) => response()->json(
                array_merge(['message' => $message, 'code' => $code], $extra, ['request_id' => $requestId]),
                $status,
                $status === 429 && $e instanceof ThrottleRequestsException ? $e->getHeaders() : []
            );

            return match (true) {
                $e instanceof ValidationException => $payload('validation_failed', __('errors.validation_failed'), 422, ['errors' => $e->errors()]),
                $e instanceof DomainException => $payload($e->errorCode, $e->getMessage(), $e->status, $e->context ? ['context' => $e->context] : []),
                $e instanceof AuthenticationException => $payload('unauthenticated', __('errors.unauthenticated'), 401),
                $e instanceof AuthorizationException => $payload('forbidden', __('errors.forbidden'), 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $payload('not_found', __('errors.not_found'), 404),
                $e instanceof ThrottleRequestsException => $payload('too_many_requests', __('errors.too_many_requests'), 429),
                $e instanceof HttpExceptionInterface => $payload(
                    match ($e->getStatusCode()) {
                        403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict', 503 => 'unavailable', default => 'http_error'
                    },
                    $e->getStatusCode() === 403 ? __('errors.forbidden') : ($e->getMessage() ?: __('errors.server_error')),
                    $e->getStatusCode()
                ),
                default => $payload('server_error', config('app.debug') ? $e->getMessage() : __('errors.server_error'), 500),
            };
        });
    })->create();
