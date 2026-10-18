<?php

namespace Tests\Feature;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingStatusMutationGuard;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Vehicles\Services\VehicleCatalogService;
use App\Domain\Vehicles\Services\VehiclePhotoService;
use App\Models\Booking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class VehicleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_requires_confirmation_and_moves_between_tabs_then_restores_unpublished(): void
    {
        $vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
        $this->actingAs(User::factory()->create());
        $this->post(route('vehicles.archive', $vehicle))->assertSessionHasErrors('confirmed');
        $this->post(route('vehicles.archive', $vehicle), ['confirmed' => true])->assertRedirect('/vehicles?tab=archive');
        $this->assertNotNull($vehicle->refresh()->archived_at);
        $this->assertFalse($vehicle->is_active);
        $this->assertFalse($vehicle->is_visible_for_booking);
        $this->get('/vehicles')->assertInertia(fn (Assert $page) => $page->has('vehicles.data', 0));
        $this->get('/vehicles?tab=archive')->assertInertia(fn (Assert $page) => $page->has('vehicles.data', 1)->where('vehicles.data.0.can_delete', true));
        $this->post(route('vehicles.restore', $vehicle))->assertRedirect(route('vehicles.edit', $vehicle));
        $this->assertNull($vehicle->refresh()->archived_at);
        $this->assertTrue($vehicle->is_active);
        $this->assertFalse($vehicle->is_visible_for_booking);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => (string) $vehicle->id, 'action' => 'vehicle.archive']);
    }

    public function test_all_unfinished_statuses_block_archive_even_with_old_dates_but_allow_unpublishing(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([BookingStatus::Process, BookingStatus::Pending, BookingStatus::Approved, BookingStatus::Active] as $status) {
            foreach ([['2020-01-01', '2020-01-02'], ['2030-01-01', '2030-01-02']] as [$start, $end]) {
                $vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
                $booking = app(BookingStatusMutationGuard::class)->run(fn () => Booking::factory()->create([
                    'vehicle_id' => $vehicle->id, 'status' => $status, 'starts_on' => $start, 'ends_on' => $end,
                ]));
                $this->post(route('vehicles.archive', $vehicle), ['confirmed' => true])->assertSessionHasErrors('vehicle');
                $this->assertNull($vehicle->refresh()->archived_at);
                $this->post(route('vehicles.unpublish', $vehicle))->assertSessionHasNoErrors();
                $this->assertTrue($vehicle->refresh()->is_active);
                $this->assertFalse($vehicle->is_visible_for_booking);
                $this->assertSame($status, $booking->refresh()->status);
            }
        }
    }

    public function test_closed_history_allows_archive_but_prevents_permanent_deletion(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::CancelledByClient, BookingStatus::CancelledUnavailable, BookingStatus::Expired, BookingStatus::NoShow] as $status) {
            $vehicle = Vehicle::factory()->create();
            app(BookingStatusMutationGuard::class)->run(fn () => Booking::factory()->create(['vehicle_id' => $vehicle->id, 'status' => $status]));
            $this->post(route('vehicles.archive', $vehicle), ['confirmed' => true])->assertSessionHasNoErrors();
            $this->delete(route('vehicles.destroy', $vehicle), ['confirmation_name' => $vehicle->name])->assertSessionHasErrors('vehicle');
            $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
            $this->assertSame(1, $vehicle->bookings()->count());
        }
    }

    public function test_delete_requires_archive_and_exact_name(): void
    {
        $this->actingAs(User::factory()->create());
        $vehicle = Vehicle::factory()->create(['name' => 'Scoopy red']);
        $this->delete(route('vehicles.destroy', $vehicle), ['confirmation_name' => $vehicle->name])->assertSessionHasErrors('vehicle');
        $this->post(route('vehicles.archive', $vehicle), ['confirmed' => true]);
        $this->delete(route('vehicles.destroy', $vehicle), ['confirmation_name' => 'wrong'])->assertSessionHasErrors('vehicle');
        $this->delete(route('vehicles.destroy', $vehicle), ['confirmation_name' => $vehicle->name])->assertRedirect('/vehicles?tab=archive');
        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => (string) $vehicle->id, 'action' => 'vehicle.deleted']);
    }

    public function test_archived_vehicle_cannot_be_reactivated_by_stale_form_or_quoted(): void
    {
        $vehicle = Vehicle::factory()->create();
        $this->actingAs(User::factory()->create())->post(route('vehicles.archive', $vehicle), ['confirmed' => true]);
        $updated = app(VehicleCatalogService::class)->updateVehicle($vehicle, [
            'name' => $vehicle->name, 'type' => 'scooter', 'is_active' => true, 'is_visible_for_booking' => true,
        ]);
        $this->assertNotNull($updated->archived_at);
        $this->assertFalse($updated->is_active);
        $this->assertFalse($updated->is_visible_for_booking);
        $this->expectException(PricingException::class);
        app(PricingService::class)->quote($updated, RentalPeriod::fromStrings('2030-01-01', '2030-01-03'), false);
    }

    public function test_permanent_delete_removes_original_and_derived_photos_only_after_commit(): void
    {
        Storage::fake('public');
        $vehicle = Vehicle::factory()->create();
        app(VehiclePhotoService::class)->store($vehicle, UploadedFile::fake()->image('bike.jpg', 900, 600));
        $this->assertCount(3, Storage::disk('public')->allFiles());
        $this->actingAs(User::factory()->create())->post(route('vehicles.archive', $vehicle), ['confirmed' => true]);
        $this->delete(route('vehicles.destroy', $vehicle), ['confirmation_name' => $vehicle->name])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
        $this->assertDatabaseCount('vehicle_photos', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_audit_failure_rolls_back_archive(): void
    {
        $vehicle = Vehicle::factory()->create(['is_active' => true]);
        $this->mock(AdminAuditService::class, fn (MockInterface $mock) => $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable')));
        $this->actingAs(User::factory()->create())->post(route('vehicles.archive', $vehicle), ['confirmed' => true])->assertServerError();
        $this->assertNull($vehicle->refresh()->archived_at);
        $this->assertTrue($vehicle->is_active);
    }

    public function test_guest_cannot_change_lifecycle(): void
    {
        $vehicle = Vehicle::factory()->create();
        foreach (['archive', 'restore', 'unpublish'] as $action) {
            $this->post(route('vehicles.'.$action, $vehicle), ['confirmed' => true])->assertRedirect('/login');
        }
        $this->delete(route('vehicles.destroy', $vehicle), ['confirmation_name' => $vehicle->name])->assertRedirect('/login');
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }
}
