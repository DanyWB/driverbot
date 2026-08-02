<?php

namespace App\Providers;

use App\Domain\Bookings\Services\BookingStatusMutationGuard;
use App\Http\Responses\BotApiResponse;
use App\Models\ServiceApiClient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BookingStatusMutationGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        RateLimiter::for('bot-api', function (Request $request): array {
            $client = $request->attributes->get('service_api_client');
            $serviceKey = match (true) {
                $client instanceof ServiceApiClient => "service:{$client->id}",
                default => "ip:{$request->ip()}",
            };
            $response = fn (Request $request) => BotApiResponse::error(
                $request,
                'rate_limit_exceeded',
                'Too many Bot API requests.',
                429,
            );
            $limits = [
                Limit::perMinute(max(1, (int) config('bot_api.service_rate_limit_per_minute', 600)))
                    ->by($serviceKey)
                    ->response($response),
            ];
            $telegramId = trim((string) $request->header('X-Telegram-User-ID', ''));

            if (preg_match('/^[1-9][0-9]{0,19}$/', $telegramId) === 1) {
                $limits[] = Limit::perMinute(max(1, (int) config('bot_api.user_rate_limit_per_minute', 90)))
                    ->by("{$serviceKey}:telegram:{$telegramId}")
                    ->response($response);
            }

            return $limits;
        });
    }
}
