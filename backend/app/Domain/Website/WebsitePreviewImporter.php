<?php

namespace App\Domain\Website;

use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class WebsitePreviewImporter
{
    public function __construct(private readonly WebsitePreviewGuard $guard) {}

    /** @return array<string, int|bool> */
    public function import(): array
    {
        $this->guard->assertDatabaseIdentity();
        $inputs = '/srv/drive-phangan-preview/shared/private/inputs/'.config('website.preview.instance_id');
        $catalog = app(PublicCatalogSnapshot::class)->validate($this->input($inputs.'/catalog-snapshot.json', 'snapshot_sha256'));
        $source = $this->input($inputs.'/pricing-source.json', 'pricing_source_sha256');
        $manifest = $this->input($inputs.'/mapping.json', 'mapping_sha256');
        $media = $this->input($inputs.'/optimized-manifest.json', 'media_manifest_sha256');
        $reviewed = app(ReviewedWebsitePricing::class)->validate($catalog, $source, $manifest);
        $photoRows = $this->photos($catalog, $media);
        if (DB::table('website_preview_metadata')->exists()) {
            $this->guard->verifyRuntime();
            $this->verifyPricing($catalog, $reviewed);
            $this->verifyStoredPhotos($photoRows);

            return ['created' => false, 'vehicles' => Vehicle::query()->count(), 'presets' => PricingPreset::query()->count(), 'photos' => count($photoRows)];
        }
        foreach (['vehicles', 'categories', 'pricing_seasons', 'vehicle_photos', 'vehicle_price_tiers', 'pricing_presets', 'customers', 'customer_contacts', 'customer_identities', 'bookings', 'admin_users', 'admin_telegram_bindings', 'service_api_clients', 'notification_outbox', 'jobs'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preview import requires a new empty migrated database.');
            }
        }

        return DB::transaction(function () use ($catalog, $reviewed, $photoRows): array {
            $now = now()->toDateTimeString();
            foreach ($catalog['categories'] as $row) {
                DB::table('categories')->insert([...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach ($catalog['seasons'] as $row) {
                DB::table('pricing_seasons')->insert([...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('pricing_season_months')->insert($catalog['months']);
            foreach ($catalog['vehicles'] as $row) {
                $row['calculation_settings'] = $row['calculation_settings'] === null ? null : json_encode($row['calculation_settings'], JSON_THROW_ON_ERROR);
                DB::table('vehicles')->insert([...$row, 'is_active' => true, 'is_visible_for_booking' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach ($catalog['prices'] as $row) {
                DB::table('vehicle_price_tiers')->insert([...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach ($photoRows as $row) {
                DB::table('vehicle_photos')->insert([...$row, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('rental_policies')->insert(['id' => 1, 'minimum_days' => $catalog['rental_policy']['minimum_days'],
                'category_minimum_days' => json_encode($catalog['rental_policy']['category_minimum_days'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            // Explicit source IDs need sequence alignment before future test writes.
            foreach (['categories', 'pricing_seasons', 'pricing_season_months', 'vehicles', 'vehicle_photos', 'vehicle_price_tiers'] as $table) {
                DB::select("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST(COALESCE(MAX(id), 1), 1), MAX(id) IS NOT NULL) FROM {$table}");
            }
            $author = User::query()->create(['name' => 'PREVIEW pricing metadata - inactive', 'email' => 'preview-pricing@example.invalid',
                'password' => Hash::make(bin2hex(random_bytes(32))), 'role' => 'full_admin', 'is_active' => false]);
            $preview = $reviewed['preview'];
            $preview['vehicle_revisions'] = Vehicle::query()->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->id => ['preset_id' => null, 'version' => (int) $vehicle->pricing_version]])->all();
            $preview['preset_revisions'] = [];
            $presetTargets = [];
            foreach (array_keys($preview['presets']) as $name) {
                if (! is_string($name)) {
                    throw new RuntimeException('Reviewed preset names must be nonnumeric strings.');
                }
                $presetTargets[$name] = null;
            }
            $result = app(PricingSettingsImport::class)->commit($preview, $presetTargets, $reviewed['mapping'], $author->id, 'en');
            if ($result['assigned'] !== 31 || $result['skipped'] !== 2 || PricingPreset::query()->count() !== 20
                || DB::table('pricing_preset_prices')->count() !== 300 || DB::table('vehicle_price_tiers')->count() !== 465) {
                throw new RuntimeException('Authoritative preview import counts differ.');
            }
            $this->verifyPricing($catalog, $reviewed);
            $this->verifyStoredPhotos($photoRows);
            DB::table('website_preview_metadata')->insert([
                'instance_id' => config('website.preview.instance_id'), 'marker' => 'WEBSITE PREVIEW ONLY - NO PRODUCTION WRITES',
                'snapshot_sha256' => config('website.preview.snapshot_sha256'), 'pricing_source_sha256' => config('website.preview.pricing_source_sha256'),
                'mapping_sha256' => config('website.preview.mapping_sha256'), 'vehicle_count' => 31, 'photo_count' => count($photoRows), 'created_at' => $now, 'updated_at' => $now,
                'media_manifest_sha256' => config('website.preview.media_manifest_sha256'),
            ]);

            return ['created' => true, 'vehicles' => 31, 'presets' => 20, 'assigned' => 31, 'skipped' => 2, 'photos' => count($photoRows), 'customers' => 0, 'bookings' => 0];
        });
    }

    /** Full read-only validation on repeated import and explicit deployment check.
     * @return array<string, int|bool>
     */
    public function verifyExisting(): array
    {
        $this->guard->verifyRuntime();
        $inputs = '/srv/drive-phangan-preview/shared/private/inputs/'.config('website.preview.instance_id');
        $catalog = app(PublicCatalogSnapshot::class)->validate($this->input($inputs.'/catalog-snapshot.json', 'snapshot_sha256'));
        $source = $this->input($inputs.'/pricing-source.json', 'pricing_source_sha256');
        $manifest = $this->input($inputs.'/mapping.json', 'mapping_sha256');
        $media = $this->input($inputs.'/optimized-manifest.json', 'media_manifest_sha256');
        $reviewed = app(ReviewedWebsitePricing::class)->validate($catalog, $source, $manifest);
        $this->verifyPricing($catalog, $reviewed);
        $photos = $this->photos($catalog, $media);
        $this->verifyStoredPhotos($photos);

        return ['verified' => true, 'vehicles' => 31, 'presets' => 20, 'preset_anchors' => 300, 'vehicle_anchors' => 465, 'photos' => count($photos)];
    }

    /** @param array<string,mixed> $catalog
     * @param  array{preview:array<string,mixed>,mapping:array<string,int|null>}  $reviewed
     */
    private function verifyPricing(array $catalog, array $reviewed): void
    {
        $presets = PricingPreset::query()->with('prices.season')->get()->keyBy('name');
        $vehicles = Vehicle::query()->with(['pricingPreset', 'priceTiers.season'])->get()->keyBy('id');
        $expectedVehicles = array_column($catalog['vehicles'], null, 'id');
        if ($presets->count() !== 20 || $vehicles->count() !== 31 || DB::table('pricing_preset_prices')->count() !== 300 || DB::table('vehicle_price_tiers')->count() !== 465) {
            throw new RuntimeException('Preview stored pricing counts differ.');
        }
        foreach ($reviewed['preview']['presets'] as $name => $expected) {
            $preset = $presets->get($name);
            if (! $preset instanceof PricingPreset || $preset->calculation_settings != $expected['calculation_settings'] || app(PricingPresetService::class)->matrix($preset) != $expected['prices']) {
                throw new RuntimeException('Preview stored preset anchors/settings differ.');
            }
        }
        foreach ($reviewed['mapping'] as $sourceId => $id) {
            if ($id === null) {
                continue;
            }
            $vehicle = $vehicles->get($id);
            $expected = $reviewed['preview']['presets'][$reviewed['preview']['assignments'][$sourceId]];
            if (! $vehicle instanceof Vehicle || $vehicle->pricingPreset?->name !== $expected['name'] || $vehicle->calculation_settings != $expected['calculation_settings']
                || ! $vehicle->is_active || ! $vehicle->is_visible_for_booking || $vehicle->archived_at !== null) {
                throw new RuntimeException('Preview stored vehicle link/settings differ.');
            }
            foreach ($expectedVehicles[$id] as $field => $value) {
                if ($field === 'calculation_settings') {
                    continue;
                }
                $actual = $vehicle->getRawOriginal($field);
                $matches = match (true) {
                    $value === null => $actual === null,
                    is_int($value) => is_numeric($actual) && (int) $actual === $value,
                    is_bool($value) => (bool) $actual === $value,
                    default => $actual === $value,
                };
                if (! $matches) {
                    throw new RuntimeException('Preview vehicle identity/public metadata differs.');
                }
            }
            $matrix = [];
            foreach ($vehicle->priceTiers as $price) {
                if (! $price->is_active) {
                    throw new RuntimeException('Preview contains inactive materialized prices.');
                }
                $tier = PricingTier::from($price->getRawOriginal('tier_key'));
                $daily = (string) BigDecimal::of($price->package_total)->dividedBy($tier->anchorDays(), 6, RoundingMode::HalfUp);
                if ($price->currency !== 'THB' || (int) $price->anchor_days !== $tier->anchorDays() || (int) $price->min_days !== $tier->minimumDays()
                    || ($price->max_days === null ? null : (int) $price->max_days) !== $tier->maximumDays() || (string) $price->daily_rate !== $daily) {
                    throw new RuntimeException('Preview materialized price metadata differs.');
                }
                $matrix[$price->season->getRawOriginal('key')][$price->getRawOriginal('tier_key')] = (int) $price->package_total;
            }
            if ($matrix != $expected['prices']) {
                throw new RuntimeException('Preview materialized vehicle anchors differ.');
            }
        }
    }

    /** @param list<array<string,mixed>> $expected */
    private function verifyStoredPhotos(array $expected): void
    {
        $stored = DB::table('vehicle_photos')->get()->keyBy('id');
        if ($stored->count() !== count($expected)) {
            throw new RuntimeException('Preview photo row counts differ.');
        }
        foreach ($expected as $photo) {
            $actual = $stored->get($photo['id']);
            if ($actual === null) {
                throw new RuntimeException('Preview photo row is missing.');
            }
            foreach ($photo as $field => $value) {
                if ($actual->{$field} != $value || ($value === null && $actual->{$field} !== null)) {
                    throw new RuntimeException('Preview photo association/public metadata differs.');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function input(string $file, string $hashKey): array
    {
        $resolved = realpath($file);
        $actualHash = is_file($file) ? hash_file('sha256', $file) : false;
        if ($resolved !== $file || is_link($file) || ! is_file($file) || filesize($file) > 2000000
            || ! is_string($actualHash) || ! hash_equals(config('website.preview.'.$hashKey), $actualHash)) {
            throw new RuntimeException('Preview input path, size or hash differs from the reviewed manifest.');
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Preview input could not be read.');
        }
        $value = json_decode($contents, true, 40, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('Preview input is not an object.');
        }

        return $value;
    }

    /** @param array<string,mixed> $catalog
     * @param  array<string,mixed>  $media
     * @return list<array<string,mixed>>
     */
    private function photos(array $catalog, array $media): array
    {
        if (($media['snapshot_sha256'] ?? null) !== config('website.preview.snapshot_sha256') || ! is_array($media['photos'] ?? null)
            || count($media['photos']) !== count($catalog['photos']) || ($media['total_optimized_bytes'] ?? PHP_INT_MAX) > 25000000) {
            throw new RuntimeException('Preview media provenance or budget is invalid.');
        }
        $byId = array_column($media['photos'], null, 'photo_id');
        $rows = [];
        $total = 0;
        foreach ($catalog['photos'] as $photo) {
            $proof = $byId[$photo['id']] ?? null;
            if (! is_array($proof) || $proof['vehicle_id'] !== $photo['vehicle_id'] || $proof['source_path'] !== $photo['file_path']
                || ! is_string($proof['optimized_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $proof['optimized_sha256'])) {
                throw new RuntimeException('Preview media association is invalid.');
            }
            $path = 'vehicles/'.$photo['vehicle_id'].'/'.$proof['optimized_sha256'].'.webp';
            $file = storage_path('app/public/'.$path);
            $actualHash = is_file($file) ? hash_file('sha256', $file) : false;
            if (realpath($file) !== $file || is_link($file) || ! is_file($file) || filesize($file) !== $proof['optimized_bytes']
                || ! is_string($actualHash) || ! hash_equals($proof['optimized_sha256'], $actualHash)) {
                throw new RuntimeException('Preview media content hash is invalid.');
            }
            $size = getimagesize($file);
            if ($size === false || $size['mime'] !== 'image/webp' || $size[0] > 1400 || $size[1] > 1000) {
                throw new RuntimeException('Preview media dimensions/type are invalid.');
            }
            $total += filesize($file);
            $rows[] = [...$photo, 'file_path' => $path];
        }
        if ($total > 25000000 || $total !== $media['total_optimized_bytes']) {
            throw new RuntimeException('Preview media total differs.');
        }

        return $rows;
    }
}
