<?php

namespace App\Domain\Website;

use App\Domain\Pricing\Services\PricingSettingsImport;
use InvalidArgumentException;

class ReviewedWebsitePricing
{
    /** @param array<string, mixed> $catalog
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $manifest
     * @return array{preview:array<string,mixed>,mapping:array<string,int|null>}
     */
    public function validate(array $catalog, array $source, array $manifest): array
    {
        if (($manifest['format'] ?? null) !== 'REVIEWED-LOCAL-FINAL-PRICE-MAPPING-1'
            || ($manifest['approval'] ?? null) !== 'root-2026-10-31-31-local-only'
            || ($manifest['source_snapshot_sha256'] ?? null) !== config('website.preview.snapshot_sha256')
            || ($manifest['pricing_source_sha256'] ?? null) !== config('website.preview.pricing_source_sha256')
            || ($manifest['media_manifest_sha256'] ?? null) !== config('website.preview.media_manifest_sha256')
            || ! is_array($manifest['assignments'] ?? null)) {
            throw new InvalidArgumentException('Reviewed pricing provenance is invalid.');
        }
        $preview = app(PricingSettingsImport::class)->preview($source);
        $mapping = $manifest['assignments'];
        $vehicles = array_column($catalog['vehicles'], null, 'id');
        $targets = [];
        if (count($preview['presets']) !== 20 || count($preview['assignments']) !== 33 || count($mapping) !== 33
            || array_diff(array_keys($mapping), array_keys($preview['assignments'])) !== []
            || array_diff(array_keys($preview['assignments']), array_keys($mapping)) !== []) {
            throw new InvalidArgumentException('Reviewed pricing preset/mapping bounds are invalid.');
        }
        foreach ($mapping as $sourceId => $id) {
            if (str_starts_with($sourceId, 'TEMP-')) {
                if ($id !== null) {
                    throw new InvalidArgumentException('Temporary source vehicles must stay skipped.');
                }

                continue;
            }
            if (! is_int($id) || ! isset($vehicles[$id]) || isset($targets[$id])) {
                throw new InvalidArgumentException('Reviewed pricing destination is missing or duplicated.');
            }
            $vehicle = $vehicles[$id];
            if ($vehicle['inventory_code'] !== $sourceId) {
                $alias = $manifest['approved_aliases'][$sourceId] ?? null;
                if (! is_array($alias) || ($alias['vehicle_id'] ?? null) !== $id || ($alias['inventory_code'] ?? null) !== $vehicle['inventory_code']
                    || ($alias['external_code'] ?? null) !== $vehicle['external_code'] || ($alias['name'] ?? null) !== $vehicle['name']
                    || ($alias['year'] ?? null) !== $vehicle['year'] || ($alias['preset'] ?? null) !== $preview['assignments'][$sourceId]) {
                    throw new InvalidArgumentException('Unreviewed inventory alias is forbidden.');
                }
            }
            if (isset($manifest['reviewed_year_label_differences'][$sourceId])
                && ($manifest['reviewed_year_label_differences'][$sourceId]['vehicle_year'] !== $vehicle['year']
                    || $manifest['reviewed_year_label_differences'][$sourceId]['preset'] !== $preview['assignments'][$sourceId])) {
                throw new InvalidArgumentException('Reviewed vehicle metadata differs; do not change its year.');
            }
            $targets[$id] = true;
        }
        if (count($targets) !== 31 || count($targets) !== count($vehicles)) {
            throw new InvalidArgumentException('Reviewed mapping does not cover the exact public Fleet.');
        }

        return ['preview' => $preview, 'mapping' => $mapping];
    }
}
