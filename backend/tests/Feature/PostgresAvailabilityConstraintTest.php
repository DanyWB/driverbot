<?php

namespace Tests\Feature;

use App\Domain\Availability\Enums\OccupancyType;
use App\Models\Booking;
use App\Models\NotificationOutbox;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresAvailabilityConstraintTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL exclusion constraints require the pgsql driver.');
        }
    }

    public function test_blocking_intervals_for_the_same_vehicle_cannot_overlap(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->occupy($vehicle, '2026-08-01', '2026-08-05');

        try {
            $this->occupy($vehicle, '2026-08-05', '2026-08-10');
            $this->fail('An overlapping interval was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
        }
    }

    public function test_adjacent_and_non_blocking_intervals_are_allowed(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->occupy($vehicle, '2026-08-01', '2026-08-05');
        $this->occupy($vehicle, '2026-08-06', '2026-08-10');
        $this->occupy($vehicle, '2026-08-03', '2026-08-07', false);

        $this->assertSame(3, VehicleOccupancy::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_booking_time_constraint_rejects_a_rental_shorter_than_one_hour(): void
    {
        try {
            Booking::factory()->create([
                'starts_on' => '2026-08-10',
                'ends_on' => '2026-08-10',
                'pickup_time' => '10:00',
                'return_time' => '10:30',
            ]);
            $this->fail('The database accepted a rental shorter than one hour.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_notification_outbox_constraint_accepts_discarded_and_rejects_unknown_statuses(): void
    {
        NotificationOutbox::query()->create([
            'deduplication_key' => 'postgres-discarded-status',
            'event_type' => 'booking.reminder.pickup_day',
            'channel' => 'telegram',
            'recipient' => '100500',
            'payload' => [],
            'status' => NotificationOutbox::STATUS_DISCARDED,
            'available_at' => now(),
            'discarded_at' => now(),
        ]);

        try {
            NotificationOutbox::query()->create([
                'deduplication_key' => 'postgres-invalid-status',
                'event_type' => 'booking.pending',
                'channel' => 'telegram',
                'recipient' => '100500',
                'payload' => [],
                'status' => 'unknown',
                'available_at' => now(),
            ]);
            $this->fail('The database accepted an unknown outbox status.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    private function occupy(Vehicle $vehicle, string $startsOn, string $endsOn, bool $blocks = true): void
    {
        VehicleOccupancy::query()->create([
            'vehicle_id' => $vehicle->id,
            'type' => OccupancyType::Maintenance,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'blocks_availability' => $blocks,
            'label' => 'Constraint test',
        ]);
    }
}
