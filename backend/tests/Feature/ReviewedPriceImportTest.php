<?php

namespace Tests\Feature;

use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\Booking;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReviewedPriceImport;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/ops/pricing-20261031/ReviewedPriceImport.php';

class ReviewedPriceImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> */
    protected array $payload;

    /** @var array{assignments:array<string,int|null>} */
    protected array $mapping;

    /** @var array<string,mixed> */
    protected array $reference;

    protected User $admin;

    protected function beforeRefreshingDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        if (($connection->getDriverName() === 'pgsql' && ! preg_match('/test/i', $database))
            || ($connection->getDriverName() === 'sqlite' && $database !== ':memory:')) {
            throw new RuntimeException('Synthetic importer tests require an isolated test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
        $json = file_get_contents(base_path('tests/Fixtures/customer-pricing-presets-20261029.json'));
        if ($json === false) {
            throw new RuntimeException('Fixture unreadable.');
        }
        $this->payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->mapping = ['assignments' => []];
        $this->reference = ['vehicles' => []];
        for ($id = 1; $id <= 32; $id++) {
            $inventory = $id < 28 ? 'LIST-'.$id : 'LIST-ROW-'.($id + 4);
            $vehicle = Vehicle::factory()->create([
                'id' => $id, 'external_code' => 'synthetic-price-test-'.$id, 'inventory_code' => $inventory,
                'name' => 'SYNTHETIC PRICE TEST '.$id, 'year' => 2023, 'category_id' => null,
                'type' => $id < 28 ? 'scooter' : 'car', 'is_active' => $id !== 24,
                'is_visible_for_booking' => $id !== 24, 'archived_at' => $id === 24 ? now() : null,
            ]);
            if ($id === 24) {
                continue;
            }
            $sourceId = $id === 32 ? 'LIST-ROW-38' : $inventory;
            $this->mapping['assignments'][$sourceId] = $id;
            $this->reference['vehicles'][] = array_intersect_key($vehicle->getAttributes(), array_flip(['id', 'external_code', 'inventory_code', 'name', 'year', 'type', 'category_id']));
            foreach (PricingSeason::query()->get() as $season) {
                foreach (PricingTier::cases() as $tier) {
                    VehiclePriceTier::query()->create([
                        'vehicle_id' => $id, 'pricing_season_id' => $season->id, 'tier_key' => $tier->value,
                        'min_days' => $tier->minimumDays(), 'max_days' => $tier->maximumDays(), 'anchor_days' => $tier->anchorDays(),
                        'package_total' => 1000, 'daily_rate' => (string) BigDecimal::of(1000)->dividedBy($tier->anchorDays(), 6, RoundingMode::HalfUp),
                        'currency' => 'THB', 'is_active' => true,
                    ]);
                }
            }
        }
        $this->mapping['assignments']['TEMP-ADV-BLUE-2027'] = null;
        $this->mapping['assignments']['TEMP-ADV-YELLOW-2027'] = null;
    }

    public function test_atomic_import_preserves_old_booking_snapshots_unmapped_fleet_and_repeat_is_noop(): void
    {
        $vehicle = Vehicle::query()->findOrFail(15);
        $booking = Booking::factory()->create(['vehicle_id' => $vehicle->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-12-01']);
        // Existing historical row fixture, not a new application booking workflow.
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'pending']);
        $booking->refresh();
        $oldQuote = app(PricingService::class)->quote($vehicle, RentalPeriod::fromStrings('2026-11-02', '2026-12-01'));
        app(BookingPriceSnapshotService::class)->createAutomatic($booking, $oldQuote);
        app(BookingPriceSnapshotService::class)->override($booking, 1234, 'SYNTHETIC HISTORICAL MANUAL PRICE', $this->admin);
        app(BookingPriceSnapshotService::class)->createAutomatic($booking, $oldQuote);
        $this->assertDatabaseCount('booking_price_snapshots', 3);
        $runner = new ReviewedPriceImport;
        $before = $runner->inspect($this->payload, $this->mapping, $this->reference);
        $this->assertSame('ready', $before['state']);
        $unmapped = Vehicle::query()->findOrFail(24)->getAttributes();
        $result = $runner->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before['fingerprint']);
        $this->assertSame('applied', $result['state']);
        $this->assertSame(31, $result['assigned']);
        $this->assertSame(2, $result['skipped']);
        foreach (['bookings', 'booking_price_snapshots', 'pricing_seasons', 'pricing_season_months', 'rental_policies', 'categories', 'vehicle_photos'] as $table) {
            $this->assertSame($before['fingerprint'][$table], $result['fingerprint'][$table], $table);
        }
        $this->assertSame($unmapped, Vehicle::query()->findOrFail(24)->getAttributes());
        $this->assertDatabaseCount('pricing_presets', 20);
        $this->assertDatabaseCount('pricing_preset_prices', 300);
        $this->assertDatabaseCount('vehicle_price_tiers', 465);
        $this->assertSame(51, DB::table('audit_logs')->whereIn('action', ['pricing_preset.deployment_imported', 'vehicle.pricing_preset_deployment_imported'])->count());
        $this->assertDatabaseHas('booking_price_snapshots', ['booking_id' => $booking->id, 'manual_total' => 1234, 'final_total' => 1234]);
        $again = $runner->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before['fingerprint']);
        $this->assertSame('already-applied', $again['state']);
        $this->assertSame($result['fingerprint'], $again['fingerprint']);
        $this->assertSame(51, DB::table('audit_logs')->whereIn('action', ['pricing_preset.deployment_imported', 'vehicle.pricing_preset_deployment_imported'])->count());
        DB::table('vehicle_price_tiers')->where('vehicle_id', 15)->where('tier_key', '21d')->update(['package_total' => 1]);
        $this->expectException(RuntimeException::class);
        $runner->inspect($this->payload, $this->mapping, $this->reference);
    }

    public function test_stale_backup_baseline_fails_before_any_import(): void
    {
        $runner = new ReviewedPriceImport;
        $before = $runner->inspect($this->payload, $this->mapping, $this->reference)['fingerprint'];
        DB::table('vehicles')->where('id', 24)->update(['name' => 'changed after backup']);
        try {
            $runner->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
            $this->fail('Stale baseline must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('baseline drifted', $e->getMessage());
        }
        $this->assertDatabaseCount('pricing_presets', 0);
        $this->assertDatabaseCount('pricing_preset_prices', 0);
    }

    public function test_late_verification_failure_rolls_back_all_presets_prices_and_links(): void
    {
        $runner = new ReviewedPriceImport;
        $before = $runner->inspect($this->payload, $this->mapping, $this->reference)['fingerprint'];
        $this->instance(PricingService::class, new class extends PricingService
        {
            public function quote(Vehicle $vehicle, RentalPeriod $period, bool $requireBookable = true): PriceQuote
            {
                throw new RuntimeException('Injected verification failure');
            }
        });
        try {
            $runner->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
            $this->fail('Late verification must abort the transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected verification failure', $e->getMessage());
        }
        $this->assertSame($before, $runner->fingerprint());
        $this->assertDatabaseCount('pricing_presets', 0);
        $this->assertDatabaseCount('pricing_preset_prices', 0);
    }

    public function test_changed_identity_or_guessed_temp_destination_fails_closed(): void
    {
        $runner = new ReviewedPriceImport;
        $wrong = $this->mapping;
        $wrong['assignments']['TEMP-ADV-BLUE-2027'] = 24;
        try {
            $runner->inspect($this->payload, $wrong, $this->reference);
            $this->fail('TEMP must stay skipped.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mapping required', $e->getMessage());
        }
        DB::table('vehicles')->where('id', 32)->update(['year' => 2024]);
        $this->expectException(RuntimeException::class);
        $runner->inspect($this->payload, $this->mapping, $this->reference);
    }

    public function test_entrypoint_refuses_local_or_preview_paths_before_bootstrap_even_with_go(): void
    {
        $script = dirname(__DIR__, 3).'/ops/pricing-20261031/run.php';
        foreach ([['apply', base_path(), base_path()], ['apply', '/srv/drive-phangan-preview/current/backend', '/tmp/inputs'], ['fingerprint', '/', '/']] as $args) {
            $process = new Process([PHP_BINARY, $script, ...$args], base_path(), [
                'FINAL_PRICES_EXECUTION_GO' => ReviewedPriceImport::SOURCE_SHA,
                'FINAL_PRICES_BACKUP_VERIFIED' => str_repeat('a', 64),
            ]);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertSame('', $process->getOutput());
            $this->assertStringContainsString('operation refused', $process->getErrorOutput());
        }
        $this->assertDatabaseCount('pricing_presets', 0);
    }
}
