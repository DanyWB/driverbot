<?php

namespace Tests\Feature;

use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Services\AdminAuditService;
use App\Models\Booking;
use App\Models\PricingPreset;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use ReviewedAnchorRepair;
use RuntimeException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/ops/pricing-20261031/ReviewedAnchorRepair.php';

/** Reuses the same explicitly synthetic isolated Fleet/source fixture. */
class ReviewedAnchorRepairTest extends ReviewedPriceImportTest
{
    protected function afterRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (! app()->environment('testing') || ! preg_match('/test/i', DB::connection()->getDatabaseName()) || DB::transactionLevel() < 1) {
            throw new RuntimeException('Exact-ID repair fixtures require a transacted isolated PostgreSQL test database.');
        }
        // PostgreSQL nextval is not rolled back by RefreshDatabase. This fixture
        // intentionally reproduces reviewed IDs, including low-season ID3.
        // RESTART is transactional (unlike setval); never reset populated tables.
        foreach (['pricing_seasons', 'pricing_presets', 'pricing_preset_prices', 'vehicle_price_tiers'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Exact-ID repair fixture requires empty test pricing tables.');
            }
            DB::statement('ALTER SEQUENCE '.$table.'_id_seq RESTART WITH 1');
        }
    }

    protected function prepareExistingImport(): ReviewedAnchorRepair
    {
        // Reproduce the real UI import, not a deployment apply: PostgreSQL table
        // locks from a nested deployment transaction would survive until this
        // test's outer RefreshDatabase rollback and deadlock the second PDO.
        $import = app(PricingSettingsImport::class);
        $preview = $import->preview($this->payload);
        $preview['preset_revisions'] = [];
        $preview['vehicle_revisions'] = Vehicle::query()->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->id => ['version' => (int) $vehicle->pricing_version, 'preset_id' => null]])->all();
        $targets = [];
        foreach (array_keys($preview['presets']) as $name) {
            if (! is_string($name)) {
                throw new RuntimeException('Named synthetic presets required.');
            }
            $targets[$name] = null;
        }
        $import->commit($preview, $targets, $this->mapping['assignments'], $this->admin->id, 'en');
        app(AdminAuditService::class)->record($this->admin, PricingPreset::query()->findOrFail(17), 'pricing_preset.imported', null, ['fixture' => 'SYNTHETIC EXISTING UI IMPORT']);
        DB::table('pricing_presets')->update(['version' => 7]);
        foreach ([17 => 17, 18 => 19, 19 => 18, 20 => 16] as $id => $position) {
            DB::table('pricing_presets')->where('id', $id)->update(['sort_order' => $position]);
        }
        DB::table('pricing_preset_prices')->where('id', 254)->update(['package_total' => 15100]);
        DB::table('vehicle_price_tiers')->where('id', 449)->update(['package_total' => 15100, 'daily_rate' => '719.047619']);

        return new ReviewedAnchorRepair;
    }

    public function test_one_anchor_repair_preserves_existing_ids_order_settings_other_rows_and_old_manual_snapshots(): void
    {
        $repair = $this->prepareExistingImport();
        $booking = Booking::factory()->create(['vehicle_id' => 31, 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-22']);
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'pending']);
        $booking->refresh();
        $quote = app(PricingService::class)->quote(Vehicle::query()->findOrFail(31), RentalPeriod::fromStrings('2026-07-01', '2026-07-22'));
        app(BookingPriceSnapshotService::class)->createAutomatic($booking, $quote);
        app(BookingPriceSnapshotService::class)->override($booking, 1234, 'SYNTHETIC HISTORICAL MANUAL PRICE', $this->admin);
        app(BookingPriceSnapshotService::class)->createAutomatic($booking, $quote);
        $before = $repair->fingerprint();
        $otherPresets = DB::table('pricing_presets')->where('id', '!=', 17)->orderBy('id')->get()->toJson();
        $otherPrices = DB::table('pricing_preset_prices')->where('id', '!=', 254)->orderBy('id')->get()->toJson();
        $otherVehiclePrices = DB::table('vehicle_price_tiers')->where('id', '!=', 449)->orderBy('id')->get()->toJson();
        $otherVehicles = DB::table('vehicles')->where('id', '!=', 31)->orderBy('id')->get()->toJson();
        $result = $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
        $this->assertSame('repaired', $result['state']);
        foreach (['bookings', 'booking_price_snapshots', 'pricing_seasons', 'pricing_season_months', 'rental_policies', 'categories', 'vehicle_photos', 'admin_users'] as $table) {
            $this->assertSame($before[$table], $result['fingerprint'][$table], $table);
        }
        $this->assertSame($otherPresets, DB::table('pricing_presets')->where('id', '!=', 17)->orderBy('id')->get()->toJson());
        $this->assertSame($otherPrices, DB::table('pricing_preset_prices')->where('id', '!=', 254)->orderBy('id')->get()->toJson());
        $this->assertSame($otherVehiclePrices, DB::table('vehicle_price_tiers')->where('id', '!=', 449)->orderBy('id')->get()->toJson());
        $this->assertSame($otherVehicles, DB::table('vehicles')->where('id', '!=', 31)->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('pricing_presets', ['id' => 17, 'version' => 8, 'sort_order' => 17]);
        $this->assertDatabaseHas('vehicles', ['id' => 31, 'pricing_version' => 3, 'pricing_preset_id' => 17]);
        $this->assertDatabaseHas('pricing_preset_prices', ['id' => 254, 'package_total' => 15000]);
        $this->assertDatabaseHas('vehicle_price_tiers', ['id' => 449, 'package_total' => 15000, 'daily_rate' => '714.285714']);
        $this->assertDatabaseHas('booking_price_snapshots', ['booking_id' => $booking->id, 'manual_total' => 1234, 'final_total' => 1234]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'pricing.deployment_anchor_repaired')->count());
        $again = $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
        $this->assertSame('already-correct', $again['state']);
        $this->assertSame($result['fingerprint'], $again['fingerprint']);
    }

    public function test_repair_refuses_stale_baseline_and_unexpected_or_mixed_anchor_state(): void
    {
        $repair = $this->prepareExistingImport();
        $before = $repair->fingerprint();
        DB::table('vehicles')->where('id', 24)->update(['name' => 'SYNTHETIC changed after backup']);
        try {
            $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
            $this->fail('Stale baseline must refuse.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('baseline drifted', $error->getMessage());
        }
        DB::table('pricing_preset_prices')->where('id', 254)->update(['package_total' => 15000]);
        $this->expectException(RuntimeException::class);
        $repair->inspect($this->payload, $this->mapping, $this->reference);
    }

    public function test_repair_refuses_other_anchor_revision_or_extra_link(): void
    {
        $repair = $this->prepareExistingImport();
        DB::table('pricing_presets')->where('id', 17)->update(['version' => 8]);
        $this->expectException(RuntimeException::class);
        $repair->inspect($this->payload, $this->mapping, $this->reference);
    }

    public function test_other_anchor_extra_link_and_inactive_audit_actor_are_refused(): void
    {
        $repair = $this->prepareExistingImport();
        DB::table('pricing_preset_prices')->where('id', 1)->update(['package_total' => 1]);
        try {
            $repair->inspect($this->payload, $this->mapping, $this->reference);
            $this->fail('Unrelated anchor must refuse.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Unexpected source preset anchor', $error->getMessage());
        }
        $original = app(PricingSettingsImport::class)->preview($this->payload)['presets']['Scoopy - 2026']['prices']['high']['1d'];
        DB::table('pricing_preset_prices')->where('id', 1)->update(['package_total' => $original]);
        $previous = DB::table('vehicles')->where('id', 1)->value('pricing_preset_id');
        DB::table('vehicles')->where('id', 1)->update(['pricing_preset_id' => 17]);
        try {
            $repair->inspect($this->payload, $this->mapping, $this->reference);
            $this->fail('Extra target link must refuse.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('repair identities changed', $error->getMessage());
        }
        DB::table('vehicles')->where('id', 1)->update(['pricing_preset_id' => $previous]);
        $this->admin->forceFill(['is_active' => false])->save();
        $before = $repair->fingerprint();
        try {
            $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
            $this->fail('Inactive audit actor must refuse.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $repair->fingerprint());
        }
    }

    public function test_repair_entrypoint_refuses_local_paths_and_wrapper_never_calls_full_import(): void
    {
        $ops = dirname(__DIR__, 3).'/ops/pricing-20261031';
        $process = new Process([PHP_BINARY, $ops.'/repair.php', 'apply', base_path(), base_path()], base_path());
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('operation refused', $process->getErrorOutput());
        $wrapper = file_get_contents($ops.'/release.sh');
        $this->assertIsString($wrapper);
        $this->assertStringNotContainsString('/run.php" apply', $wrapper);
        $this->assertStringContainsString('/repair.php" apply', $wrapper);
    }

    public function test_late_quote_failure_rolls_back_both_rows_and_revisions(): void
    {
        $repair = $this->prepareExistingImport();
        $before = $repair->fingerprint();
        $this->instance(PricingService::class, new class extends PricingService
        {
            public function quote(Vehicle $vehicle, RentalPeriod $period, bool $requireBookable = true): PriceQuote
            {
                throw new RuntimeException('Injected late repair failure');
            }
        });
        try {
            $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
            $this->fail('Late repair failure must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected late repair failure', $error->getMessage());
        }
        $this->assertSame($before, $repair->fingerprint());
    }

    public function test_full_row_whitelist_rejects_unexpected_service_normalization(): void
    {
        $repair = $this->prepareExistingImport();
        $before = $repair->fingerprint();
        $this->instance(PricingPresetService::class, new class extends PricingPresetService
        {
            public function update(PricingPreset $preset, array $data, int $adminId, string $locale): PricingPreset
            {
                $result = parent::update($preset, $data, $adminId, $locale);
                DB::table('pricing_preset_prices')->where('id', 1)->update(['updated_at' => '2001-01-01 00:00:00']);

                return $result;
            }
        });
        try {
            $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
            $this->fail('Unrelated row metadata must roll back.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Unexpected full-row change', $error->getMessage());
        }
        $this->assertSame($before, $repair->fingerprint());
    }
}
