<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Imports\Data\ImportedPrice;
use App\Domain\Pricing\Imports\Data\ImportedVehicle;
use App\Domain\Pricing\Imports\Data\PricingWorkbookData;
use App\Domain\Pricing\Imports\PricingWorkbookImporter;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Models\BookingPriceSnapshot;
use App\Models\Customer;
use App\Models\PricingPreset;
use App\Models\ServiceApiClient;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PricingPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->admin = User::factory()->create();
    }

    public function test_routes_require_an_authenticated_active_administrator(): void
    {
        $payload = $this->presetPayload('Protected preset');

        $this->getJson(route('pricing-presets.index'))->assertUnauthorized();
        $this->postJson(route('pricing-presets.store'), $payload)->assertUnauthorized();

        $inactive = User::factory()->create(['is_active' => false]);
        $this->actingAs($inactive)
            ->getJson(route('pricing-presets.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->admin)
            ->getJson(route('pricing-presets.index'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_create_persists_all_exact_integer_prices_and_bot_quote_keeps_hundred_baht_rounding(): void
    {
        $prices = $this->matrix(1000);
        $prices['low']['1d'] = 230;
        $prices['high']['1d'] = 330;
        $preset = $this->createPreset('Exact odd prices', $prices);

        $this->assertDatabaseCount('pricing_preset_prices', 15);
        $this->assertDatabaseHas('pricing_preset_prices', [
            'pricing_preset_id' => $preset->id,
            'package_total' => 230,
        ]);
        $this->assertDatabaseHas('pricing_preset_prices', [
            'pricing_preset_id' => $preset->id,
            'package_total' => 330,
        ]);

        $vehicle = Vehicle::factory()->create([
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
        $this->attach($vehicle, $preset);
        $this->assertVehicleMatrix($vehicle, $prices);

        $token = 'pricing-preset-quote-token';
        ServiceApiClient::query()->create([
            'name' => 'Pricing preset quote test',
            'token_hash' => hash('sha256', $token),
            'abilities' => ['bot:read'],
            'is_active' => true,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'X-Request-ID' => 'pricing-preset-low-quote',
        ])->postJson('/api/v1/bot/quotes', [
            'vehicle_id' => $vehicle->id,
            'starts_on' => '2027-06-10',
            'ends_on' => '2027-06-10',
        ])->assertOk()
            ->assertJsonPath('data.calculated_total', '230.000000')
            ->assertJsonPath('data.rounded_total', 200)
            ->assertJsonPath('data.final_total', 200);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'X-Request-ID' => 'pricing-preset-high-quote',
        ])->postJson('/api/v1/bot/quotes', [
            'vehicle_id' => $vehicle->id,
            'starts_on' => '2026-12-10',
            'ends_on' => '2026-12-10',
        ])->assertOk()
            ->assertJsonPath('data.calculated_total', '330.000000')
            ->assertJsonPath('data.rounded_total', 300)
            ->assertJsonPath('data.final_total', 300);
    }

    public function test_admin_can_create_copy_list_and_update_a_preset(): void
    {
        $originalPrices = $this->matrix(200);
        $original = $this->createPreset('City scooters', $originalPrices);

        $copyResponse = $this->actingAs($this->admin)->postJson(
            route('pricing-presets.copy', $original),
            ['name' => 'City scooters copy', 'source_version' => 1],
        )->assertCreated()
            ->assertJsonPath('data.name', 'City scooters copy')
            ->assertJsonPath('data.vehicle_type', 'scooter')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.linked_vehicles_count', 0)
            ->assertJsonPath('data.prices.low.1d', $originalPrices['low']['1d']);

        $copy = PricingPreset::query()->findOrFail((int) $copyResponse->json('data.id'));
        $updatedPrices = $this->matrix(700);
        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $original), [
            'name' => 'City scooters updated',
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 0,
            'prices' => $updatedPrices,
        ])->assertOk()
            ->assertJsonPath('data.name', 'City scooters updated')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.prices.high.month', $updatedPrices['high']['month']);

        $this->actingAs($this->admin)->getJson(route('pricing-presets.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame($originalPrices, $this->presetMatrix($copy));
        $this->assertSame($updatedPrices, $this->presetMatrix($original));
    }

    public function test_stale_source_copy_creates_nothing_and_current_source_copy_is_exact(): void
    {
        $source = $this->createPreset('Copy race source', $this->matrix(310));
        $current = $this->matrix(910);
        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $source), [
            'name' => $source->name,
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 0,
            'prices' => $current,
        ])->assertOk();

        $this->actingAs($this->admin)->postJson(route('pricing-presets.copy', $source), [
            'name' => 'Stale source copy',
            'source_version' => 1,
        ])->assertConflict()
            ->assertJsonPath('error', 'pricing_preset_version_conflict')
            ->assertJsonPath('current_version', 2);

        $this->assertDatabaseCount('pricing_presets', 1);
        $this->assertDatabaseCount('pricing_preset_prices', 15);
        $this->assertDatabaseMissing('pricing_presets', ['name' => 'Stale source copy']);

        $response = $this->actingAs($this->admin)->postJson(route('pricing-presets.copy', $source), [
            'name' => 'Current source copy',
            'source_version' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.prices', $current);

        $copy = PricingPreset::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame($current, $this->presetMatrix($copy));
        $this->assertSame(2, $source->fresh()->version);
    }

    public function test_updating_a_preset_materializes_two_linked_vehicles_without_touching_an_unlinked_vehicle(): void
    {
        $preset = $this->createPreset('Shared fleet prices', $this->matrix(300));
        $first = Vehicle::factory()->create();
        $second = Vehicle::factory()->create();
        $unlinked = Vehicle::factory()->create();
        $unlinkedPrices = $this->matrix(1500);

        $this->attach($first, $preset);
        $this->attach($second, $preset);
        $this->writeVehicleMatrix($unlinked, $unlinkedPrices);

        $updatedPrices = $this->matrix(801);
        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [
            'name' => $preset->name,
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 2,
            'prices' => $updatedPrices,
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.linked_vehicles_count', 2);

        $this->assertVehicleMatrix($first, $updatedPrices);
        $this->assertVehicleMatrix($second, $updatedPrices);
        $this->assertVehicleMatrix($unlinked, $unlinkedPrices);
        $this->assertNull($unlinked->fresh()->pricing_preset_id);
    }

    public function test_assign_switch_and_detach_preserve_exact_materialized_values(): void
    {
        $firstPrices = $this->matrix(230);
        $secondPrices = $this->matrix(330);
        $firstPreset = $this->createPreset('First prices', $firstPrices);
        $secondPreset = $this->createPreset('Second prices', $secondPrices);
        $vehicle = Vehicle::factory()->create();

        $this->attach($vehicle, $firstPreset);
        $this->assertSame($firstPreset->id, $vehicle->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($vehicle, $firstPrices);

        $this->attach($vehicle, $secondPreset);
        $this->assertSame($secondPreset->id, $vehicle->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($vehicle, $secondPrices);

        $vehicle->refresh();
        $this->actingAs($this->admin)->delete(route('vehicles.pricing-preset.detach', $vehicle), [
            'preset_version' => $secondPreset->fresh()->version,
            'expected_pricing_preset_id' => $secondPreset->id,
            'expected_vehicle_pricing_revision' => $vehicle->pricing_version,
        ])->assertRedirect(route('vehicles.edit', $vehicle));

        $this->assertNull($vehicle->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($vehicle, $secondPrices);
    }

    public function test_stale_preset_version_cannot_overwrite_prices_or_be_assigned(): void
    {
        $originalPrices = $this->matrix(400);
        $currentPrices = $this->matrix(900);
        $preset = $this->createPreset('Versioned prices', $originalPrices);

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [
            'name' => $preset->name,
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 0,
            'prices' => $currentPrices,
        ])->assertOk();

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [
            'name' => 'Stale overwrite',
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 0,
            'prices' => $this->matrix(1300),
        ])->assertConflict()
            ->assertJsonPath('error', 'pricing_preset_version_conflict')
            ->assertJsonPath('current_version', 2);

        $this->assertSame('Versioned prices', $preset->fresh()->name);
        $this->assertSame($currentPrices, $this->presetMatrix($preset));

        $vehicle = Vehicle::factory()->create();
        $vehicle->refresh();
        $this->actingAs($this->admin)->putJson(route('vehicles.pricing-preset.attach', $vehicle), [
            'preset_id' => $preset->id,
            'preset_version' => 1,
            'expected_pricing_preset_id' => null,
            'expected_vehicle_pricing_revision' => $vehicle->pricing_version,
        ])->assertConflict()
            ->assertJsonPath('error', 'pricing_preset_version_conflict')
            ->assertJsonPath('current_version', 2);

        $this->assertNull($vehicle->fresh()->pricing_preset_id);
        $this->assertDatabaseMissing('vehicle_price_tiers', ['vehicle_id' => $vehicle->id]);
    }

    public function test_stale_assignment_and_link_confirmation_count_do_not_overwrite_anything(): void
    {
        $firstPreset = $this->createPreset('Assignment A', $this->matrix(500));
        $secondPreset = $this->createPreset('Assignment B', $this->matrix(800));
        $vehicle = Vehicle::factory()->create();
        $this->attach($vehicle, $firstPreset);
        $attachedRevision = $vehicle->fresh()->pricing_version;

        $this->actingAs($this->admin)->putJson(route('vehicles.pricing-preset.attach', $vehicle), [
            'preset_id' => $secondPreset->id,
            'preset_version' => 1,
            'expected_pricing_preset_id' => null,
            'expected_vehicle_pricing_revision' => $attachedRevision - 1,
        ])->assertConflict()
            ->assertJsonPath('error', 'vehicle_pricing_conflict');

        $this->assertSame($firstPreset->id, $vehicle->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($vehicle, $this->matrix(500));

        $otherVehicle = Vehicle::factory()->create();
        $this->attach($otherVehicle, $firstPreset);
        $before = $this->presetMatrix($firstPreset);

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $firstPreset), [
            'name' => $firstPreset->name,
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 1,
            'prices' => $this->matrix(1700),
        ])->assertConflict()
            ->assertJsonPath('error', 'pricing_preset_links_conflict')
            ->assertJsonPath('current_linked_vehicles_count', 2);

        $this->assertSame(1, $firstPreset->fresh()->version);
        $this->assertSame($before, $this->presetMatrix($firstPreset));
        $this->assertVehicleMatrix($vehicle, $before);
        $this->assertVehicleMatrix($otherVehicle, $before);
    }

    public function test_inertia_attach_and_detach_conflicts_redirect_back_with_inline_errors(): void
    {
        $firstPreset = $this->createPreset('Inline conflict A', $this->matrix(500));
        $secondPreset = $this->createPreset('Inline conflict B', $this->matrix(800));
        $vehicle = Vehicle::factory()->create();
        $this->attach($vehicle, $firstPreset);
        $editUrl = route('vehicles.edit', $vehicle);

        $this->actingAs($this->admin)
            ->from($editUrl)
            ->put(route('vehicles.pricing-preset.attach', $vehicle), [
                'preset_id' => $secondPreset->id,
                'preset_version' => 1,
                'expected_pricing_preset_id' => null,
                'expected_vehicle_pricing_revision' => 1,
            ])->assertRedirect($editUrl)
            ->assertSessionHasErrors('preset_id');

        $this->assertSame($firstPreset->id, $vehicle->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($vehicle, $this->matrix(500));

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $firstPreset), [
            'name' => $firstPreset->name,
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 1,
            'prices' => $this->matrix(900),
        ])->assertOk();
        $vehicle->refresh();

        $this->actingAs($this->admin)
            ->from($editUrl)
            ->delete(route('vehicles.pricing-preset.detach', $vehicle), [
                'preset_version' => 1,
                'expected_pricing_preset_id' => $firstPreset->id,
                'expected_vehicle_pricing_revision' => $vehicle->pricing_version,
            ])->assertRedirect($editUrl)
            ->assertSessionHasErrors('preset_version');

        $this->assertSame($firstPreset->id, $vehicle->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($vehicle, $this->matrix(900));
    }

    public function test_linked_vehicle_and_preset_type_changes_reject_incompatible_assignments_atomically(): void
    {
        $scooterPreset = $this->createPreset('Scooter-only', $this->matrix(600));
        $scooter = Vehicle::factory()->create(['type' => 'scooter']);
        $this->attach($scooter, $scooterPreset);

        $this->actingAs($this->admin)->patch(route('vehicles.update', $scooter), [
            ...$this->vehiclePayload($scooter),
            'type' => 'car',
        ])->assertSessionHasErrors('type');

        $this->assertSame('scooter', $scooter->fresh()->getRawOriginal('type'));
        $this->assertSame($scooterPreset->id, $scooter->fresh()->pricing_preset_id);
        $this->assertVehicleMatrix($scooter, $this->matrix(600));

        $neutral = $this->createPreset('Neutral type', $this->matrix(1000), null);
        $car = Vehicle::factory()->create(['type' => 'car']);
        $neutralScooter = Vehicle::factory()->create(['type' => 'scooter']);
        $this->attach($neutralScooter, $neutral);
        $this->attach($car, $neutral);
        $before = $this->presetMatrix($neutral);

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $neutral), [
            'name' => 'Must roll back type and prices',
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 2,
            'prices' => $this->matrix(1400),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vehicle_type');

        $neutral->refresh();
        $this->assertSame('Neutral type', $neutral->name);
        $this->assertNull($neutral->vehicle_type);
        $this->assertSame(1, $neutral->version);
        $this->assertSame($before, $this->presetMatrix($neutral));
        $this->assertVehicleMatrix($neutralScooter, $before);
        $this->assertVehicleMatrix($car, $before);
    }

    public function test_stale_revision_rejects_individual_pricing_without_overwriting_matrix(): void
    {
        $vehicle = Vehicle::factory()->create();
        $first = $this->multipleOfFiftyMatrix(1000);

        $this->actingAs($this->admin)->patch(route('vehicles.pricing.update', $vehicle), [
            'prices' => $first,
            'enabled' => $this->enabledMatrix(),
            'expected_vehicle_pricing_revision' => 1,
        ])->assertRedirect(route('vehicles.edit', $vehicle));

        $vehicle->refresh();
        $this->assertSame(2, $vehicle->pricing_version);
        $this->assertVehicleMatrix($vehicle, $first);

        $this->actingAs($this->admin)->patchJson(route('vehicles.pricing.update', $vehicle), [
            'prices' => $this->multipleOfFiftyMatrix(3000),
            'enabled' => $this->enabledMatrix(),
            'expected_vehicle_pricing_revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('expected_vehicle_pricing_revision');

        $this->assertSame(2, $vehicle->fresh()->pricing_version);
        $this->assertVehicleMatrix($vehicle, $first);
    }

    public function test_failed_preset_update_audit_rolls_back_source_and_every_linked_vehicle(): void
    {
        $before = $this->matrix(650);
        $preset = $this->createPreset('Audit rollback', $before);
        $first = Vehicle::factory()->create();
        $second = Vehicle::factory()->create();
        $this->attach($first, $preset);
        $this->attach($second, $preset);
        $firstRevision = $first->fresh()->pricing_version;
        $secondRevision = $second->fresh()->pricing_version;

        $this->mock(AdminAuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        });

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [
            'name' => 'Partially written name',
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 2,
            'prices' => $this->matrix(1800),
        ])->assertServerError();

        $preset->refresh();
        $this->assertSame('Audit rollback', $preset->name);
        $this->assertSame(1, $preset->version);
        $this->assertSame($before, $this->presetMatrix($preset));
        $this->assertSame($firstRevision, $first->fresh()->pricing_version);
        $this->assertSame($secondRevision, $second->fresh()->pricing_version);
        $this->assertVehicleMatrix($first, $before);
        $this->assertVehicleMatrix($second, $before);
        $this->assertDatabaseMissing('audit_logs', [
            'subject_type' => PricingPreset::class,
            'subject_id' => (string) $preset->id,
            'action' => 'pricing_preset.updated',
        ]);
    }

    public function test_failed_attach_audit_restores_the_previous_individual_matrix_and_revision(): void
    {
        $preset = $this->createPreset('Attach audit rollback', $this->matrix(1600));
        $vehicle = Vehicle::factory()->create();
        $individual = $this->matrix(740);
        $this->writeVehicleMatrix($vehicle, $individual);
        $vehicle->refresh();
        $beforeRevision = $vehicle->pricing_version;

        $this->mock(AdminAuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        });

        $this->actingAs($this->admin)->putJson(route('vehicles.pricing-preset.attach', $vehicle), [
            'preset_id' => $preset->id,
            'preset_version' => 1,
            'expected_pricing_preset_id' => null,
            'expected_vehicle_pricing_revision' => $beforeRevision,
        ])->assertServerError();

        $vehicle->refresh();
        $this->assertNull($vehicle->pricing_preset_id);
        $this->assertSame($beforeRevision, $vehicle->pricing_version);
        $this->assertVehicleMatrix($vehicle, $individual);
        $this->assertDatabaseMissing('audit_logs', [
            'subject_type' => Vehicle::class,
            'subject_id' => (string) $vehicle->id,
            'action' => 'vehicle.pricing_preset_attached',
        ]);
    }

    public function test_incomplete_or_invalid_matrix_is_rejected_and_update_rolls_back(): void
    {
        $incomplete = $this->presetPayload('Incomplete');
        unset($incomplete['prices']['low']['month']);

        $this->actingAs($this->admin)->postJson(route('pricing-presets.store'), $incomplete)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prices.low.month');
        $this->assertDatabaseCount('pricing_presets', 0);
        $this->assertDatabaseCount('pricing_preset_prices', 0);

        $preset = $this->createPreset('Rollback target', $this->matrix(600));
        $before = $this->presetMatrix($preset);
        $vehicle = Vehicle::factory()->create();
        $this->attach($vehicle, $preset);
        $invalid = $this->matrix(1100);
        $invalid['high']['1d'] = 0;
        $invalid['middle']['7d'] = 230.5;

        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [
            'name' => 'Must not persist',
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 1,
            'prices' => $invalid,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['prices.high.1d', 'prices.middle.7d']);

        $this->assertSame('Rollback target', $preset->fresh()->name);
        $this->assertSame(1, $preset->version);
        $this->assertSame($before, $this->presetMatrix($preset));
        $this->assertVehicleMatrix($vehicle, $before);
        $this->assertDatabaseCount('pricing_preset_prices', 15);
    }

    public function test_linked_vehicle_rejects_individual_pricing_write_and_workbook_import(): void
    {
        $prices = $this->matrix(700);
        $preset = $this->createPreset('Protected source', $prices);
        $vehicle = Vehicle::factory()->create();
        $this->attach($vehicle, $preset);

        $manual = $this->multipleOfFiftyMatrix(2000);
        $this->actingAs($this->admin)->patchJson(route('vehicles.pricing.update', $vehicle), [
            'prices' => $manual,
            'enabled' => $this->enabledMatrix(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('prices');
        $this->assertVehicleMatrix($vehicle, $prices);

        $importedPrices = [];
        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $importedPrices[] = new ImportedPrice($season, $tier, 3000, 5);
            }
        }
        $workbook = new PricingWorkbookData(
            sourcePath: 'memory://linked.xlsx',
            sourceFilename: 'linked.xlsx',
            sourceSha256: str_repeat('a', 64),
            vehicles: [new ImportedVehicle(
                externalCode: $vehicle->external_code,
                type: VehicleType::Scooter,
                categoryCode: 'linked-import',
                categoryName: 'Linked import',
                name: 'Imported overwrite',
                inventoryCode: null,
                year: 2026,
                isActive: true,
                hasExplicitActiveMarker: true,
                sourceSheet: 'LIST BIKES',
                sourceRow: 2,
                prices: $importedPrices,
            )],
            warnings: [],
        );

        try {
            app(PricingWorkbookImporter::class)->import($workbook, $this->admin);
            $this->fail('A workbook overwrote pricing owned by a shared preset.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preset', strtolower($exception->getMessage()));
        }

        $this->assertSame($preset->id, $vehicle->fresh()->pricing_preset_id);
        $this->assertSame($vehicle->name, $vehicle->fresh()->name);
        $this->assertVehicleMatrix($vehicle, $prices);
    }

    public function test_existing_automatic_snapshot_and_manual_override_are_unchanged_after_preset_update(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00', 'Asia/Bangkok'));
        $prices = $this->matrix(1000);
        $prices['low']['1d'] = 230;
        $preset = $this->createPreset('Booking snapshot source', $prices);
        $vehicle = Vehicle::factory()->create([
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
        $this->attach($vehicle, $preset);
        $customer = Customer::factory()->create();

        $booking = app(BookingService::class)->create(
            new CreateBookingData(
                customerId: $customer->id,
                vehicleId: $vehicle->id,
                startsOn: '2026-08-10',
                endsOn: '2026-08-10',
                source: BookingSource::AdminManual,
            ),
            BookingActor::admin($this->admin->id),
        );
        app(BookingPriceSnapshotService::class)->override(
            $booking,
            275,
            'Agreed customer price',
            $this->admin,
        );

        $updated = $prices;
        $updated['low']['1d'] = 330;
        $this->actingAs($this->admin)->patchJson(route('pricing-presets.update', $preset), [
            'name' => $preset->name,
            'vehicle_type' => 'scooter',
            'version' => 1,
            'expected_linked_vehicles_count' => 1,
            'prices' => $updated,
        ])->assertOk();

        $snapshots = BookingPriceSnapshot::query()
            ->where('booking_id', $booking->id)
            ->orderBy('version')
            ->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame('230.000000', (string) $snapshots[0]->calculated_total);
        $this->assertSame(200, (int) $snapshots[0]->rounded_total);
        $this->assertSame(200, (int) $snapshots[0]->final_total);
        $this->assertSame('230.000000', (string) $snapshots[1]->calculated_total);
        $this->assertSame(200, (int) $snapshots[1]->rounded_total);
        $this->assertSame(275, (int) $snapshots[1]->manual_total);
        $this->assertSame(275, (int) $snapshots[1]->final_total);
        $this->assertSame(330, (int) VehiclePriceTier::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('tier_key', '1d')
            ->whereHas('season', fn ($query) => $query->where('key', 'low'))
            ->value('package_total'));
    }

    public function test_vehicle_edit_exposes_current_preset_revision_and_available_presets(): void
    {
        $preset = $this->createPreset('Edit page preset', $this->matrix(450));
        $vehicle = Vehicle::factory()->create();
        $this->attach($vehicle, $preset);
        $vehicle->refresh();

        $this->actingAs($this->admin)->get(route('vehicles.edit', $vehicle))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vehicles/Edit')
                ->where('vehicle.pricing_preset.id', $preset->id)
                ->where('vehicle.pricing_preset.name', 'Edit page preset')
                ->where('vehicle.pricing_preset.version', 1)
                ->where('vehicle.pricing_revision', $vehicle->pricing_version)
                ->has('pricing_presets', 1)
                ->where('pricing_presets.0.id', $preset->id));
    }

    /** @param array<string, array<string, int>>|null $prices */
    private function createPreset(string $name, ?array $prices = null, ?string $vehicleType = 'scooter'): PricingPreset
    {
        $response = $this->actingAs($this->admin)->postJson(route('pricing-presets.store'), [
            'name' => $name,
            'vehicle_type' => $vehicleType,
            'prices' => $prices ?? $this->matrix(),
        ])->assertCreated()
            ->assertJsonPath('data.name', $name)
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(3, 'data.prices')
            ->assertJsonCount(5, 'data.prices.low');

        return PricingPreset::query()->findOrFail((int) $response->json('data.id'));
    }

    private function attach(Vehicle $vehicle, PricingPreset $preset): void
    {
        $vehicle->refresh();
        $currentPresetId = $vehicle->pricing_preset_id;

        $this->actingAs($this->admin)->put(route('vehicles.pricing-preset.attach', $vehicle), [
            'preset_id' => $preset->id,
            'preset_version' => $preset->fresh()->version,
            'expected_pricing_preset_id' => $currentPresetId,
            'expected_vehicle_pricing_revision' => $vehicle->pricing_version,
        ])->assertRedirect(route('vehicles.edit', $vehicle));
    }

    /** @return array{name: string, vehicle_type: string, prices: array<string, array<string, int>>} */
    private function presetPayload(string $name): array
    {
        return [
            'name' => $name,
            'vehicle_type' => 'scooter',
            'prices' => $this->matrix(),
        ];
    }

    /** @return array<string, mixed> */
    private function vehiclePayload(Vehicle $vehicle): array
    {
        return [
            'external_code' => $vehicle->external_code,
            'type' => $vehicle->getRawOriginal('type'),
            'category_id' => $vehicle->category_id,
            'name' => $vehicle->name,
            'inventory_code' => $vehicle->inventory_code,
            'year' => $vehicle->year,
            'description' => $vehicle->description,
            'characteristics_text' => $vehicle->characteristics_text,
            'emoji' => $vehicle->emoji,
            'is_active' => (bool) $vehicle->is_active,
            'is_visible_for_booking' => (bool) $vehicle->is_visible_for_booking,
            'sort_order' => (int) $vehicle->sort_order,
        ];
    }

    /** @return array<string, array<string, int>> */
    private function matrix(int $base = 100): array
    {
        $prices = [];

        foreach (PricingSeasonKey::cases() as $seasonOffset => $season) {
            foreach (PricingTier::cases() as $tierOffset => $tier) {
                $prices[$season->value][$tier->value] = $base + ($seasonOffset * 101) + ($tierOffset * 17);
            }
        }

        return $prices;
    }

    /** @return array<string, array<string, bool>> */
    private function enabledMatrix(): array
    {
        $enabled = [];

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $enabled[$season->value][$tier->value] = true;
            }
        }

        return $enabled;
    }

    /** @return array<string, array<string, int>> */
    private function multipleOfFiftyMatrix(int $base): array
    {
        $prices = [];
        $offset = 0;

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $prices[$season->value][$tier->value] = $base + ($offset++ * 50);
            }
        }

        return $prices;
    }

    /** @return array<string, array<string, int>> */
    private function presetMatrix(PricingPreset $preset): array
    {
        return app(PricingPresetService::class)->matrix($preset->fresh());
    }

    /** @param array<string, array<string, int>> $prices */
    private function assertVehicleMatrix(Vehicle $vehicle, array $prices): void
    {
        $rows = VehiclePriceTier::query()
            ->where('vehicle_id', $vehicle->id)
            ->with('season')
            ->get();

        $this->assertCount(15, $rows);
        foreach ($prices as $season => $tiers) {
            foreach ($tiers as $tier => $packageTotal) {
                $row = $rows->first(fn (VehiclePriceTier $candidate): bool => $candidate->season->seasonKey()->value === $season
                    && $candidate->getRawOriginal('tier_key') === $tier);
                $this->assertNotNull($row, "Missing materialized {$season}/{$tier} price for vehicle {$vehicle->id}.");
                $this->assertTrue((bool) $row->is_active);
                $this->assertSame($packageTotal, (int) $row->package_total);
            }
        }
    }

    /** @param array<string, array<string, int>> $prices */
    private function writeVehicleMatrix(Vehicle $vehicle, array $prices): void
    {
        $payload = [
            'prices' => $prices,
            'enabled' => $this->enabledMatrix(),
        ];

        app(VehiclePricingService::class)
            ->replaceMatrix($vehicle, $payload['prices'], $payload['enabled']);
    }
}
