<?php

use App\Http\Controllers\WebsiteAdmin\WebsiteCardController;
use App\Http\Controllers\WebsiteAdmin\WebsiteMediaController;
use App\Http\Controllers\WebsiteAdmin\WebsitePageController;
use App\Http\Controllers\WebsiteAdmin\WebsitePreviewController;
use App\Http\Controllers\WebsiteAdmin\WebsiteSettingsController;
use App\Http\Middleware\WebsiteAdminAccess;
use Illuminate\Support\Facades\Route;

// String route arguments deliberately avoid implicit CMS model binding before
// WebsiteAdminAccess can reject a disabled feature or a customer-preview host.
Route::prefix('website')->name('admin.website.')->middleware([WebsiteAdminAccess::class, 'auth', 'admin.active', 'verified'])->group(function (): void {
    Route::get('cards', [WebsiteCardController::class, 'index'])->name('cards.index');
    Route::get('cards/{vehicle}', [WebsiteCardController::class, 'edit'])->whereNumber('vehicle')->name('cards.edit');
    foreach (['draft', 'publish', 'unpublish'] as $action) {
        Route::post('cards/{vehicle}/'.$action, [WebsiteCardController::class, $action])->whereNumber('vehicle')->name('cards.'.$action);
    }
    Route::get('cards/{vehicle}/preview/{locale}', [WebsitePreviewController::class, 'card'])->whereNumber('vehicle')->where('locale', 'en|ru|uk')->name('cards.preview');
    Route::post('cards/{vehicle}/media', [WebsiteMediaController::class, 'store'])->whereNumber('vehicle')->middleware('throttle:10,1')->name('media.store');
    Route::delete('media/{asset}', [WebsiteMediaController::class, 'destroy'])->whereUuid('asset')->name('media.destroy');
    Route::get('media/{asset}/{variant}', [WebsiteMediaController::class, 'preview'])->whereUuid('asset')->where('variant', 'card|card_2x|detail')->name('media.preview');
    Route::get('pages', [WebsitePageController::class, 'index'])->name('pages.index');
    Route::get('pages/{key}', [WebsitePageController::class, 'edit'])->where('key', 'home|cars|scooters|contact|terms|privacy')->name('pages.edit');
    foreach (['draft', 'publish', 'unpublish'] as $action) {
        Route::post('pages/{key}/'.$action, [WebsitePageController::class, $action])->where('key', 'home|cars|scooters|contact|terms|privacy')->name('pages.'.$action);
    }
    Route::post('pages/{key}/restore/{revision}', [WebsitePageController::class, 'restore'])->where('key', 'home|cars|scooters|contact|terms|privacy')->whereNumber('revision')->name('pages.restore');
    Route::get('pages/{key}/preview/{locale}', [WebsitePreviewController::class, 'page'])->where('key', 'home|cars|scooters|contact|terms|privacy')->where('locale', 'en|ru|uk')->name('pages.preview');
    Route::get('settings', [WebsiteSettingsController::class, 'edit'])->name('settings.edit');
    foreach (['draft', 'publish', 'unpublish'] as $action) {
        Route::post('settings/'.$action, [WebsiteSettingsController::class, $action])->name('settings.'.$action);
    }
});
