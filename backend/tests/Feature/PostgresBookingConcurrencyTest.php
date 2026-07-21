<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\NotificationOutbox;
use App\Models\PricingSeason;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresBookingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency test requires the pgsql driver.');
        }
    }

    public function test_two_simultaneous_requests_create_one_booking_and_one_conflict(): void
    {
        $this->seed();
        $category = Category::factory()->create();
        $vehicle = Vehicle::factory()->create([
            'category_id' => $category->id,
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
        $customers = Customer::factory()->count(2)->create();
        $this->addPrice($vehicle);

        try {
            $barrier = (string) (microtime(true) + 1.0);
            $first = $this->worker((int) $customers[0]->id, $vehicle->id, $barrier);
            $second = $this->worker((int) $customers[1]->id, $vehicle->id, $barrier);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            $results = [
                json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR),
                json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR),
            ];
            usort($results, fn (array $left, array $right): int => $left['status'] <=> $right['status']);

            $this->assertSame(201, $results[0]['status'], $first->getErrorOutput().$second->getErrorOutput());
            $this->assertSame(409, $results[1]['status'], $first->getErrorOutput().$second->getErrorOutput());
            $this->assertSame('vehicle_unavailable', $results[1]['error_code']);
            $this->assertSame(1, Booking::query()->where('vehicle_id', $vehicle->id)->count());
        } finally {
            $this->cleanup($vehicle, $category, $customers->pluck('id')->all());
        }
    }

    private function worker(int $customerId, int $vehicleId, string $barrier): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_SSLMODE' => (string) $connection['sslmode'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        return new Process([
            PHP_BINARY,
            base_path('tests/Support/create_booking_worker.php'),
            (string) $customerId,
            (string) $vehicleId,
            '2026-08-10',
            '2026-08-12',
            $barrier,
        ], base_path(), $environment, timeout: 20);
    }

    private function addPrice(Vehicle $vehicle): void
    {
        $season = PricingSeason::query()->where('key', PricingSeasonKey::Low->value)->firstOrFail();
        $tier = PricingTier::OneDay;
        $packageTotal = 300;

        VehiclePriceTier::query()->create([
            'vehicle_id' => $vehicle->id,
            'pricing_season_id' => $season->id,
            'tier_key' => $tier,
            'min_days' => $tier->minimumDays(),
            'max_days' => $tier->maximumDays(),
            'anchor_days' => $tier->anchorDays(),
            'package_total' => $packageTotal,
            'daily_rate' => (string) BigDecimal::of($packageTotal)->dividedBy($tier->anchorDays(), 6, RoundingMode::HalfUp),
            'currency' => 'THB',
            'is_active' => true,
        ]);
    }

    /** @param list<int> $customerIds */
    private function cleanup(Vehicle $vehicle, Category $category, array $customerIds): void
    {
        $bookings = Booking::query()->where('vehicle_id', $vehicle->id)->get();

        foreach ($bookings as $booking) {
            NotificationOutbox::query()->where('deduplication_key', 'like', "booking:{$booking->public_id}:%")->delete();
            AuditLog::query()->where('subject_type', Booking::class)->where('subject_id', $booking->public_id)->delete();
        }

        Booking::query()->where('vehicle_id', $vehicle->id)->delete();
        VehiclePriceTier::query()->where('vehicle_id', $vehicle->id)->delete();
        $vehicle->delete();
        Customer::query()->whereKey($customerIds)->delete();
        $category->delete();
    }
}
