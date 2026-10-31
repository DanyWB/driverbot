<?php

declare(strict_types=1);

use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\Services\PricingSettings;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Services\AdminAuditService;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** One reviewed operation, deliberately not registered as a public command/API. */
final class ReviewedPriceImport
{
    public const SOURCE_SHA = '304a31270256c2d36e08b3c90128dc91bca31b215ea4750df2bc9b0b4707bfc2';

    public const MAPPING_SHA = '02791d6c6b9422079eade97db90ff3c32f7963594e4b18180fcc48b48c0291b3';

    public const SNAPSHOT_SHA = 'cc6a931d8d24e7e667bbde7d948dd9748a6a86bb75b2087cb9b0a9e671922304';

    private const PRESERVED = ['bookings', 'booking_price_snapshots', 'pricing_seasons', 'pricing_season_months', 'rental_policies', 'categories', 'vehicle_photos'];

    /** @param array<string,mixed> $decoded
     * @return array{assignments:array<string,int|null>}
     */
    public static function mapping(array $decoded): array
    {
        if (! is_array($decoded['assignments'] ?? null)) {
            throw new RuntimeException('Mapping assignments missing.');
        }
        $assignments = [];
        foreach ($decoded['assignments'] as $key => $value) {
            if (! is_string($key) || ($value !== null && ! is_int($value))) {
                throw new RuntimeException('Mapping key/destination type invalid.');
            }
            $assignments[$key] = $value;
        }

        return ['assignments' => $assignments];
    }

    /** Hash raw rows in memory, never return historical row values/PII.
     * @return array<string,array{count:int,sha256:string}>
     */
    public function fingerprint(): array
    {
        $result = [];
        foreach ([...self::PRESERVED, 'vehicles', 'vehicle_price_tiers', 'pricing_presets', 'pricing_preset_prices'] as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
            $canonical = array_map(function (array $row): string {
                ksort($row);

                return json_encode($row, JSON_THROW_ON_ERROR);
            }, $rows);
            sort($canonical, SORT_STRING);
            $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
        }

        return $result;
    }

    /** @param array<string,mixed> $payload
     * @param  array{assignments:array<string,int|null>}  $manifest
     * @param  array<string,mixed>  $reference
     * @return array{presets:array<string,array<string,mixed>>,assignments:array<string,string>}
     */
    private function input(array $payload, array $manifest, array $reference): array
    {
        $preview = app(PricingSettingsImport::class)->preview($payload);
        $mapping = $manifest['assignments'];
        $keys = array_keys($mapping);
        $sourceKeys = array_keys($preview['assignments']);
        sort($keys);
        sort($sourceKeys);
        $ids = array_values(array_filter($mapping, fn ($id): bool => $id !== null));
        sort($ids);
        $expected = array_values(array_diff(range(1, 32), [24]));
        if (count($preview['presets']) !== 20 || count($mapping) !== 33 || $keys !== $sourceKeys || $ids !== $expected
            || ($mapping['LIST-ROW-38'] ?? null) !== 32
            || ! array_key_exists('TEMP-ADV-BLUE-2027', $mapping) || $mapping['TEMP-ADV-BLUE-2027'] !== null
            || ! array_key_exists('TEMP-ADV-YELLOW-2027', $mapping) || $mapping['TEMP-ADV-YELLOW-2027'] !== null) {
            throw new RuntimeException('Exact reviewed 33/31/2 mapping required.');
        }
        $references = array_column($reference['vehicles'] ?? [], null, 'id');
        $fleetIds = Vehicle::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $unmapped = Vehicle::query()->findOrFail(24);
        if (count($references) !== 31 || $fleetIds !== range(1, 32) || $unmapped->inventory_code !== 'LIST-24'
            || $unmapped->is_active || $unmapped->is_visible_for_booking || $unmapped->archived_at === null) {
            throw new RuntimeException('Fleet population changed; obtain a new review.');
        }
        foreach ($mapping as $sourceId => $id) {
            if ($id === null) {
                continue;
            }
            $vehicle = Vehicle::query()->findOrFail($id);
            $row = $references[$id] ?? [];
            foreach (['external_code', 'inventory_code', 'name', 'year', 'type', 'category_id'] as $field) {
                if ((string) $vehicle->getRawOriginal($field) !== (string) ($row[$field] ?? null)) {
                    throw new RuntimeException('Mapped public identity changed.');
                }
            }
            $inventory = $sourceId === 'LIST-ROW-38' ? 'LIST-ROW-36' : $sourceId;
            if ($vehicle->inventory_code !== $inventory || ! $vehicle->is_active || ! $vehicle->is_visible_for_booking || $vehicle->archived_at !== null) {
                throw new RuntimeException('Mapped inventory/publication changed.');
            }
        }
        foreach ($preview['presets'] as $data) {
            if (app(PricingSettings::class)->storedPreset($data)['prices'] !== $data['prices']) {
                throw new RuntimeException('Persistence would alter a source anchor; rounding fix required.');
            }
        }

        return ['presets' => $preview['presets'], 'assignments' => $preview['assignments']];
    }

