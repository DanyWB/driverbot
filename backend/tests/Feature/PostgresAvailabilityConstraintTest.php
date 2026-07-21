<?php

namespace Tests\Feature;

use App\Domain\Availability\Enums\OccupancyType;
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
