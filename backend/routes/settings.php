<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TelegramController;
/* @chisel-password-confirmation */
use Illuminate\Auth\Middleware\RequirePassword;
/* @end-chisel-password-confirmation */
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin.active'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'admin.active', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        /* @chisel-password-confirmation */
        ->middleware(RequirePassword::class)
        /* @end-chisel-password-confirmation */
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::middleware(RequirePassword::class)->group(function (): void {
        Route::get('settings/telegram', [TelegramController::class, 'edit'])
            ->name('telegram.edit');
        Route::post('settings/telegram/binding-code', [TelegramController::class, 'issueCode'])
            ->middleware('throttle:5,1')
            ->name('telegram.binding-code.store');
        Route::post('settings/telegram/test', [TelegramController::class, 'test'])
            ->middleware('throttle:3,1')
            ->name('telegram.test');
        Route::delete('settings/telegram/binding', [TelegramController::class, 'destroy'])
            ->middleware('throttle:5,1')
            ->name('telegram.binding.destroy');
    });
});

/* @chisel-passkeys */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
/* @end-chisel-passkeys */
