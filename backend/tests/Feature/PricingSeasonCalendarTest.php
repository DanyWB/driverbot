<?php

namespace Tests\Feature;

use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingSeasonCalendarService;
use App\Domain\Pricing\Services\PricingSettings;
use App\Models\AuditLog;
use App\Models\PricingPreset;
use App\Models\PricingSeasonMonth;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingSeasonCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT = ['high', 'high', 'high', 'middle', 'middle', 'low', 'low', 'low', 'low', 'middle', 'middle', 'high'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
    }

    private function preset(): PricingPreset
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/customer-pricing-presets-20261029.json')), true, 512, JSON_THROW_ON_ERROR);
        $source = collect($payload['presets'])->firstWhere('group', 'PCX/ADV 160 - 2023');

        return app(PricingPresetService::class)->create(app(PricingSettings::class)->importPreset($source), $this->admin->id);
    }

    public function test_page_and_endpoint_expose_the_seeded_calendar(): void
    {
        $this->assertSame(self::DEFAULT, app(PricingSeasonCalendarService::class)->current());
        $this->actingAs($this->admin)->getJson(route('pricing-presets.season-calendar.show'))
            ->assertOk()->assertExactJson(['data' => self::DEFAULT]);
    }

    public function test_apply_updates_months_presets_linked_and_detached_vehicles_and_quotes(): void
    {
        $preset = $this->preset();
        $linked = Vehicle::factory()->create()->refresh();
        app(PricingPresetService::class)->attach($linked, $preset, (int) $preset->version, null, (int) $linked->pricing_version, 'en');
        $legacy = Vehicle::factory()->create(['calculation_settings' => null]);
        $preset->refresh();
        $linked->refresh();
        $presetVersion = (int) $preset->version;
        $vehicleVersion = (int) $linked->pricing_version;
        $legacyVersion = (int) $legacy->refresh()->pricing_version;

        $aprilQuote = route('pricing-presets.calculate', $preset).'?starts_on=2027-04-01&ends_on=2027-04-28';
        $before = $this->actingAs($this->admin)->getJson($aprilQuote)->assertOk()->json('data');
        $this->assertSame('middle', $before['season']);

        $calendar = self::DEFAULT;
        $calendar[3] = 'high'; // April becomes high season
        $calendar[8] = 'middle'; // September becomes middle season

        $this->actingAs($this->admin)->putJson(route('pricing-presets.season-calendar.update'), ['confirmed' => true, 'calendar' => $calendar])
            ->assertOk()
            ->assertJsonPath('data', $calendar)
            ->assertJsonPath('updated_presets', 1)
            ->assertJsonPath('updated_vehicles', 1)
            ->assertJsonPath('presets.0.calculation_settings.prefs.seasonCalendar', $calendar);

        $this->assertSame($calendar, app(PricingSeasonCalendarService::class)->current());
        $this->assertSame(12, PricingSeasonMonth::query()->count());
        $preset->refresh();
        $linked->refresh();
        $this->assertSame($calendar, $preset->calculation_settings['prefs']['seasonCalendar']);
        $this->assertSame($presetVersion + 1, (int) $preset->version);
        $this->assertSame($calendar, $linked->calculation_settings['prefs']['seasonCalendar']);
        $this->assertSame($vehicleVersion + 1, (int) $linked->pricing_version);
        $this->assertNull($legacy->refresh()->calculation_settings);
        $this->assertSame($legacyVersion, (int) $legacy->pricing_version);

        $after = $this->actingAs($this->admin)->getJson($aprilQuote)->assertOk()->json('data');
        $this->assertSame('high', $after['season']);
        $this->assertGreaterThan($before['total'], $after['total']);

        $this->assertTrue(AuditLog::query()->where('action', 'pricing_season_calendar.updated')->exists());

        // Re-applying the same calendar is a no-op for presets and vehicles.
        $this->actingAs($this->admin)->putJson(route('pricing-presets.season-calendar.update'), ['confirmed' => true, 'calendar' => $calendar])
            ->assertOk()->assertJsonPath('updated_presets', 0)->assertJsonPath('updated_vehicles', 0);
        $this->assertSame($presetVersion + 1, (int) $preset->refresh()->version);
    }

    public function test_invalid_or_unconfirmed_calendar_changes_nothing(): void
    {
        $bad = self::DEFAULT;
        $bad[0] = 'summer';

        $this->actingAs($this->admin)->putJson(route('pricing-presets.season-calendar.update'), ['confirmed' => true, 'calendar' => $bad])
            ->assertUnprocessable()->assertJsonValidationErrors('calendar.0');
        $this->actingAs($this->admin)->putJson(route('pricing-presets.season-calendar.update'), ['confirmed' => true, 'calendar' => array_slice(self::DEFAULT, 0, 11)])
            ->assertUnprocessable()->assertJsonValidationErrors('calendar');
        $this->actingAs($this->admin)->putJson(route('pricing-presets.season-calendar.update'), ['calendar' => self::DEFAULT])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');

        $this->assertSame(self::DEFAULT, app(PricingSeasonCalendarService::class)->current());
    }

    public function test_guests_cannot_change_the_calendar(): void
    {
        $this->putJson(route('pricing-presets.season-calendar.update'), ['confirmed' => true, 'calendar' => self::DEFAULT])
            ->assertUnauthorized();
    }
}
