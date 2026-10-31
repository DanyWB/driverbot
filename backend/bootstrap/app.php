<?php

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateServiceApiClient;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminSessionVersion;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTelegramCustomer;
use App\Http\Middleware\WebsiteAdminAccess;
use App\Http\Middleware\WebsitePreviewAccess;
use App\Http\Responses\BotApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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
        $middleware->append(AddSecurityHeaders::class);
        $middleware->append(WebsitePreviewAccess::class);
        $middleware->trustHosts(subdomains: false);

        $middleware->alias([
            'admin.active' => EnsureAdminIsActive::class,
            'service.api' => AuthenticateServiceApiClient::class,
            'telegram.customer' => ResolveTelegramCustomer::class,
        ]);
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            AuthenticateServiceApiClient::class,
        );

        $middleware->encryptCookies(except: ['admin_locale', 'appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureAdminSessionVersion::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request): null {
            if ($request->routeIs('admin.website.*') && ! $request->expectsJson()) {
                // Image/preview GETs and another browser tab can update the shared
                // session previous URL. Preserve privacy/no-referrer while always
                // returning CMS validation to its owning editor, with normal old
                // input/errors handled by Laravel; never redirect to binary media.
                $exception->redirectTo(WebsiteAdminAccess::validationEditor($request));
            } elseif ($request->routeIs('website.booking.store') && ! $request->expectsJson()) {
                // Quote/media GETs also replace a guest session's previous URL.
                // Keep submitted dates/contact only in normal flashed old input,
                // never in the deterministic owning form's redirect URL.
                $exception->redirectTo(route('website.booking', [
                    'locale' => $request->route('locale'),
                    'vehicle' => $request->route('vehicle'),
                ]));
            }

            return null;
        });
        $exceptions->dontReportWhen(fn (Throwable $exception): bool => match (true) {
            $exception instanceof BotApiException => $exception->httpStatus < 500,
            $exception instanceof BookingException => $exception->httpStatus < 500,
            $exception instanceof PricingException => true,
            default => false,
        });
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
