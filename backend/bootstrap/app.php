<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateServiceApiClient;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTelegramCustomer;
use App\Http\Responses\BotApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health/live',
        then: function (): void {
            Route::get('/health/ready', HealthController::class)->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        $middleware->alias([
            'service.api' => AuthenticateServiceApiClient::class,
            'telegram.customer' => ResolveTelegramCustomer::class,
        ]);
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AuthenticateServiceApiClient::class,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/bot*')) {
                return null;
            }

            return app(BotApiExceptionRenderer::class)->render($request, $exception);
        });
    })->create();
