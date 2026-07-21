<?php

namespace Tests\Feature;

use App\Domain\Availability\Services\MaintenanceService;
use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_blocks_dates_and_can_be_removed_by_an_admin(): void
    {
        $vehicle = Vehicle::factory()->create();
        $admin = User::factory()->create();
        $actor = BookingActor::admin($admin->id);
        $service = app(MaintenanceService::class);

        $maintenance = $service->create($vehicle->id, '2026-08-01', '2026-08-05', 'Scheduled service', $actor);

        try {
            $service->create($vehicle->id, '2026-08-05', '2026-08-06', 'Overlapping service', $actor);
            $this->fail('Overlapping maintenance was accepted.');
        } catch (BookingException $exception) {
            $this->assertSame('vehicle_unavailable', $exception->errorCode);
        }

        $service->remove($maintenance, $actor);

        $this->assertDatabaseMissing('vehicle_occupancies', ['id' => $maintenance->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.removed']);
    }
}
