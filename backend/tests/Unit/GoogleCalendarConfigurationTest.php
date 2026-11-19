<?php

namespace Tests\Unit;

use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarConfiguration;
use Tests\TestCase;

class GoogleCalendarConfigurationTest extends TestCase
{
    public function test_production_cannot_process_local_simulator_work(): void
    {
        $this->app->instance('env', 'production');
        config(['google_calendar.driver' => 'local', 'google_calendar.calendars.pickup' => 'pickup', 'google_calendar.calendars.return' => 'return']);

        $this->assertFalse(app(GoogleCalendarConfiguration::class)->ready());

        config(['google_calendar.driver' => 'google']);
        $this->assertTrue(app(GoogleCalendarConfiguration::class)->ready());
    }
}
