<?php

namespace Tests\Feature;

use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Http\Controllers\PricingPresets\PricingToolsController;
use App\Models\PricingPreset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PricingPresetReadSnapshotTest extends TestCase
{
    private User $admin;

    private ?PricingPreset $preset = null;

    protected function setUp(): void
    {
        parent::setUp();
        // PostgreSQL migrations belong to the isolated race-suite harness. Do
        // not wrap fixtures in a transaction: subprocesses must see them.
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(0, Artisan::call('migrate:fresh'));
        }
        $this->seed();
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        try {
            $this->preset?->delete();
            if (isset($this->admin)) {
                $this->admin->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_calculate_reloads_stale_route_bound_rules_version_and_preloaded_prices(): void
    {
        $original = $this->data(100);
        $stale = $this->create($original);
        $this->assertTrue($stale->relationLoaded('prices'));
        $updated = $this->data(700);
        app(PricingPresetService::class)->update($stale, [...$updated, 'version' => 1, 'expected_linked_vehicles_count' => 0], $this->admin->id, 'en');
        $this->assertSame(1, (int) $stale->version);

        foreach ([['days' => 8, 'season' => 'high'], ['starts_on' => '2027-01-02', 'ends_on' => '2027-01-10']] as $input) {
            $response = $this->calculate($stale, $input);
            $this->assertSame(2, $response['version']);
            $this->assertSame(app(PricingCalculator::class)->matrix($updated['prices'], $updated['calculation_settings']), $response['matrix']);
            $this->assertSame(700 * 37, $response['data']['total']);
        }
    }

    public function test_calculate_checks_fresh_activation_instead_of_stale_legacy_mode(): void
    {
        $original = $this->data(100);
        $original['calculation_settings'] = null;
        $stale = $this->create($original);
        $updated = $this->data(700);
        app(PricingPresetService::class)->update($stale, [...$updated, 'version' => 1, 'expected_linked_vehicles_count' => 0], $this->admin->id, 'en');

        $response = $this->calculate($stale, ['days' => 8, 'season' => 'high']);
        $this->assertSame(2, $response['version']);
        $this->assertSame(700 * 37, $response['data']['total']);
    }

    public function test_calculate_rejects_fresh_legacy_mode_despite_stale_enabled_model(): void
    {
        $original = $this->data(100);
        $stale = $this->create($original);
        app(PricingPresetService::class)->update($stale, [...$original, 'calculation_settings' => null, 'version' => 1, 'expected_linked_vehicles_count' => 0], $this->admin->id, 'en');

        $this->expectException(ValidationException::class);
        $this->calculate($stale, ['days' => 8, 'season' => 'high']);
    }

    public function test_postgres_calculate_holds_one_snapshot_while_writer_waits(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consistent pricing read race requires the PostgreSQL driver.');
        }
        $original = $this->data(100);
        $preset = $this->create($original);
        $updated = $this->data(700);
        $gate = random_int(1, 2147483647);
        $label = 'pricing-read-'.bin2hex(random_bytes(6));
        $reader = $this->worker(['operation' => 'read', 'preset_id' => $preset->id, 'gate' => $gate, 'label' => $label.'-reader']);
        $writer = $this->worker(['operation' => 'write', 'preset_id' => $preset->id, 'admin_id' => $this->admin->id, 'data' => $updated, 'label' => $label.'-writer']);
        DB::select('SELECT pg_advisory_lock(?)', [$gate]);
        try {
            $reader->start();
            $this->waitForLock($reader, $label.'-reader', 'advisory');
            $writer->start();
            // A real PostgreSQL lock wait proves the read owns the parent row
            // before fetching children; merely starting two processes does not.
            $this->waitForLock($writer, $label.'-writer');
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $reader->wait();
            $writer->wait();
            $this->assertTrue($reader->isSuccessful(), $reader->getErrorOutput());
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            $read = json_decode(trim($reader->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $read['version']);
            $this->assertSame(100 * 37, $read['data']['total']);
            $this->assertSame(app(PricingCalculator::class)->matrix($original['prices'], $original['calculation_settings']), $read['matrix']);
            $after = $this->calculate($preset, ['days' => 8, 'season' => 'high']);
            $this->assertSame(2, $after['version']);
            $this->assertSame(700 * 37, $after['data']['total']);
            $this->assertSame(app(PricingCalculator::class)->matrix($updated['prices'], $updated['calculation_settings']), $after['matrix']);
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            foreach ([$reader, $writer] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    /** @return array{name:string,vehicle_type:null,prices:array<string,array<string,int>>,calculation_settings:array<string,mixed>|null} */
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

        return ['name' => 'Read snapshot '.uniqid('', true), 'vehicle_type' => null, 'prices' => $prices, 'calculation_settings' => $settings];
    }

    /** @param array{name:string,vehicle_type:null,prices:array<string,array<string,int>>,calculation_settings:array<string,mixed>|null} $data */
    private function create(array $data): PricingPreset
    {
        return $this->preset = app(PricingPresetService::class)->create($data, $this->admin->id);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function calculate(PricingPreset $bound, array $input): array
    {
        $response = app()->call([app(PricingToolsController::class), 'calculate'], ['request' => Request::create('/pricing-presets/'.$bound->id.'/calculate', 'GET', $input), 'pricingPreset' => $bound]);

        return $response->getData(true);
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

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password', 'search_path', 'sslmode'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $connection[$key];
        }

        return new Process([PHP_BINARY, base_path('tests/Support/pricing_preset_read_worker.php')], base_path(), $environment, json_encode($input, JSON_THROW_ON_ERROR), 20);
    }
}
