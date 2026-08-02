<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('operations:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:00')
    ->timezone(config('business.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('bookings:expire-pending')
    ->everyTenMinutes()
    ->timezone(config('business.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('bookings:reconcile-reminders')
    ->everyTenMinutes()
    ->timezone(config('business.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notifications:dispatch-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notifications:monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => app()->isProduction());

Schedule::command('operations:housekeeping')
    ->dailyAt('03:20')
    ->timezone(config('business.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
