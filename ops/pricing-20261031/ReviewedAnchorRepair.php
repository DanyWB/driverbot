<?php

declare(strict_types=1);

use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Services\AdminAuditService;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Repairs an already imported matrix; never creates, imports or reorders presets. */
final class ReviewedAnchorRepair
{
    private const TABLES = ['bookings', 'booking_price_snapshots', 'pricing_seasons', 'pricing_season_months', 'rental_policies', 'categories', 'vehicle_photos', 'vehicles', 'vehicle_price_tiers', 'pricing_presets', 'pricing_preset_prices', 'admin_users', 'audit_logs'];

    /** @param array<string,mixed> $payload
     * @param  array{assignments:array<string,int|null>}  $manifest
     * @param  array<string,mixed>  $reference
     * @return array<string,mixed>
     */
    public function inspect(array $payload, array $manifest, array $reference): array
    {
        $preview = (new ReviewedPriceImport)->input($payload, $manifest, $reference);
        $presets = PricingPreset::query()->get()->keyBy('name');
        if ($presets->count() !== 20 || DB::table('pricing_preset_prices')->count() !== 300 || DB::table('vehicle_price_tiers')->count() !== 465) {
            throw new RuntimeException('Existing pricing population changed.');
        }
        $target = $presets->get('HR-V 2020');
        $vehicle = Vehicle::query()->findOrFail(31);
        if (! $target instanceof PricingPreset || (int) $target->id !== 17 || (int) $target->sort_order !== 17
            || $target->getRawOriginal('vehicle_type') !== null || (int) $vehicle->pricing_preset_id !== 17
            || Vehicle::query()->where('pricing_preset_id', 17)->orderBy('id')->pluck('id')->all() !== [31]) {
            throw new RuntimeException('Exact reviewed repair identities changed.');
        }
        $service = app(PricingPresetService::class);
        $bad = [];
        foreach ($preview['presets'] as $name => $expected) {
            $preset = $presets->get($name);
            if (! $preset instanceof PricingPreset || $this->canonical($preset->calculation_settings) !== $this->canonical($expected['calculation_settings'])) {
                throw new RuntimeException('Original preset settings/name changed.');
            }
            $actual = $service->matrix($preset);
            foreach ($expected['prices'] as $season => $tiers) {
                foreach ($tiers as $tier => $amount) {
                    if ($actual[$season][$tier] !== $amount) {
                        if ($name !== 'HR-V 2020' || $season !== 'low' || $tier !== '21d' || $amount !== 15000 || $actual[$season][$tier] !== 15100) {
                            throw new RuntimeException('Unexpected source preset anchor drift.');
                        }
                        $bad[] = 'preset';
                    }
                }
            }
        }
        foreach ($manifest['assignments'] as $sourceId => $id) {
            if ($id === null) {
                continue;
            }
            $mapped = Vehicle::query()->findOrFail($id);
            $expected = $preview['presets'][$preview['assignments'][$sourceId]];
            $preset = $presets[$expected['name']];
            if ((int) $mapped->pricing_preset_id !== (int) $preset->id || $mapped->pricing_profile !== null
                || $this->canonical($mapped->calculation_settings) !== $this->canonical($expected['calculation_settings'])) {
                throw new RuntimeException('Reviewed source mapping/settings changed.');
            }
            $rows = $mapped->priceTiers()->with('season')->get();
            if ($rows->count() !== 15) {
                throw new RuntimeException('Vehicle anchor population changed.');
            }
            foreach ($rows as $row) {
                $season = $row->season->seasonKey()->value;
                $tier = PricingTier::from($row->getRawOriginal('tier_key'));
                $wanted = $expected['prices'][$season][$tier->value];
                $amount = (int) $row->package_total;
                if ($amount !== $wanted) {
                    if ($id !== 31 || $season !== 'low' || $tier->value !== '21d' || $wanted !== 15000 || $amount !== 15100) {
                        throw new RuntimeException('Unexpected source vehicle anchor drift.');
                    }
                    $bad[] = 'vehicle';
                }
                $daily = (string) BigDecimal::of($amount)->dividedBy($tier->anchorDays(), 6, RoundingMode::HalfUp);
                if (! $row->is_active || $row->currency !== 'THB' || (int) $row->anchor_days !== $tier->anchorDays()
                    || (int) $row->min_days !== $tier->minimumDays() || ($row->max_days === null ? null : (int) $row->max_days) !== $tier->maximumDays()
                    || (string) $row->daily_rate !== $daily || $row->source_sheet !== null || $row->source_row !== null || $row->source_import_run_id !== null) {
                    throw new RuntimeException('Vehicle price metadata changed.');
                }
            }
        }
        if (Vehicle::query()->whereNotNull('pricing_preset_id')->count() !== 31 || Vehicle::query()->whereNotNull('calculation_settings')->count() !== 31) {
            throw new RuntimeException('Unexpected extra links/settings.');
        }
        $presetAnchor = DB::table('pricing_preset_prices')->where('id', 254)->first();
        $vehicleAnchor = DB::table('vehicle_price_tiers')->where('id', 449)->first();
        if ($presetAnchor === null || $vehicleAnchor === null || (int) $presetAnchor->pricing_preset_id !== 17
            || (int) $vehicleAnchor->vehicle_id !== 31 || (int) $presetAnchor->pricing_season_id !== 3 || (int) $vehicleAnchor->pricing_season_id !== 3
            || $presetAnchor->tier_key !== '21d' || $vehicleAnchor->tier_key !== '21d') {
            throw new RuntimeException('Exact repair anchor identities changed.');
        }
        if ($bad !== [] && ($bad !== ['preset', 'vehicle'] || (int) $target->version !== 7 || (int) $vehicle->pricing_version !== 2)) {
            throw new RuntimeException('Mixed repair state or reviewed revision changed.');
        }

        return ['state' => $bad === [] ? 'already-correct' : 'repair-ready', 'fingerprint' => $this->fingerprint()];
    }

