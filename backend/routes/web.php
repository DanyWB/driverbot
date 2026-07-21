<?php

use App\Http\Controllers\Bookings\BookingController;
use App\Http\Controllers\Bookings\BookingDatesController;
use App\Http\Controllers\Bookings\BookingPriceOverrideController;
use App\Http\Controllers\Bookings\BookingQuoteController;
use App\Http\Controllers\Bookings\BookingStatusController;
use App\Http\Controllers\Bookings\CustomerLookupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('timeline', TimelineController::class)->name('timeline.index');

    Route::get('bookings/quote', [BookingQuoteController::class, 'create'])->name('bookings.quote');
    Route::get('bookings/customers/search', CustomerLookupController::class)->name('bookings.customers.search');
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('bookings/{booking}/quote', [BookingQuoteController::class, 'booking'])->name('bookings.booking-quote');
    Route::patch('bookings/{booking}/dates', [BookingDatesController::class, 'update'])->name('bookings.dates.update');
    Route::post('bookings/{booking}/price-overrides', [BookingPriceOverrideController::class, 'store'])->name('bookings.price-overrides.store');
    Route::post('bookings/{booking}/approve', [BookingStatusController::class, 'approve'])->name('bookings.approve');
    Route::post('bookings/{booking}/activate', [BookingStatusController::class, 'activate'])->name('bookings.activate');
    Route::post('bookings/{booking}/complete', [BookingStatusController::class, 'complete'])->name('bookings.complete');
    Route::post('bookings/{booking}/cancel', [BookingStatusController::class, 'cancel'])->name('bookings.cancel');
    Route::post('bookings/{booking}/no-show', [BookingStatusController::class, 'noShow'])->name('bookings.no-show');
});

require __DIR__.'/settings.php';
