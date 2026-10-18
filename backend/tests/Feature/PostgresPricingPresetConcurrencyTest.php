<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Models\Category;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresPricingPresetConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL pricing preset concurrency test requires the pgsql driver.');
        }
    }

    public function test_simultaneous_update_and_attach_leave_one_coherent_source_of_truth(): void
    {
        [$admin, $preset, $vehicle, $category, $suffix] = $this->fixture(100);
        $updatedPrices = $this->matrix(700);

        try {
            $barrier = (string) (microtime(true) + 1.0);
            $update = $this->updateWorker($preset, $admin, "Attach race {$suffix}", 700, 0, $barrier);
            $attach = $this->assignmentWorker('attach', $preset, $vehicle, 1, null, 1, $barrier);
            $results = $this->race($update, $attach);

            $successfulOperation = $results[0]['operation'];
            $preset->refresh();
            $vehicle->refresh();

            if ($successfulOperation === 'update') {
                $this->assertSame(2, $preset->version);
                $this->assertNull($vehicle->pricing_preset_id);
                $this->assertDatabaseMissing('vehicle_price_tiers', ['vehicle_id' => $vehicle->id]);
                $this->assertSame($updatedPrices, app(PricingPresetService::class)->matrix($preset));
            } else {
                $this->assertSame('attach', $successfulOperation);
                $this->assertSame(1, $preset->version);
                $this->assertSame($preset->id, $vehicle->pricing_preset_id);
                $this->assertSame($this->matrix(100), app(PricingPresetService::class)->matrix($preset));
                $this->assertVehicleMatrix($vehicle, $this->matrix(100));
            }
        } finally {
            $this->cleanup($admin, $preset, $vehicle, $category);
        }
    }

    public function test_simultaneous_update_and_detach_leave_one_coherent_source_of_truth(): void
    {
        [$admin, $preset, $vehicle, $category, $suffix] = $this->fixture(200);
        app(PricingPresetService::class)->attach($vehicle, $preset, 1, null, 1, 'en');
        $vehicle->refresh();
        $updatedPrices = $this->matrix(900);

        try {
            $barrier = (string) (microtime(true) + 1.0);
            $update = $this->updateWorker($preset, $admin, "Detach race {$suffix}", 900, 1, $barrier);
            $detach = $this->assignmentWorker(
                'detach',
                $preset,
                $vehicle,
                1,
                $preset->id,
                $vehicle->pricing_version,
                $barrier,
            );
            $results = $this->race($update, $detach);

            $successfulOperation = $results[0]['operation'];
            $preset->refresh();
            $vehicle->refresh();

            if ($successfulOperation === 'update') {
                $this->assertSame(2, $preset->version);
                $this->assertSame($preset->id, $vehicle->pricing_preset_id);
                $this->assertSame($updatedPrices, app(PricingPresetService::class)->matrix($preset));
                $this->assertVehicleMatrix($vehicle, $updatedPrices);
            } else {
                $this->assertSame('detach', $successfulOperation);
                $this->assertSame(1, $preset->version);
                $this->assertNull($vehicle->pricing_preset_id);
                $this->assertSame($this->matrix(200), app(PricingPresetService::class)->matrix($preset));
                $this->assertVehicleMatrix($vehicle, $this->matrix(200));
            }
        } finally {
            $this->cleanup($admin, $preset, $vehicle, $category);
        }
    }

    /** @return array{User, PricingPreset, Vehicle, Category, string} */
    private function fixture(int $base): array
    {
        $this->seed();
        $admin = User::factory()->create();
        $category = Category::factory()->create();
        $vehicle = Vehicle::factory()->create(['category_id' => $category->id]);
        $suffix = str_replace('.', '', uniqid('concurrent-', true));
        $preset = app(PricingPresetService::class)->create([
            'name' => "Concurrency source {$suffix}",
            'vehicle_type' => 'scooter',
            'prices' => $this->matrix($base),
        ], $admin->id);

        return [$admin, $preset, $vehicle, $category, $suffix];
    }

    /** @return list<array<string, mixed>> */
    private function race(Process $first, Process $second): array
    {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $results = [
            json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR),
            json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR),
        ];
        usort($results, fn (array $left, array $right): int => $left['status'] <=> $right['status']);

        $diagnostics = $first->getErrorOutput().$second->getErrorOutput();
        $this->assertSame(200, $results[0]['status'], $diagnostics);
        $this->assertSame(409, $results[1]['status'], $diagnostics);

        return $results;
    }

    private function updateWorker(
        PricingPreset $preset,
        User $admin,
        string $name,
        int $base,
        int $expectedLinkedVehiclesCount,
        string $barrier,
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/update_pricing_preset_worker.php'),
            (string) $preset->id,
            (string) $admin->id,
            $name,
            (string) $base,
            (string) $expectedLinkedVehiclesCount,
            $barrier,
        ], base_path(), $this->workerEnvironment(), timeout: 20);
    }

    private function assignmentWorker(
        string $operation,
        PricingPreset $preset,
        Vehicle $vehicle,
        int $presetVersion,
        ?int $expectedPresetId,
        int $expectedVehicleRevision,
        string $barrier,
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/assign_pricing_preset_worker.php'),
            $operation,
            (string) $preset->id,
            (string) $vehicle->id,
            (string) $presetVersion,
            $expectedPresetId === null ? 'null' : (string) $expectedPresetId,
            (string) $expectedVehicleRevision,
            $barrier,
        ], base_path(), $this->workerEnvironment(), timeout: 20);
    }

    /** @return array<string, string> */
    private function workerEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_SEARCH_PATH' => (string) $connection['search_path'],
            'DB_SSLMODE' => (string) $connection['sslmode'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
    }

    /** @return array<string, array<string, int>> */
    private function matrix(int $base): array
    {
        $prices = [];
        $offset = 0;

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $prices[$season->value][$tier->value] = $base + $offset++;
            }
        }

        return $prices;
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
            foreach ($tiers as $tier => $value) {
                $row = $rows->first(fn (VehiclePriceTier $candidate): bool => $candidate->season->seasonKey()->value === $season
                    && $candidate->getRawOriginal('tier_key') === $tier);
                $this->assertNotNull($row, "Missing {$season}/{$tier} after concurrent pricing mutation.");
                $this->assertSame($value, (int) $row->package_total);
            }
        }
    }

    private function cleanup(User $admin, PricingPreset $preset, Vehicle $vehicle, Category $category): void
    {
        VehiclePriceTier::query()->where('vehicle_id', $vehicle->id)->delete();
        $vehicle->delete();
        $preset->delete();
        $category->delete();
        $admin->delete();
    }
}
