<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Shared\Services\AdminAuditService;
use App\Models\Category;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Models\VehiclePriceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AdminVehicleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->admin = User::factory()->create();
    }

    public function test_catalog_routes_require_admin_authentication(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->get(route('vehicles.index'))->assertRedirect(route('login'));
        $this->get(route('vehicles.create'))->assertRedirect(route('login'));
        $this->get(route('vehicles.edit', $vehicle))->assertRedirect(route('login'));
        $this->get(route('categories.index'))->assertRedirect(route('login'));
    }

    public function test_admin_manages_vehicle_lifecycle_without_deleting_history(): void
    {
        $this->actingAs($this->admin)->post(route('categories.store'), [
            'code' => 'premium-scooters',
            'name' => 'Premium scooters',
            'vehicle_type' => 'scooter',
            'description' => null,
            'sort_order' => 10,
            'is_active' => true,
        ])->assertRedirect(route('categories.index'));

        $category = Category::query()->sole();
        $response = $this->actingAs($this->admin)->post(route('vehicles.store'), [
            ...$this->vehiclePayload(),
            'category_id' => $category->id,
        ]);
        $vehicle = Vehicle::query()->sole();

        $response->assertRedirect(route('vehicles.edit', $vehicle));
        $this->assertFalse($vehicle->is_visible_for_booking);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Vehicle::class,
            'subject_id' => (string) $vehicle->id,
            'action' => 'vehicle.created',
        ]);

        $this->actingAs($this->admin)->patch(route('vehicles.update', $vehicle), [
            ...$this->vehiclePayload(),
            'category_id' => $category->id,
            'is_visible_for_booking' => true,
        ])->assertSessionHasErrors('is_visible_for_booking');

        $this->actingAs($this->admin)->patch(route('vehicles.pricing.update', $vehicle), $this->pricePayload())
            ->assertRedirect(route('vehicles.edit', $vehicle));

        $this->assertSame(15, $vehicle->priceTiers()->count());
        $sevenDay = VehiclePriceTier::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('tier_key', PricingTier::SevenDays->value)
            ->firstOrFail();
        $this->assertSame('100.000000', (string) $sevenDay->daily_rate);

        $this->actingAs($this->admin)->patch(route('vehicles.update', $vehicle), [
            ...$this->vehiclePayload(),
            'category_id' => $category->id,
            'is_visible_for_booking' => true,
        ])->assertRedirect(route('vehicles.edit', $vehicle));
        $this->assertTrue($vehicle->refresh()->is_visible_for_booking);

        $this->actingAs($this->admin)->patch(route('vehicles.update', $vehicle), [
            ...$this->vehiclePayload(),
            'category_id' => $category->id,
            'is_active' => false,
            'is_visible_for_booking' => true,
        ])->assertRedirect(route('vehicles.edit', $vehicle));

        $vehicle->refresh();
        $this->assertFalse($vehicle->is_active);
        $this->assertFalse($vehicle->is_visible_for_booking);
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_incomplete_price_update_hides_published_vehicle_and_preview_uses_saved_matrix(): void
    {
        $vehicle = Vehicle::factory()->create([
            'is_active' => true,
            'is_visible_for_booking' => false,
        ]);
        $this->actingAs($this->admin)->patch(route('vehicles.pricing.update', $vehicle), $this->pricePayload());
        $vehicle->update(['is_visible_for_booking' => true]);

        $this->actingAs($this->admin)
            ->getJson(route('vehicles.pricing.quote', [
                'vehicle' => $vehicle,
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-07',
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_days', 7)
            ->assertJsonPath('data.tier_key', '7d')
            ->assertJsonPath('data.final_total', 700);

        $payload = $this->pricePayload();
        $payload['prices']['low']['7d'] = null;
        $payload['enabled']['low']['7d'] = false;
        $this->actingAs($this->admin)->patch(route('vehicles.pricing.update', $vehicle), $payload);

        $this->assertFalse($vehicle->refresh()->is_visible_for_booking);
        $this->assertSame(14, $vehicle->priceTiers()->where('is_active', true)->count());
    }

    public function test_zero_package_total_is_rejected_as_incomplete_pricing(): void
    {
        $vehicle = Vehicle::factory()->create();
        $payload = $this->pricePayload();
        $payload['prices']['low']['7d'] = 0;

        $this->actingAs($this->admin)
            ->patch(route('vehicles.pricing.update', $vehicle), $payload)
            ->assertSessionHasErrors('prices.low.7d');

        $this->assertDatabaseCount('vehicle_price_tiers', 0);
    }

    public function test_deactivating_category_hides_assigned_vehicles(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create([
            'category_id' => $category->id,
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);

        $this->actingAs($this->admin)->patch(route('categories.update', $category), [
            'code' => $category->code,
            'name' => $category->name,
            'vehicle_type' => 'scooter',
            'description' => null,
            'sort_order' => 0,
            'is_active' => false,
        ])->assertRedirect(route('categories.index'));

        $this->assertFalse($vehicle->refresh()->is_visible_for_booking);
    }

    public function test_admin_uploads_arranges_and_deletes_vehicle_photos_with_thumbnails(): void
    {
        Storage::fake('public');
        $vehicle = Vehicle::factory()->create();

        foreach (['front.jpg', 'side.jpg'] as $filename) {
            $this->actingAs($this->admin)->post(route('vehicles.photos.store', $vehicle), [
                'photo' => UploadedFile::fake()->image($filename, 900, 600),
                'alt_text' => $filename,
            ])->assertRedirect(route('vehicles.edit', $vehicle));
        }

        $photos = $vehicle->photos()->orderBy('id')->get();
        $this->assertCount(2, $photos);
        $this->assertTrue($photos[0]->is_primary);

        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->file_path);
            Storage::disk('public')->assertExists(
                "vehicles/{$vehicle->id}/thumbnails/".pathinfo($photo->file_path, PATHINFO_FILENAME).'.webp',
            );
        }

        $this->actingAs($this->admin)->patch(route('vehicles.photos.arrange', $vehicle), [
            'ordered_ids' => [$photos[1]->id, $photos[0]->id],
            'primary_id' => $photos[1]->id,
        ])->assertRedirect(route('vehicles.edit', $vehicle));

        $this->assertDatabaseHas('vehicle_photos', [
            'id' => $photos[1]->id,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $deletedPath = (string) $photos[1]->file_path;
        $this->actingAs($this->admin)->delete(route('vehicles.photos.destroy', [
            'vehicle' => $vehicle,
            'photo' => $photos[1],
        ]))->assertRedirect(route('vehicles.edit', $vehicle));

        Storage::disk('public')->assertMissing($deletedPath);
        $this->assertTrue(VehiclePhoto::query()->sole()->is_primary);
    }

    public function test_failed_photo_audit_rolls_back_database_and_public_files(): void
    {
        Storage::fake('public');
        $vehicle = Vehicle::factory()->create();
        $this->mock(AdminAuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        });

        $this->actingAs($this->admin)->post(route('vehicles.photos.store', $vehicle), [
            'photo' => UploadedFile::fake()->image('front.jpg', 900, 600),
            'alt_text' => 'Front',
        ])->assertServerError();

        $this->assertDatabaseCount('vehicle_photos', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_fleet_index_filters_and_reports_price_completeness(): void
    {
        Vehicle::factory()->create(['name' => 'Hidden Xmax', 'is_active' => false]);
        $complete = Vehicle::factory()->create(['name' => 'Published Click']);
        $this->actingAs($this->admin)->patch(route('vehicles.pricing.update', $complete), $this->pricePayload());

        $this->actingAs($this->admin)
            ->get(route('vehicles.index', ['pricing' => 'complete']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vehicles/Index')
                ->where('vehicles.total', 1)
                ->where('vehicles.data.0.name', 'Published Click')
                ->where('summary.total', 2)
                ->where('summary.incomplete_pricing', 1));
    }

    /** @return array<string, mixed> */
    private function vehiclePayload(): array
    {
        return [
            'external_code' => 'honda-click-160-test',
            'type' => 'scooter',
            'category_id' => null,
            'name' => 'Honda Click 160',
            'inventory_code' => 'HC-160-T',
            'year' => 2026,
            'description' => 'Customer description',
            'characteristics_text' => 'Automatic, two seats',
            'emoji' => 'HC',
            'is_active' => true,
            'is_visible_for_booking' => false,
            'sort_order' => 10,
            'pricing_profile' => 'standard',
        ];
    }

    /** @return array{prices: array<string, array<string, int|null>>, enabled: array<string, array<string, bool>>} */
    private function pricePayload(): array
    {
        $prices = [];
        $enabled = [];

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $prices[$season->value][$tier->value] = $tier->anchorDays() * 100;
                $enabled[$season->value][$tier->value] = true;
            }
        }

        return ['prices' => $prices, 'enabled' => $enabled];
    }
}
