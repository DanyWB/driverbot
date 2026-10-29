<?php

namespace Tests\Feature;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\Category;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VehicleQuoteReadSnapshotTest extends TestCase
{
    private User $admin;

    private Category $category;

    private PricingPreset $preset;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(0, Artisan::call('migrate:fresh'));
        }
        $this->seed();
        $this->admin = User::factory()->create();
        $this->category = Category::factory()->create();
        $this->preset = app(PricingPresetService::class)->create($this->data(100), $this->admin->id);
        $this->vehicle = Vehicle::factory()->create(['category_id' => $this->category->id, 'is_active' => true, 'is_visible_for_booking' => true]);
        app(PricingPresetService::class)->attach($this->vehicle, $this->preset, 1, null, 1, 'en');
        $this->vehicle->refresh();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->vehicle)) {
                VehiclePriceTier::query()->where('vehicle_id', $this->vehicle->id)->delete();
                $this->vehicle->delete();
            }
            if (isset($this->preset)) {
                $this->preset->delete();
                $this->category->delete();
                $this->admin->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_quote_reloads_stale_vehicle_rules_and_materialized_prices(): void
    {
        $updated = $this->data(700);
        app(PricingPresetService::class)->update($this->preset, [...$updated, 'version' => 1, 'expected_linked_vehicles_count' => 1], $this->admin->id, 'en');
        $this->assertSame(2, (int) $this->vehicle->pricing_version);

        $quote = app(PricingService::class)->quote($this->vehicle, $this->period());
        $this->assertSame(700 * 37, $quote->finalTotal);
        $this->assertSame($this->expected($updated), $quote->calculation);
        $this->assertSame(1400, $quote->breakdown[0]->packageTotal);
    }

    public function test_quote_uses_fresh_calculator_activation_for_a_stale_legacy_vehicle(): void
    {
        Vehicle::query()->whereKey($this->vehicle->id)->update(['calculation_settings' => null]);
        $stale = $this->vehicle->fresh();
        app(PricingPresetService::class)->update($this->preset, [...$this->data(700), 'version' => 1, 'expected_linked_vehicles_count' => 1], $this->admin->id, 'en');

        $quote = app(PricingService::class)->quote($stale, $this->period());
        $this->assertSame(700 * 37, $quote->finalTotal);
        $this->assertNotNull($quote->calculation);
    }

    public function test_quote_uses_fresh_legacy_mode_for_a_stale_calculator_vehicle(): void
    {
        app(PricingPresetService::class)->update($this->preset, [...$this->data(700), 'calculation_settings' => null, 'version' => 1, 'expected_linked_vehicles_count' => 1], $this->admin->id, 'en');

        $quote = app(PricingService::class)->quote($this->vehicle, $this->period());
        $this->assertNull($quote->calculation);
        $this->assertSame(1600, $quote->finalTotal);
    }

    public function test_quote_checks_fresh_visibility_active_and_archive_flags(): void
    {
        foreach ([['is_visible_for_booking' => false], ['is_active' => false], ['archived_at' => now()]] as $change) {
            Vehicle::query()->whereKey($this->vehicle->id)->update(['is_visible_for_booking' => true, 'is_active' => true, 'archived_at' => null, ...$change]);
            try {
                app(PricingService::class)->quote($this->vehicle, $this->period());
                $this->fail('Stale vehicle flags allowed a quote after visibility changed.');
            } catch (PricingException $exception) {
                $this->assertSame('vehicle_hidden', $exception->errorCode);
            }
        }
        Vehicle::query()->whereKey($this->vehicle->id)->update(['is_active' => true, 'archived_at' => null, 'is_visible_for_booking' => false]);
        $this->assertSame(3700, app(PricingService::class)->quote($this->vehicle, $this->period(), false)->finalTotal);
    }

    public function test_detached_manual_matrix_save_keeps_rules_and_is_used_by_a_stale_vehicle_quote(): void
    {
        app(PricingPresetService::class)->detach($this->vehicle, 1, $this->preset->id, 2, 'en');
        $updated = $this->data(700);
        app(VehiclePricingService::class)->replaceMatrix($this->vehicle, $updated['prices'], $this->enabled(), 3);
        $period = RentalPeriod::fromStrings('2027-01-02', '2027-01-04');
        $quote = app(PricingService::class)->quote($this->vehicle, $period);
        $original = $this->data(100);
        $this->assertSame(app(PricingCalculator::class)->quote($updated['prices'], $original['calculation_settings'], $period), $quote->calculation);
        $this->assertSame(700, $quote->breakdown[0]->packageTotal);
        $this->assertNull($this->vehicle->fresh()->pricing_preset_id);
        $this->assertSame($original['calculation_settings'], $this->vehicle->fresh()->calculation_settings);
    }

    public function test_quote_preserves_the_callers_outer_transaction(): void
    {
        DB::transaction(function (): void {
            $locked = Vehicle::query()->whereKey($this->vehicle->id)->lockForUpdate()->firstOrFail();
            $this->assertSame(3700, app(PricingService::class)->quote($locked, $this->period())->finalTotal);
            $this->assertSame(1, DB::transactionLevel());
        });
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_postgres_vehicle_read_blocks_preset_materialization_until_snapshot_finishes(): void
    {
        $this->requirePostgres();
        $updated = $this->data(700);
        $before = $this->race('read', ['operation' => 'preset-update', 'data' => $updated]);
        $this->assertSame($this->expected($this->data(100)), $before['calculation']);
        $after = app(PricingService::class)->quote($this->vehicle, $this->period());
        $this->assertSame($this->expected($updated), $after->calculation);
        $this->assertSame(1400, $after->breakdown[0]->packageTotal);
    }

    public function test_postgres_detached_vehicle_read_blocks_manual_matrix_writer(): void
    {
        $this->requirePostgres();
        app(PricingPresetService::class)->detach($this->vehicle, 1, $this->preset->id, 2, 'en');
        $updated = $this->data(700);
        $before = $this->race('read', ['operation' => 'manual-update', 'prices' => $updated['prices'], 'enabled' => $this->enabled()]);
        $this->assertSame(200, $before['breakdown'][0]['package_total']);
        $after = app(PricingService::class)->quote($this->vehicle, $this->period());
        $this->assertSame(1400, $after->breakdown[0]->packageTotal);
        $this->assertSame($this->expected([...$updated, 'calculation_settings' => $this->data(100)['calculation_settings']]), $after->calculation);
    }

    public function test_postgres_nested_quote_does_not_release_or_downgrade_booking_update_lock(): void
    {
        $this->requirePostgres();
        // The first worker quotes under the same outer FOR UPDATE contract as
        // BookingService, then pauses AFTER quote's inner transaction returned.
        // A second shared reader must still block on that outer update lock.
        $before = $this->race('outer-read', ['operation' => 'plain-read']);
        $this->assertSame($this->expected($this->data(100)), $before['calculation']);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Vehicle quote read races require the PostgreSQL driver.');
        }
    }

    private function period(): RentalPeriod
    {
        return RentalPeriod::fromStrings('2027-01-02', '2027-01-10');
    }

    /** @return array{name:string,vehicle_type:null,prices:array<string,array<string,int>>,calculation_settings:array<string,mixed>} */
    private function data(int $base): array
    {
        $prices = [];
        foreach (PricingCalculator::SEASONS as $index => $season) {
            foreach (PricingCalculator::TIERS as $tierIndex => $tier) {
                $prices[$season][$tier] = $base * ($tierIndex + 1) + $index * 10;
            }
        }
        $settings = PricingCalculator::defaults();
        $settings['dayOverrides'] = ['high:8' => $base * 37];
        $settings['rules']['step'] = $base;

        return ['name' => 'Vehicle read '.uniqid('', true), 'vehicle_type' => null, 'prices' => $prices, 'calculation_settings' => $settings];
    }

    /** @return array<string,array<string,bool>> */
    private function enabled(): array
    {
        return array_fill_keys(PricingCalculator::SEASONS, array_fill_keys(PricingCalculator::TIERS, true));
    }

    /**
     * @param  array{name:string,vehicle_type:null,prices:array<string,array<string,int>>,calculation_settings:array<string,mixed>}  $data
     * @return array<string,mixed>
     */
    private function expected(array $data): array
    {
        return app(PricingCalculator::class)->quote($data['prices'], $data['calculation_settings'], $this->period());
    }

    /**
     * @param  array<string,mixed>  $other
     * @return array<string,mixed>
     */
    private function race(string $firstOperation, array $other): array
    {
        $gate = random_int(1, 2147483647);
        $label = 'vehicle-read-'.bin2hex(random_bytes(6));
        $common = ['preset_id' => $this->preset->id, 'vehicle_id' => $this->vehicle->id, 'admin_id' => $this->admin->id];
        $first = $this->worker([...$common, 'operation' => $firstOperation, 'gate' => $gate, 'label' => $label.'-first']);
        $second = $this->worker([...$common, ...$other, 'label' => $label.'-second']);
        DB::select('SELECT pg_advisory_lock(?)', [$gate]);
        try {
            $first->start();
            $this->waitForLock($first, $label.'-first', 'advisory');
            $second->start();
            $this->waitForLock($second, $label.'-second');
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

            return json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    private function waitForLock(Process $process, string $label, ?string $event = null): void
    {
        $deadline = microtime(true) + 8;
        do {
            $row = DB::selectOne('SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE application_name = ?', [$label]);
            if ($row !== null && $row->wait_event_type === 'Lock' && ($event === null || $row->wait_event === $event)) {
                $this->addToAssertionCount(1);

                return;
            }
            if (! $process->isRunning()) {
                $this->fail('Worker finished before expected lock wait: '.$process->getOutput().' '.$process->getErrorOutput());
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach its expected PostgreSQL lock wait: '.$label);
    }

    /** @param array<string,mixed> $input */
    private function worker(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password', 'search_path', 'sslmode'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
        }

        return new Process([PHP_BINARY, base_path('tests/Support/vehicle_quote_read_worker.php')], base_path(), $environment, json_encode($input, JSON_THROW_ON_ERROR), 20);
    }
}
