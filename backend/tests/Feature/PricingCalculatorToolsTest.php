<?php

namespace Tests\Feature;

use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\Services\PricingSettings;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\Booking;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingCalculatorToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/customer-pricing-presets-20261029.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function data(): array
    {
        $source = collect($this->payload()['presets'])->firstWhere('group', 'PCX/ADV 160 - 2023');

        return app(PricingSettings::class)->importPreset($source);
    }

    private function preset(): PricingPreset
    {
        return app(PricingPresetService::class)->create($this->data(), $this->admin->id);
    }

    public function test_all_twenty_presets_match_1860_prices_extracted_from_actual_customer_html(): void
    {
        $expected = json_decode(file_get_contents(base_path('tests/Fixtures/customer-pricing-matrix-expected-20261029.json')), true, 512, JSON_THROW_ON_ERROR);
        $import = app(PricingSettingsImport::class)->preview($this->payload());
        $this->assertCount(20, $import['presets']);
        $this->assertCount(33, $import['assignments']);
        foreach ($import['presets'] as $name => $preset) {
            $this->assertSame($expected[$name], app(PricingCalculator::class)->matrix($preset['prices'], $preset['calculation_settings']), $name);
            $this->assertCount(12, $preset['calculation_settings']['prefs']['seasonCalendar']);
        }
    }

    public function test_original_twenty_anchor_matrices_and_settings_survive_save_and_native_import(): void
    {
        $imported = app(PricingSettingsImport::class)->preview($this->payload());
        $native = [];
        foreach ($imported['presets'] as $name => $data) {
            $preset = app(PricingPresetService::class)->create($data, $this->admin->id);
            for ($save = 0; $save < 2; $save++) {
                $this->assertSame($data['prices'], app(PricingPresetService::class)->matrix($preset), $name);
                $this->assertSame($data['calculation_settings'], $preset->calculation_settings, $name);
                $preset = app(PricingPresetService::class)->update($preset, [
                    ...$data, 'version' => $preset->version, 'expected_linked_vehicles_count' => 0,
                ], $this->admin->id, 'en');
            }
            $this->assertSame($data['prices'], app(PricingPresetService::class)->matrix($preset), $name);
            $native[] = ['id' => $preset->id, 'name' => $name, 'vehicle_type' => null, ...$data];
        }
        $reimported = app(PricingSettingsImport::class)->preview([
            'format' => 'drive-pricing-presets', 'version' => 1, 'presets' => $native, 'assignments' => [],
        ]);
        foreach ($imported['presets'] as $name => $data) {
            $this->assertSame($data['prices'], $reimported['presets'][$name]['prices'], $name);
            $this->assertSame($data['calculation_settings'], $reimported['presets'][$name]['calculation_settings'], $name);
        }
        $hrv = PricingPreset::query()->where('name', 'HR-V 2020')->firstOrFail();
        $this->assertSame(15000, app(PricingPresetService::class)->matrix($hrv)['low']['21d']);
    }

    public function test_linked_prices_match_javascript_below_at_above_half_and_validated_bounds(): void
    {
        $source = app(PricingSettingsImport::class)->preview($this->payload())['presets']['HR-V 2020'];
        $settings = $source['calculation_settings'];
        $settings['prefs']['linkLow'] = false;
        $prices = $source['prices'];
        $prices['high'] = array_fill_keys(PricingCalculator::TIERS, 1000);
        foreach ([[75.00000000000001, 200], [75, 300], [74.99999999999999, 300]] as [$discount, $expected]) {
            $settings['prefs']['discounts']['middle'] = array_fill(0, 5, $discount);
            $settings['prefs']['roundSteps']['middle'] = array_fill(0, 5, 100);
            $linked = app(PricingSettings::class)->linkedPrices($prices, $settings);
            $this->assertSame(array_fill_keys(PricingCalculator::TIERS, $expected), $linked['middle']);
            $this->assertSame($prices['high'], $linked['high']);
            $this->assertSame($prices['low'], $linked['low']);
        }
        $prices['high'] = array_fill_keys(PricingCalculator::TIERS, 100000000);
        $settings['prefs']['discounts']['middle'] = array_fill(0, 5, 0);
        $settings['prefs']['roundSteps']['middle'] = array_fill(0, 5, 1);
        $this->assertSame($prices['high'], app(PricingSettings::class)->linkedPrices($prices, $settings)['middle']);
        $this->assertSame($prices, app(PricingSettings::class)->linkedPrices($prices, null));
    }

    public function test_27_28_29_days_and_preliminary_long_periods_preserve_customer_boundary(): void
    {
        $data = $this->data();
        $calculator = app(PricingCalculator::class);
        foreach ([27 => 6150, 28 => 6500, 29 => 6500, 30 => 6500, 31 => 6500, 32 => 6850, 60 => 13000, 61 => 13000, 62 => 13000] as $days => $expected) {
            $quote = $calculator->quote($data['prices'], $data['calculation_settings'], days: $days, season: 'middle');
            $this->assertSame($expected, $quote['total'], "day {$days}");
            $this->assertSame('days-preview', $quote['mode']);
        }
    }

    public function test_calendar_month_anniversaries_clamp_from_original_start_and_handle_leap_year(): void
    {
        $data = $this->data();
        $calculator = app(PricingCalculator::class);
        foreach ([['2027-01-31', '2027-02-28', 1, 0], ['2028-01-31', '2028-02-29', 1, 0], ['2028-02-29', '2028-03-29', 1, 0], ['2027-01-31', '2027-03-31', 2, 0], ['2027-01-31', '2027-04-02', 2, 2], ['2026-12-31', '2027-03-31', 3, 0], ['2027-04-30', '2027-05-31', 1, 1]] as [$start, $end, $months, $remainder]) {
            $quote = $calculator->quote($data['prices'], $data['calculation_settings'], RentalPeriod::fromStrings($start, $end));
            $this->assertSame($months, $quote['completed_months'], $start.' / '.$end);
            $this->assertSame($remainder, $quote['remainder_days'], $start.' / '.$end);
        }
        $calendar = $calculator->quote($data['prices'], $data['calculation_settings'], RentalPeriod::fromStrings('2027-01-31', '2027-03-03'));
        $preview = $calculator->quote($data['prices'], $data['calculation_settings'], days: 31, season: 'high');
        $this->assertNotSame($calendar['total'], $preview['total']);
    }

    public function test_majority_season_tie_cheaper_month_and_return_excluded(): void
    {
        $data = $this->data();
        $calculator = app(PricingCalculator::class);
        $quote = $calculator->quote($data['prices'], $data['calculation_settings'], RentalPeriod::fromStrings('2027-03-30', '2027-04-03'));
        $this->assertSame(['high' => 2, 'middle' => 2, 'low' => 0], $quote['season_days']);
        $this->assertSame('middle', $quote['season']);
        $this->assertSame(['middle', 'high'], $quote['tied_seasons']);
        $one = $calculator->quote($data['prices'], $data['calculation_settings'], RentalPeriod::fromStrings('2027-03-31', '2027-04-01'));
        $this->assertSame('high', $one['season']);
    }

    public function test_delayed_mode_overrides_month_rates_and_exact_anchors(): void
    {
        $data = $this->data();
        $settings = $data['calculation_settings'];
        $settings['rules'] = ['step' => 50, 'mode' => 'delayed', 'holdDays' => 2, 'cap' => false, 'monthly' => false, 'monthlyStart' => 28, 'avg' => false];
        $calculator = app(PricingCalculator::class);
        $this->assertSame(700, $calculator->daily(2, $data['prices']['middle'], $settings, 'middle'));
        $this->assertSame(1050, $calculator->daily(3, $data['prices']['middle'], $settings, 'middle'));
        $this->assertSame(2200, $calculator->daily(7, $data['prices']['middle'], $settings, 'middle'));
        $settings['dayOverrides'] = ['high:30' => 7100, 'high:31' => 7200, 'high:2' => 0, 'high:60' => 9999];
        $this->assertSame(7100, $calculator->quote($data['prices'], $settings, RentalPeriod::fromStrings('2027-02-15', '2027-03-15'))['total']);
        $this->assertSame(7200, $calculator->quote($data['prices'], $settings, days: 33, season: 'high')['total']);
        $this->assertSame(9999, $calculator->quote($data['prices'], $settings, days: 60, season: 'high')['total']);
    }

    public function test_bot_and_admin_use_same_calculator_path_and_detach_preserves_settings(): void
    {
        $preset = $this->preset();
        $vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
        $service = app(PricingPresetService::class);
        $service->attach($vehicle, $preset, 1, null, 1, 'en');
        $period = RentalPeriod::fromStrings('2027-04-01', '2027-04-28');
        $before = app(PricingService::class)->quote($vehicle->refresh(), $period);
        $this->assertSame(6150, $before->finalTotal);
        $this->assertSame('calendar', $before->toArray()['calculation']['mode']);
        $service->detach($vehicle, 1, $preset->id, 2, 'en');
        $after = app(PricingService::class)->quote($vehicle->refresh(), $period);
        $this->assertSame($before->finalTotal, $after->finalTotal);
        $this->assertNotNull($vehicle->calculation_settings);
        $settings = $vehicle->calculation_settings;
        $this->actingAs($this->admin)->patchJson(route('vehicles.pricing.update', $vehicle), [
            'prices' => $this->data()['prices'],
            'enabled' => array_fill_keys(PricingCalculator::SEASONS, array_fill_keys(PricingCalculator::TIERS, true)),
            'expected_vehicle_pricing_revision' => 3,
        ])->assertOk();
        $this->assertSame($settings, $vehicle->refresh()->calculation_settings);
        $this->assertSame(370, (int) $vehicle->priceTiers()->where('tier_key', '1d')->whereHas('season', fn ($query) => $query->where('key', 'high'))->value('package_total'));
        $this->assertSame(6150, app(PricingService::class)->quote($vehicle, $period)->finalTotal);
        $this->actingAs($this->admin)->getJson(route('pricing-presets.calculate', $preset).'?starts_on=2027-04-01&ends_on=2027-04-28')->assertOk()->assertJsonPath('data.total', 6150);
    }

    public function test_import_requires_explicit_mapping_creates_no_demo_vehicles_and_rejects_replay(): void
    {
        $vehicle = Vehicle::factory()->create();
        $preview = $this->actingAs($this->admin)->postJson(route('pricing-presets.import-preview'), ['payload' => $this->payload()])->assertOk();
        $this->assertDatabaseCount('pricing_presets', 0);
        $this->assertDatabaseCount('vehicles', 1);
        $mapping = array_fill_keys(array_keys($this->payload()['fleetAssignments']), null);
        $mapping['LIST-1'] = $vehicle->id;
        $targets = array_fill_keys(array_column($this->payload()['presets'], 'group'), null);
        $request = ['token' => $preview->json('token'), 'confirmed' => true, 'mapping' => $mapping, 'preset_targets' => $targets];
        $this->postJson(route('pricing-presets.import-commit'), [...$request, 'mapping' => []])->assertUnprocessable();
        $saved = $this->postJson(route('pricing-presets.import-commit'), $request)->assertOk()->assertJsonPath('assigned', 1)->assertJsonPath('skipped', 32);
        $this->assertSame(1, $saved->json('data.0.linked_vehicles_count'));
        $this->assertSame([$vehicle->id], $saved->json('data.0.linked_vehicle_ids'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'vehicle.pricing_preset_imported']);
        $this->assertDatabaseCount('pricing_presets', 20);
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertNotNull($vehicle->refresh()->calculation_settings);
        $this->postJson(route('pricing-presets.import-commit'), $request)->assertUnprocessable();
    }

    public function test_stale_vehicle_import_is_atomic_and_unknown_or_duplicate_targets_fail(): void
    {
        $vehicle = Vehicle::factory()->create();
        $payload = $this->payload();
        $preview = $this->actingAs($this->admin)->postJson(route('pricing-presets.import-preview'), ['payload' => $payload])->assertOk();
        $mapping = array_fill_keys(array_keys($payload['fleetAssignments']), null);
        $mapping['LIST-1'] = $vehicle->id;
        $request = ['token' => $preview->json('token'), 'confirmed' => true, 'mapping' => $mapping, 'preset_targets' => array_fill_keys(array_column($payload['presets'], 'group'), null)];
        $vehicle->forceFill(['pricing_version' => 2])->save();
        $this->postJson(route('pricing-presets.import-commit'), $request)->assertConflict();
        $this->assertDatabaseCount('pricing_presets', 0);
        $mapping['LIST-2'] = $vehicle->id;
        $this->postJson(route('pricing-presets.import-commit'), [...$request, 'mapping' => $mapping])->assertUnprocessable();
        $mapping['LIST-1'] = 999999;
        $mapping['LIST-2'] = null;
        $this->postJson(route('pricing-presets.import-commit'), [...$request, 'mapping' => $mapping])->assertUnprocessable();
    }

    public function test_malformed_settings_and_payloads_fail_validation_not_server_error(): void
    {
        $this->actingAs($this->admin);
        foreach ([['format' => 'bad'], [...$this->payload(), 'presets' => [['group' => 'x', 'data' => [], 'prefs' => 'bad']]], [...$this->payload(), 'fleetAssignments' => ['x' => 'missing']]] as $payload) {
            $this->postJson(route('pricing-presets.import-preview'), ['payload' => $payload])->assertUnprocessable();
        }
        foreach ([['seasonCalendar' => array_fill(0, 12, [])], ['dayOverrides' => ['high:1:unexpected' => 123]], ['dayOverrides' => ['high:1' => 123, 'Высокий сезон:1' => 456]]] as $invalid) {
            $payload = $this->payload();
            if (isset($invalid['seasonCalendar'])) {
                $payload['presets'][0]['prefs']['seasonCalendar'] = $invalid['seasonCalendar'];
            } else {
                $payload['presets'][0]['dayOverrides'] = $invalid['dayOverrides'];
            }
            $this->postJson(route('pricing-presets.import-preview'), ['payload' => $payload])->assertUnprocessable();
        }
        $data = $this->data();
        foreach ([['rules' => ['mode' => 'bad']], ['dayOverrides' => ['high:366' => 1]], ['prefs' => ['seasonCalendar' => ['high']]], ['rules' => ['step' => 0]]] as $patch) {
            $settings = array_replace_recursive($data['calculation_settings'], $patch);
            if (isset($patch['prefs']['seasonCalendar'])) {
                $settings['prefs']['seasonCalendar'] = ['high'];
            }
            $this->postJson(route('pricing-presets.store'), [...$data, 'name' => 'Invalid settings', 'calculation_settings' => $settings])->assertUnprocessable();
        }
    }

    public function test_bulk_save_and_assignment_conflicts_roll_back_all_changes(): void
    {
        $preset = $this->preset();
        $vehicles = Vehicle::factory()->count(2)->create();
        $this->actingAs($this->admin)->postJson(route('pricing-presets.assignments'), ['confirmed' => true, 'preset_id' => $preset->id, 'preset_version' => 1, 'vehicles' => [
            ['id' => $vehicles[0]->id, 'expected_preset_id' => null, 'expected_revision' => 1],
            ['id' => $vehicles[1]->id, 'expected_preset_id' => null, 'expected_revision' => 99],
        ]])->assertConflict();
        $this->assertNull($vehicles[0]->refresh()->pricing_preset_id);
        $this->patchJson(route('pricing-presets.bulk'), ['confirmed' => true, 'presets' => [[...$this->data(), 'id' => $preset->id, 'version' => 99, 'expected_linked_vehicles_count' => 0]]])->assertConflict();
        $this->assertSame(1, $preset->refresh()->version);
    }

    public function test_copy_keeps_every_rule_override_and_explicit_calendar(): void
    {
        $data = $this->data();
        $data['calculation_settings']['dayOverrides'] = ['middle:365' => 123456];
        $preset = app(PricingPresetService::class)->create($data, $this->admin->id);
        $copy = app(PricingPresetService::class)->copy($preset, 'Copy all rules', $this->admin->id, 1);
        $this->assertSame($preset->calculation_settings, $copy->calculation_settings);
    }

    public function test_tools_are_authenticated_and_invalid_dates_rejected(): void
    {
        $preset = $this->preset();
        $this->getJson(route('pricing-presets.fleet'))->assertUnauthorized();
        $this->getJson(route('pricing-presets.export'))->assertUnauthorized();
        $this->postJson(route('pricing-presets.import-preview'), ['payload' => $this->payload()])->assertUnauthorized();
        $this->actingAs($this->admin)->getJson(route('pricing-presets.calculate', $preset).'?starts_on=2027-02-30&ends_on=2027-03-05')->assertUnprocessable();
        $this->getJson(route('pricing-presets.calculate', $preset).'?starts_on=2027-04-01&ends_on=2027-04-01')->assertUnprocessable();
    }

    public function test_native_export_reimports_without_changing_legacy_mode(): void
    {
        $preset = $this->preset();
        $legacy = app(PricingPresetService::class)->create([...$this->data(), 'name' => 'Legacy', 'calculation_settings' => null], $this->admin->id);
        $response = $this->actingAs($this->admin)->get(route('pricing-presets.export'))->assertOk();
        $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $preview = app(PricingSettingsImport::class)->preview($payload);
        $this->assertNull($preview['presets'][$legacy->name]['calculation_settings']);
        $this->assertSame($preset->calculation_settings, $preview['presets'][$preset->name]['calculation_settings']);
    }

    public function test_server_recomputes_linked_discounts_and_keeps_existing_snapshot_unchanged(): void
    {
        $preset = $this->preset();
        $vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
        app(PricingPresetService::class)->attach($vehicle, $preset, 1, null, 1, 'en');
        $period = RentalPeriod::fromStrings('2027-04-01', '2027-04-02');
        $booking = Booking::factory()->create(['vehicle_id' => $vehicle->id, 'starts_on' => '2027-04-01', 'ends_on' => '2027-04-02']);
        $quote = app(PricingService::class)->quote($vehicle->refresh(), $period);
        $snapshot = app(BookingPriceSnapshotService::class)->createAutomatic($booking, $quote);
        $this->assertSame(350, (int) $snapshot->final_total);
        $data = $this->data();
        $data['prices']['high']['1d'] = 1000;
        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [...$data, 'version' => 1, 'expected_linked_vehicles_count' => 1])->assertOk()->assertJsonPath('data.prices.middle.1d', 950)->assertJsonPath('data.prices.low.1d', 890);
        $this->assertSame(950, app(PricingService::class)->quote($vehicle->refresh(), $period)->finalTotal);
        $this->assertSame(350, (int) $snapshot->refresh()->final_total);
        $this->assertSame(1, $booking->priceSnapshots()->count());
    }

    public function test_stale_imported_preset_and_changed_source_assignment_return_conflicts(): void
    {
        $preset = $this->preset();
        $other = app(PricingPresetService::class)->copy($preset, 'Another', $this->admin->id, 1);
        $vehicle = Vehicle::factory()->create();
        app(PricingPresetService::class)->attach($vehicle, $preset, 1, null, 1, 'en');
        $payload = ['format' => 'price-calculator-settings', 'version' => 1, 'presets' => [$this->payload()['presets'][0]], 'fleetAssignments' => ['TEMP-EXPLICIT' => 'Scoopy - 2026']];
        $preview = $this->actingAs($this->admin)->postJson(route('pricing-presets.import-preview'), ['payload' => $payload])->assertOk();
        app(PricingPresetService::class)->attach($vehicle->refresh(), $other, 1, $preset->id, 2, 'en');
        $request = ['token' => $preview->json('token'), 'confirmed' => true, 'preset_targets' => ['Scoopy - 2026' => null], 'mapping' => ['TEMP-EXPLICIT' => $vehicle->id]];
        $this->postJson(route('pricing-presets.import-commit'), $request)->assertConflict()->assertJsonPath('error', 'vehicle_pricing_conflict');
        $this->assertDatabaseCount('pricing_presets', 2);
        $preview = $this->postJson(route('pricing-presets.import-preview'), ['payload' => $payload])->assertOk();
        $preset->forceFill(['version' => 2])->save();
        $this->postJson(route('pricing-presets.import-commit'), ['token' => $preview->json('token'), 'confirmed' => true, 'preset_targets' => ['Scoopy - 2026' => $preset->id], 'mapping' => ['TEMP-EXPLICIT' => null]])->assertConflict();
    }

    public function test_row_order_and_delete_require_fresh_versions_and_linked_presets_are_retained(): void
    {
        $one = $this->preset();
        $two = app(PricingPresetService::class)->copy($one, 'Second', $this->admin->id, 1);
        $this->actingAs($this->admin)->postJson(route('pricing-presets.reorder'), ['confirmed' => true, 'presets' => [['id' => $two->id, 'version' => 1], ['id' => $one->id, 'version' => 1]]])->assertOk();
        $this->getJson(route('pricing-presets.index'))->assertJsonPath('data.0.id', $two->id);
        $this->deleteJson(route('pricing-presets.destroy', $one), ['confirmed' => true, 'version' => 1])->assertConflict();
        $vehicle = Vehicle::factory()->create();
        app(PricingPresetService::class)->attach($vehicle, $one->refresh(), 2, null, 1, 'en');
        $this->deleteJson(route('pricing-presets.destroy', $one), ['confirmed' => true, 'version' => 2])->assertUnprocessable();
        $this->deleteJson(route('pricing-presets.destroy', $two), ['confirmed' => true, 'version' => 2])->assertOk();
        $this->assertDatabaseCount('pricing_presets', 1);
    }
}
