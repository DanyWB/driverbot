<?php

use App\Http\Controllers\Website\WebsiteController;
use App\Http\Controllers\WebsiteAdmin\WebsiteMediaController;
use App\Http\Middleware\WebsiteAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(WebsiteAccess::class)->name('website.')->group(function (): void {
    Route::get('website-media/{asset}/{variant}/{sha256}.webp', [WebsiteMediaController::class, 'published'])
        ->whereUuid('asset')->where('variant', 'card|card_2x|detail')->where('sha256', '[a-f0-9]{64}')->name('media');
    Route::get('sitemap.xml', [WebsiteController::class, 'sitemap'])->name('sitemap');
    Route::get('robots.txt', [WebsiteController::class, 'robots'])->name('robots');
    Route::prefix('{locale}')->where(['locale' => 'en|ru|uk'])->group(function (): void {
        Route::get('/', [WebsiteController::class, 'home'])->name('home');
        Route::get('/{catalog}', [WebsiteController::class, 'catalog'])->where('catalog', 'cars|scooters')->name('catalog');
        Route::get('/vehicles/{vehicle}/quote', [WebsiteController::class, 'quote'])->whereNumber('vehicle')->middleware('throttle:website-read')->name('quote');
        Route::get('/vehicles/{vehicle}/availability', [WebsiteController::class, 'availability'])->whereNumber('vehicle')->middleware('throttle:website-read')->name('availability');
        Route::get('/vehicles/{slug}', [WebsiteController::class, 'vehicle'])->where('slug', '[0-9]+-.+')->name('vehicle');
        Route::get('/book/{vehicle}', [WebsiteController::class, 'booking'])->whereNumber('vehicle')->name('booking');
        Route::post('/book/{vehicle}', [WebsiteController::class, 'store'])->whereNumber('vehicle')->middleware('throttle:website-submit')->block(10, 10)->name('booking.store');
        Route::get('/requests/{reference}', [WebsiteController::class, 'status'])->whereUuid('reference')->name('status');
        Route::get('/policies/{policy}', [WebsiteController::class, 'policy'])->where('policy', 'terms|privacy|contact')->name('policy');
    });
});
