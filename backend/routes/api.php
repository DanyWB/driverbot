<?php

use App\Http\Controllers\Api\V1\Bot\BookingController;
use App\Http\Controllers\Api\V1\Bot\CatalogController;
use App\Http\Controllers\Api\V1\Bot\ConfigurationController;
use App\Http\Controllers\Api\V1\Bot\CustomerController;
use App\Http\Controllers\Api\V1\Bot\DocumentController;
use App\Http\Controllers\Api\V1\Bot\QuoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/bot')->group(function (): void {
    Route::middleware(['service.api:bot:read', 'throttle:bot-api'])->group(function (): void {
        Route::get('configuration', ConfigurationController::class);
        Route::get('categories', [CatalogController::class, 'categories']);
        Route::get('vehicles', [CatalogController::class, 'vehicles']);
        Route::get('vehicles/available', [CatalogController::class, 'available']);
        Route::get('vehicles/{vehicle}/availability', [CatalogController::class, 'availability'])->whereNumber('vehicle');
        Route::get('vehicles/{vehicle}', [CatalogController::class, 'show'])->whereNumber('vehicle');
        Route::post('quotes', QuoteController::class);

        Route::middleware('telegram.customer')->group(function (): void {
            Route::get('customers/me', [CustomerController::class, 'show']);
            Route::get('customers/me/bookings', [BookingController::class, 'index']);
            Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereUuid('booking');
        });
    });

    Route::middleware(['service.api:bot:write', 'throttle:bot-api'])->group(function (): void {
        Route::post('customers/sync', [CustomerController::class, 'sync']);

        Route::middleware('telegram.customer')->group(function (): void {
            Route::patch('customers/me', [CustomerController::class, 'update']);
            Route::post('bookings', [BookingController::class, 'store']);
            Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->whereUuid('booking');
        });
    });

    Route::middleware(['service.api:bot:documents', 'throttle:bot-api', 'telegram.customer'])->group(function (): void {
        Route::post('customers/me/documents', [DocumentController::class, 'storeForCustomer']);
        Route::post('bookings/{booking}/documents', [DocumentController::class, 'storeForBooking'])->whereUuid('booking');
    });

    Route::fallback(fn () => abort(404))->middleware(['service.api:bot:read', 'throttle:bot-api']);
});
