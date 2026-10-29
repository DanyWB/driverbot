<?php

namespace Tests\Feature;

use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresPricingToolsConcurrencyTest extends TestCase
{
    private User $admin;

    private Category $category;

    /** @var list<PricingPreset> */
    private array $presets = [];

    /** @var list<Vehicle> */
    private array $vehicles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL pricing tools races require the pgsql driver.');
        }
        $this->seed();
        $this->admin = User::factory()->create();
        $this->category = Category::factory()->create();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->admin)) {
                foreach ($this->vehicles as $vehicle) {
                    VehiclePriceTier::query()->where('vehicle_id', $vehicle->id)->delete();
                    $vehicle->delete();
                }
                foreach ($this->presets as $preset) {
                    $preset->delete();
                }
                AuditLog::query()->where('actor_admin_id', $this->admin->id)->delete();
                $this->category->delete();
                $this->admin->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_calculator_import_and_attach_have_one_coherent_winner(): void
    {
        [$preset, $vehicle, $original] = $this->fixture(100, false);
        $imported = $this->data(700, $preset->name);
        $winner = $this->race(
            $this->importOperation('import', [[$preset, $vehicle, $imported]]),
            ['operation' => 'attach', 'label' => 'attach', 'preset_id' => $preset->id, 'vehicle_id' => $vehicle->id, 'preset_version' => 1, 'vehicle_version' => 1],
        );
        $this->assertCoherent($preset, $vehicle, $winner === 'import' ? $imported : $original, $winner === 'import' ? 2 : 1, 2);
    }

    public function test_two_imports_cannot_mix_calculator_rules_prices_or_assignment_revisions(): void
    {
        [$preset, $vehicle] = $this->fixture(200);
        $first = $this->data(800, $preset->name);
        $second = $this->data(1200, $preset->name);
        $winner = $this->race(
            $this->importOperation('first-import', [[$preset, $vehicle, $first]]),
            $this->importOperation('second-import', [[$preset, $vehicle, $second]]),
        );
        $this->assertCoherent($preset, $vehicle, $winner === 'first-import' ? $first : $second, 2, 4);
    }

    public function test_bulk_and_import_roll_back_their_uncontested_first_row_when_shared_second_row_is_stale(): void
    {
        [$bulkOnly, $bulkVehicle, $bulkOriginal] = $this->fixture(300);
        [$shared, $sharedVehicle] = $this->fixture(400);
        [$importOnly, $importVehicle, $importOriginal] = $this->fixture(500);
        $bulkFirst = $this->data(1300, $bulkOnly->name);
        $bulkSecond = $this->data(1400, $shared->name);
        $importFirst = $this->data(1500, $importOnly->name);
        $importSecond = $this->data(1600, $shared->name);
        // Each operation changes its own first preset before reaching the shared
        // second preset. Do not explicitly remap the shared vehicle in import:
        // its early revision guard would hide the late-conflict rollback path.
        $winner = $this->race(
            ['operation' => 'bulk', 'label' => 'bulk', 'admin_id' => $this->admin->id, 'payload' => ['confirmed' => true, 'presets' => [
                ['id' => $bulkOnly->id, 'version' => 1, 'expected_linked_vehicles_count' => 1, ...$bulkFirst],
                ['id' => $shared->id, 'version' => 1, 'expected_linked_vehicles_count' => 1, ...$bulkSecond],
            ]]],
            $this->importOperation('import', [[$importOnly, $importVehicle, $importFirst], [$shared, $sharedVehicle, $importSecond]], $sharedVehicle->id),
        );
        $this->assertCoherent($shared, $sharedVehicle, $winner === 'bulk' ? $bulkSecond : $importSecond, 2, 3);
        $this->assertCoherent($bulkOnly, $bulkVehicle, $winner === 'bulk' ? $bulkFirst : $bulkOriginal, $winner === 'bulk' ? 2 : 1, $winner === 'bulk' ? 3 : 2);
        $this->assertCoherent($importOnly, $importVehicle, $winner === 'import' ? $importFirst : $importOriginal, $winner === 'import' ? 2 : 1, $winner === 'import' ? 4 : 2);
        $this->assertSame($winner === 'bulk' ? 2 : 0, AuditLog::query()->where('actor_admin_id', $this->admin->id)->where('action', 'pricing_preset.bulk_updated')->count());
    }

    /** @return array{PricingPreset, Vehicle, array<string, mixed>} */
    private function fixture(int $base, bool $attached = true): array
    {
        $data = $this->data($base, 'Pricing tools race '.uniqid('', true));
        $preset = app(PricingPresetService::class)->create($data, $this->admin->id);
        $this->presets[] = $preset;
        $vehicle = Vehicle::factory()->create(['category_id' => $this->category->id]);
        $this->vehicles[] = $vehicle;
        if ($attached) {
            app(PricingPresetService::class)->attach($vehicle, $preset, 1, null, 1, 'en');
        }

        return [$preset, $vehicle->refresh(), $data];
    }

    /** @return array{name: string, vehicle_type: null, prices: array<string, array<string, int>>, calculation_settings: array<string, mixed>} */
    private function data(int $base, string $name): array
    {
        $prices = [];
        foreach (PricingCalculator::SEASONS as $index => $season) {
            foreach (PricingCalculator::TIERS as $tierIndex => $tier) {
                $prices[$season][$tier] = $base * ($tierIndex + 1) + $index * 10;
            }
        }
        $settings = PricingCalculator::defaults();
        $settings['dayOverrides'] = ['middle:27' => $base * 37];
        $settings['rules']['step'] = $base;

        return ['name' => $name, 'vehicle_type' => null, 'prices' => $prices, 'calculation_settings' => $settings];
    }

    /** @param list<array{PricingPreset, Vehicle, array<string, mixed>}> $rows
     * @return array<string, mixed>
     */
    private function importOperation(string $label, array $rows, ?int $unmappedVehicleId = null): array
    {
        $payload = ['format' => 'drive-pricing-presets', 'version' => 1, 'presets' => [], 'assignments' => []];
        $targets = [];
        $mapping = [];
        $presetRevisions = [];
        $vehicleRevisions = [];
        foreach ($rows as [$preset, $vehicle, $data]) {
            $sourceId = 'SYNTHETIC-'.$vehicle->id;
            $payload['presets'][] = ['id' => $preset->id, ...$data];
            if ($vehicle->id !== $unmappedVehicleId) {
                $payload['assignments'][] = ['external_code' => $sourceId, 'pricing_preset_id' => $preset->id];
                $mapping[$sourceId] = $vehicle->id;
            }
            $targets[$preset->name] = $preset->id;
            $presetRevisions[$preset->id] = ['version' => (int) $preset->version, 'linked_count' => $preset->vehicles()->count()];
            $vehicleRevisions[$vehicle->id] = ['version' => (int) $vehicle->pricing_version, 'preset_id' => $vehicle->pricing_preset_id === null ? null : (int) $vehicle->pricing_preset_id];
        }
        // Same server-owned revision snapshot shape as the real preview controller;
        // parsing and committing both use the actual import service.
        $preview = app(PricingSettingsImport::class)->preview($payload);
        $preview['preset_revisions'] = $presetRevisions;
        $preview['vehicle_revisions'] = $vehicleRevisions;

        return ['operation' => 'import', 'label' => $label, 'admin_id' => $this->admin->id, 'preview' => $preview, 'targets' => $targets, 'mapping' => $mapping];
    }

    /** @param array<string, mixed> $first
     * @param  array<string, mixed>  $second
     */
    private function race(array $first, array $second): string
    {
        $barrier = microtime(true) + 1.5;
        $barrierKey = random_int(1, 2_000_000_000);
        $processes = [
            $this->worker([...$first, 'barrier' => $barrier, 'barrier_key' => $barrierKey, 'slot' => 1]),
            $this->worker([...$second, 'barrier' => $barrier, 'barrier_key' => $barrierKey, 'slot' => 2]),
        ];
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            usort($results, fn (array $left, array $right): int => $left['status'] <=> $right['status']);
            $this->assertSame([200, 409], array_column($results, 'status'), json_encode($results, JSON_THROW_ON_ERROR));

            return $results[0]['operation'];
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password', 'search_path', 'sslmode'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
        }

        return new Process([PHP_BINARY, base_path('tests/Support/pricing_tools_race_worker.php')], base_path(), $environment, json_encode($input, JSON_THROW_ON_ERROR), 20);
    }

    /** @param array<string, mixed> $expected */
    private function assertCoherent(PricingPreset $preset, Vehicle $vehicle, array $expected, int $presetVersion, int $vehicleVersion): void
    {
        $preset->refresh();
        $vehicle->refresh();
        $this->assertSame($presetVersion, (int) $preset->version);
        $this->assertSame($vehicleVersion, (int) $vehicle->pricing_version);
        $this->assertSame($preset->id, $vehicle->pricing_preset_id);
        $this->assertEquals($expected['calculation_settings'], $preset->calculation_settings);
        $this->assertEquals($expected['calculation_settings'], $vehicle->calculation_settings);
        $this->assertSame($expected['prices'], app(PricingPresetService::class)->matrix($preset));
        $rows = VehiclePriceTier::query()->where('vehicle_id', $vehicle->id)->with('season')->get();
        $this->assertCount(15, $rows);
        foreach ($expected['prices'] as $season => $tiers) {
            foreach ($tiers as $tier => $price) {
                $row = $rows->first(fn (VehiclePriceTier $candidate): bool => $candidate->season->seasonKey()->value === $season && $candidate->getRawOriginal('tier_key') === $tier);
                $this->assertNotNull($row);
                $this->assertSame($price, (int) $row->package_total);
                $this->assertTrue($row->is_active);
            }
        }
        $calculator = app(PricingCalculator::class);
        $this->assertSame($calculator->matrix($expected['prices'], $expected['calculation_settings']), $calculator->matrix(app(PricingPresetService::class)->matrix($preset), $vehicle->calculation_settings));
    }
}
