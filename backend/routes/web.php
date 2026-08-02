<?php

use App\Http\Controllers\Bookings\BookingAdminNoteController;
use App\Http\Controllers\Bookings\BookingController;
use App\Http\Controllers\Bookings\BookingCsvExportController;
use App\Http\Controllers\Bookings\BookingDatesController;
use App\Http\Controllers\Bookings\BookingPriceOverrideController;
use App\Http\Controllers\Bookings\BookingPriceRecalculationController;
use App\Http\Controllers\Bookings\BookingQuoteController;
use App\Http\Controllers\Bookings\BookingStatusController;
use App\Http\Controllers\Bookings\CustomerLookupController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\Vehicles\CategoryController;
use App\Http\Controllers\Vehicles\VehicleController;
use App\Http\Controllers\Vehicles\VehiclePhotoController;
use App\Http\Controllers\Vehicles\VehiclePricingController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'admin.active', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('timeline', TimelineController::class)->name('timeline.index');

    Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::get('vehicles/create', [VehicleController::class, 'create'])->name('vehicles.create');
    Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
    Route::get('vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('vehicles.edit');
    Route::patch('vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
    Route::post('vehicles/{vehicle}/photos', [VehiclePhotoController::class, 'store'])->name('vehicles.photos.store');
    Route::patch('vehicles/{vehicle}/photos', [VehiclePhotoController::class, 'arrange'])->name('vehicles.photos.arrange');
    Route::delete('vehicles/{vehicle}/photos/{photo}', [VehiclePhotoController::class, 'destroy'])->name('vehicles.photos.destroy');
    Route::patch('vehicles/{vehicle}/pricing', [VehiclePricingController::class, 'update'])->name('vehicles.pricing.update');
    Route::get('vehicles/{vehicle}/pricing/generate', [VehiclePricingController::class, 'generate'])->name('vehicles.pricing.generate');
    Route::get('vehicles/{vehicle}/pricing/quote', [VehiclePricingController::class, 'quote'])->name('vehicles.pricing.quote');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('customers/{customer}/documents', [CustomerDocumentController::class, 'storeForCustomer'])->name('customers.documents.store');
    Route::post('bookings/{booking}/documents', [CustomerDocumentController::class, 'storeForBooking'])->name('bookings.documents.store');
    Route::get('documents/{document}', [CustomerDocumentController::class, 'download'])->name('documents.download');
    Route::delete('documents/{document}', [CustomerDocumentController::class, 'destroy'])->name('documents.destroy');

    Route::get('bookings/quote', [BookingQuoteController::class, 'create'])->name('bookings.quote');
    Route::get('bookings/customers/search', CustomerLookupController::class)->name('bookings.customers.search');
    Route::get('bookings/export.csv', BookingCsvExportController::class)->name('bookings.export');
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('bookings/{booking}/quote', [BookingQuoteController::class, 'booking'])->name('bookings.booking-quote');
    Route::patch('bookings/{booking}/dates', [BookingDatesController::class, 'update'])->name('bookings.dates.update');
    Route::patch('bookings/{booking}/admin-note', [BookingAdminNoteController::class, 'update'])->name('bookings.admin-note.update');
    Route::post('bookings/{booking}/price-recalculations', [BookingPriceRecalculationController::class, 'store'])->name('bookings.price-recalculations.store');
    Route::post('bookings/{booking}/price-overrides', [BookingPriceOverrideController::class, 'store'])->name('bookings.price-overrides.store');
    Route::post('bookings/{booking}/approve', [BookingStatusController::class, 'approve'])->name('bookings.approve');
    Route::post('bookings/{booking}/activate', [BookingStatusController::class, 'activate'])->name('bookings.activate');
    Route::post('bookings/{booking}/complete', [BookingStatusController::class, 'complete'])->name('bookings.complete');
    Route::post('bookings/{booking}/cancel', [BookingStatusController::class, 'cancel'])->name('bookings.cancel');
    Route::post('bookings/{booking}/no-show', [BookingStatusController::class, 'noShow'])->name('bookings.no-show');
});

require __DIR__.'/settings.php';
