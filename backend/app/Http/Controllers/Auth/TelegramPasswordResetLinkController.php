<?php

namespace App\Http\Controllers\Auth;

use App\Jobs\SendTelegramPasswordRecovery;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

class TelegramPasswordResetLinkController extends PasswordResetLinkController
{
    public function __construct()
    {
        $this->middleware('throttle:password-recovery')->only('store');
    }

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        // Queue every valid identifier, including unknown ones: no account lookup/timing leak here.
        SendTelegramPasswordRecovery::dispatch(Str::lower(trim($request->string('email')->toString())), now()->getTimestamp());

        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => 'recovery.sent']);
    }
}
