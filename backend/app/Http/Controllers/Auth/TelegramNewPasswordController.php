<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Administration\Services\TelegramPasswordRecovery;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

class TelegramNewPasswordController extends NewPasswordController
{
    public function __construct(StatefulGuard $guard)
    {
        parent::__construct($guard);
        $this->middleware('throttle:password-recovery-reset')->only('store');
    }

    public function store(Request $request): Responsable
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'string', 'max:256'],
            'password_confirmation' => ['required', 'string', 'max:256'],
        ]);
        $input['email'] = Str::lower(trim($input['email']));
        $status = app(TelegramPasswordRecovery::class)->reset($input);

        if ($status === Password::PASSWORD_RESET) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return app(PasswordResetResponse::class, ['status' => $status]);
        }

        return app(FailedPasswordResetResponse::class, ['status' => 'recovery.invalid']);
    }
}