    /** @param array<string,mixed> $payload
     * @param  array{assignments:array<string,int|null>}  $manifest
     * @param  array<string,mixed>  $reference
     * @return array<string,mixed>
     */
    public function inspect(array $payload, array $manifest, array $reference): array
    {
        $preview = $this->input($payload, $manifest, $reference);
        $count = PricingPreset::query()->count();
        if ($count !== 0) {
            $this->verify($preview, $manifest['assignments']);

            return ['state' => 'already-applied', 'fingerprint' => $this->fingerprint()];
        }
        if (DB::table('pricing_preset_prices')->count() !== 0
            || Vehicle::query()->whereNotNull('pricing_preset_id')->exists()
            || Vehicle::query()->whereNotNull('calculation_settings')->exists()
            || Vehicle::query()->where('pricing_version', '!=', 1)->exists()
            || DB::table('vehicle_price_tiers')->count() !== 465) {
            throw new RuntimeException('Unexpected pricing state; do not overwrite or auto-match.');
        }

        return ['state' => 'ready', 'fingerprint' => $this->fingerprint(), 'proposed' => ['presets' => 20, 'preset_anchors' => 300, 'assignments' => 31, 'skips' => 2, 'vehicle_anchors' => 465]];
    }

    /** Caller must hold deploy+backup locks and quiesce writers; baseline is the verified backup baseline.
     * @param  array<string,mixed>  $payload
     * @param  array{assignments:array<string,int|null>}  $manifest
     * @param  array<string,mixed>  $reference
     * @param  array<string,array{count:int,sha256:string}>  $backupBaseline
     * @return array<string,mixed>
     */
    public function apply(array $payload, array $manifest, array $reference, int $adminId, array $backupBaseline): array
    {
        return DB::transaction(function () use ($payload, $manifest, $reference, $adminId, $backupBaseline): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5000ms'");
                DB::statement("SET LOCAL statement_timeout = '60000ms'");
                DB::statement('LOCK TABLE pricing_presets, pricing_preset_prices, vehicles, vehicle_price_tiers, bookings, booking_price_snapshots, pricing_seasons, pricing_season_months, rental_policies, categories, vehicle_photos IN SHARE ROW EXCLUSIVE MODE');
            }
            $inspection = $this->inspect($payload, $manifest, $reference);
            if ($inspection['state'] === 'already-applied') {
                return ['state' => 'already-applied', 'fingerprint' => $inspection['fingerprint']];
            }
            if ($inspection['fingerprint'] !== $backupBaseline) {
                throw new RuntimeException('Verified backup baseline drifted; no import.');
            }
            $admin = User::query()->whereKey($adminId)->where('is_active', true)->firstOrFail();
            $preview = $this->input($payload, $manifest, $reference);
            $preview['preset_revisions'] = [];
            $preview['vehicle_revisions'] = Vehicle::query()->get()->mapWithKeys(fn (Vehicle $v): array => [$v->id => ['version' => (int) $v->pricing_version, 'preset_id' => null]])->all();
            $untouchedVehicle = DB::table('vehicles')->where('id', 24)->first();
            $untouchedPrices = DB::table('vehicle_price_tiers')->where('vehicle_id', 24)->orderBy('id')->get()->toJson();
            $identities = $this->vehicleIdentities();
            $result = app(PricingSettingsImport::class)->commit($preview, array_fill_keys(array_keys($preview['presets']), null), $manifest['assignments'], $adminId, 'en');
            $this->verify($preview, $manifest['assignments']);
            $after = $this->fingerprint();
            foreach (self::PRESERVED as $table) {
                if ($after[$table] !== $backupBaseline[$table]) {
                    throw new RuntimeException('Historical booking/calendar/policy changed; rolling back.');
                }
            }
            if ($untouchedVehicle != DB::table('vehicles')->where('id', 24)->first()
                || $untouchedPrices !== DB::table('vehicle_price_tiers')->where('vehicle_id', 24)->orderBy('id')->get()->toJson()
                || $identities !== $this->vehicleIdentities()) {
                throw new RuntimeException('Unmapped vehicle/prices changed; rolling back.');
            }
            $audit = app(AdminAuditService::class);
            foreach ($result['presets'] as $preset) {
                $audit->record($admin, $preset, 'pricing_preset.deployment_imported', null, ['release' => 'final-prices-20261031', 'entry_point' => 'reviewed-cli-deployment', 'source_sha256' => self::SOURCE_SHA, 'mapping_sha256' => self::MAPPING_SHA]);
            }
            foreach ($result['assignment_changes'] as $change) {
                $audit->record($admin, $change['vehicle'], 'vehicle.pricing_preset_deployment_imported', ['preset_id' => null], ['preset_id' => $change['new_preset_id'], 'entry_point' => 'reviewed-cli-deployment', 'source_sha256' => self::SOURCE_SHA]);
            }

            return ['state' => 'applied', 'presets' => 20, 'assigned' => $result['assigned'], 'skipped' => $result['skipped'], 'fingerprint' => $after];
        });
    }

    /** @param array{presets:array<string,array<string,mixed>>,assignments:array<string,string>} $preview
     * @param  array<string,int|null>  $mapping
     */
    private function verify(array $preview, array $mapping): void
    {
        $presets = PricingPreset::query()->get()->keyBy('name');
        if ($presets->count() !== 20 || DB::table('pricing_preset_prices')->count() !== 300 || DB::table('vehicle_price_tiers')->count() !== 465) {
            throw new RuntimeException('Final pricing cardinality mismatch.');
        }
        $service = app(PricingPresetService::class);
        $position = 0;
        foreach ($preview['presets'] as $name => $data) {
            $preset = $presets->get($name);
            if (! $preset instanceof PricingPreset || $service->matrix($preset) !== $data['prices'] || $preset->calculation_settings !== $data['calculation_settings']
                || (int) $preset->version !== 1 || (int) $preset->sort_order !== $position++ || $preset->getRawOriginal('vehicle_type') !== null) {
                throw new RuntimeException('Final source preset anchors/rules/calendar mismatch.');
            }
        }
        foreach ($mapping as $sourceId => $id) {
            if ($id === null) {
                continue;
            }
            $vehicle = Vehicle::query()->findOrFail($id);
            $preset = $presets[$preview['assignments'][$sourceId]];
            if ((int) $vehicle->pricing_preset_id !== (int) $preset->id || $vehicle->calculation_settings !== $preset->calculation_settings
                || (int) $vehicle->pricing_version !== 2 || $vehicle->pricing_profile !== null) {
                throw new RuntimeException('Final vehicle assignment/settings mismatch.');
            }
            $rows = $vehicle->priceTiers()->with('season')->get();
            if ($rows->count() !== 15) {
                throw new RuntimeException('Vehicle anchors incomplete.');
            }
            foreach ($rows as $row) {
                $season = $row->season->seasonKey()->value;
                $tier = PricingTier::from($row->getRawOriginal('tier_key'));
                $total = $preview['presets'][$preset->name]['prices'][$season][$tier->value];
                $daily = (string) BigDecimal::of($total)->dividedBy($tier->anchorDays(), 6, RoundingMode::HalfUp);
                if (! $row->is_active || $row->currency !== 'THB' || (int) $row->package_total !== $total
                    || (int) $row->anchor_days !== $tier->anchorDays() || (int) $row->min_days !== $tier->minimumDays()
                    || ($row->max_days === null ? null : (int) $row->max_days) !== $tier->maximumDays()
                    || (string) $row->daily_rate !== $daily || $row->source_sheet !== null || $row->source_row !== null || $row->source_import_run_id !== null) {
                    throw new RuntimeException('Final vehicle anchor/currency mismatch.');
                }
            }
        }
        if (Vehicle::query()->whereNotNull('pricing_preset_id')->count() !== 31 || Vehicle::query()->whereNotNull('calculation_settings')->count() !== 31) {
            throw new RuntimeException('Unexpected extra vehicle links/settings.');
        }
        $sample = $presets['PCX/ADV 160 - 2023'];
        if (app(PricingCalculator::class)->quote($service->matrix($sample), $sample->calculation_settings, days: 29, season: 'middle')['total'] !== 6500) {
            throw new RuntimeException('Canonical final quote mismatch.');
        }
        $quote = app(PricingService::class)->quote(Vehicle::query()->findOrFail(15), RentalPeriod::fromStrings('2026-11-02', '2026-12-01'));
        if ($quote->finalTotal !== 6500 || $quote->totalDays !== 29) {
            throw new RuntimeException('Canonical dated vehicle quote mismatch.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function vehicleIdentities(): array
    {
        return DB::table('vehicles')->orderBy('id')->get()->map(function (object $row): array {
            $data = (array) $row;
            foreach (['pricing_preset_id', 'calculation_settings', 'pricing_profile', 'pricing_version', 'updated_at'] as $field) {
                unset($data[$field]);
            }

            return $data;
        })->all();
    }
}
