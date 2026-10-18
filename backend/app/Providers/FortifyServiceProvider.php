<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\Auth\TelegramNewPasswordController;
use App\Http\Controllers\Auth\TelegramPasswordResetLinkController;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PasswordResetLinkController::class, TelegramPasswordResetLinkController::class);
        $this->app->bind(NewPasswordController::class, TelegramNewPasswordController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', (string) $request->input(Fortify::username()))
                ->where('is_active', true)
                ->first();

            if ($user !== null && Hash::check((string) $request->input('password'), $user->password)) {
                $request->session()->put('admin_session_version', $user->session_version);

                return $user;
            }

            return null;
        });
        Passkeys::authorizeLoginUsing(
            fn (Request $request, PasskeyUser $user, Passkey $passkey): bool => $user instanceof User
                && (bool) $user->is_active,
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        /* @chisel-email-verification */
        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));
        /* @end-chisel-email-verification */

        /* @chisel-2fa */
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));
        /* @end-chisel-2fa */

        /* @chisel-password-confirmation */
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
        /* @end-chisel-password-confirmation */
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('password-recovery', fn (Request $request): array => [
            Limit::perMinute(5)->by('recovery-ip:'.$request->ip()),
            Limit::perHour(5)->by('recovery-email:'.$this->recoveryKey($request, 'email')),
        ]);
        RateLimiter::for('password-recovery-reset', fn (Request $request): array => [
            Limit::perMinute(10)->by('recovery-reset-ip:'.$request->ip()),
            Limit::perMinute(5)->by('recovery-reset-token:'.$this->recoveryKey($request, 'token')),
        ]);
        /* @chisel-2fa */
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
        /* @end-chisel-2fa */

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        /* @chisel-passkeys */
        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
        /* @end-chisel-passkeys */
    }

    private function recoveryKey(Request $request, string $field): string
    {
        $value = $request->input($field);

        return hash('sha256', is_string($value) ? Str::lower(trim($value)) : '');
    }
}