    /** @param array<string,mixed> $payload
     * @param  array{assignments:array<string,int|null>}  $manifest
     * @param  array<string,mixed>  $reference
     * @param  array<string,array{count:int,sha256:string}>  $backupBaseline
     * @return array<string,mixed>
     */
    public function apply(array $payload, array $manifest, array $reference, int $adminId, array $backupBaseline): array
    {
        return DB::transaction(function () use ($payload, $manifest, $reference, $adminId, $backupBaseline): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout='5000ms'");
                DB::statement("SET LOCAL statement_timeout='60000ms'");
                DB::statement('LOCK TABLE pricing_presets, pricing_preset_prices, vehicles, vehicle_price_tiers, bookings, booking_price_snapshots, pricing_seasons, pricing_season_months, rental_policies, categories, vehicle_photos, admin_users, audit_logs IN SHARE ROW EXCLUSIVE MODE');
            }
            $inspection = $this->inspect($payload, $manifest, $reference);
            $admin = User::query()->whereKey($adminId)->where('is_active', true)->where('role', 'full_admin')->firstOrFail();
            if ($inspection['state'] === 'already-correct') {
                return $inspection; // Full equality checked; no writes, audit, revision reset or second repair.
            }
            if ($inspection['fingerprint'] !== $backupBaseline) {
                throw new RuntimeException('Verified backup baseline drifted; no repair.');
            }
            $before = $this->rows();
            $preset = PricingPreset::query()->findOrFail(17);
            $vehicle = Vehicle::query()->findOrFail(31);
            $preview = (new ReviewedPriceImport)->input($payload, $manifest, $reference);
            $data = $preview['presets']['HR-V 2020'];
            $updated = app(PricingPresetService::class)->update($preset, [
                'name' => $preset->name, 'vehicle_type' => $preset->getRawOriginal('vehicle_type'),
                'version' => 7, 'expected_linked_vehicles_count' => 1, 'sort_order' => (int) $preset->sort_order,
                'prices' => $data['prices'], 'calculation_settings' => $preset->calculation_settings,
            ], $adminId, 'en');
            if ((int) $updated->version !== 8 || (int) $updated->updated_by_admin_id !== $adminId || (int) $vehicle->fresh()->pricing_version !== 3
                || $this->inspect($payload, $manifest, $reference)['state'] !== 'already-correct') {
                throw new RuntimeException('Repair result/revision mismatch.');
            }
            $after = $this->rows();
            $whitelist = ['pricing_presets' => [17 => ['version', 'updated_by_admin_id', 'updated_at']],
                'vehicles' => [31 => ['pricing_version', 'updated_at']],
                'pricing_preset_prices' => [254 => ['package_total', 'updated_at']],
                'vehicle_price_tiers' => [449 => ['package_total', 'daily_rate', 'updated_at']]];
            foreach (self::TABLES as $table) {
                foreach ($whitelist[$table] ?? [] as $id => $fields) {
                    foreach ($fields as $field) {
                        unset($before[$table][$id][$field], $after[$table][$id][$field]);
                    }
                }
                if ($before[$table] !== $after[$table]) {
                    throw new RuntimeException('Unexpected full-row change; repair rolled back.');
                }
            }
            $sample = app(PricingService::class)->quote(Vehicle::query()->findOrFail(15), RentalPeriod::fromStrings('2026-11-02', '2026-12-01'));
            if ($sample->finalTotal !== 6500 || $sample->totalDays !== 29) {
                throw new RuntimeException('Canonical quote changed.');
            }
            $audit = app(AdminAuditService::class);
            foreach ([$updated, $vehicle->fresh()] as $subject) {
                $audit->record($admin, $subject, 'pricing.deployment_anchor_repaired', ['low:21d' => 15100], [
                    'low:21d' => 15000, 'entry_point' => 'reviewed-cli-deployment', 'source_sha256' => ReviewedPriceImport::SOURCE_SHA,
                    'mapping_sha256' => ReviewedPriceImport::MAPPING_SHA,
                ]);
            }

            return ['state' => 'repaired', 'changed_preset_anchor_id' => 254, 'changed_vehicle_anchor_id' => 449,
                'fingerprint' => $this->fingerprint()];
        });
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function rows(): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->mapWithKeys(fn (object $row): array => [(int) $row->id => (array) $row])->all();
        }

        return $result;
    }

    /** @return array<string,array{count:int,sha256:string}> */
    public function fingerprint(): array
    {
        $result = [];
        foreach ($this->rows() as $table => $rows) {
            $canonical = array_map(static function (array $row): string {
                ksort($row);

                return json_encode($row, JSON_THROW_ON_ERROR);
            }, $rows);
            sort($canonical, SORT_STRING);
            $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
        }

        return $result;
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}
