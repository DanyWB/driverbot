<?php

namespace App\Domain\Website;

use App\Domain\Pricing\Services\PricingSettings;
use InvalidArgumentException;

/** Exact public-business whitelist; no model serialization or customer tables. */
class PublicCatalogSnapshot
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $top = ['format', 'exported_at', 'source_origin', 'transaction_read_only', 'vehicles', 'categories', 'photos', 'seasons', 'months', 'prices', 'rental_policy'];
        if (array_diff(array_keys($payload), $top) !== [] || array_diff($top, array_keys($payload)) !== []
            || $payload['format'] !== 'PUBLIC-CATALOG-PREVIEW-1' || $payload['source_origin'] !== 'https://admin.drivephangan.com'
            || $payload['transaction_read_only'] !== true || ! is_string($payload['exported_at'])) {
            throw new InvalidArgumentException('Public snapshot provenance or top-level whitelist is invalid.');
        }
        $fields = [
            'vehicles' => ['id', 'external_code', 'inventory_code', 'type', 'category_id', 'name', 'year', 'description', 'description_ru', 'description_ua', 'brand', 'model', 'engine_cc', 'color', 'abs', 'seats', 'transmission', 'sort_order', 'calculation_settings'],
            'categories' => ['id', 'code', 'name', 'vehicle_type', 'sort_order'],
            'photos' => ['id', 'vehicle_id', 'disk', 'file_path', 'alt_text', 'sort_order', 'is_primary'],
            'seasons' => ['id', 'key', 'name', 'sort_order'], 'months' => ['pricing_season_id', 'month'],
            'prices' => ['vehicle_id', 'pricing_season_id', 'tier_key', 'min_days', 'max_days', 'anchor_days', 'package_total', 'daily_rate', 'currency'],
        ];
        foreach ($fields as $table => $columns) {
            if (! is_array($payload[$table]) || ! array_is_list($payload[$table]) || count($payload[$table]) > match ($table) {
                'photos' => 500, 'prices' => 1500, 'months' => 12, 'seasons' => 3, default => 100
            }) {
                throw new InvalidArgumentException('Public snapshot row bounds are invalid.');
            }
            foreach ($payload[$table] as $row) {
                if (! is_array($row) || array_diff(array_keys($row), $columns) !== [] || array_diff($columns, array_keys($row)) !== []) {
                    throw new InvalidArgumentException('Public snapshot row whitelist is invalid.');
                }
            }
        }
        $vehicleIds = array_column($payload['vehicles'], 'id');
        $categoryIds = array_column($payload['categories'], 'id');
        $seasonIds = array_column($payload['seasons'], 'id');
        $photoIds = array_column($payload['photos'], 'id');
        $seasonKeys = array_column($payload['seasons'], 'key');
        sort($seasonKeys);
        $months = array_column($payload['months'], 'month');
        sort($months);
        if ($vehicleIds === [] || count(array_unique($vehicleIds)) !== count($vehicleIds)
            || count(array_unique(array_column($payload['vehicles'], 'external_code'))) !== count($vehicleIds)
            || count(array_unique($categoryIds)) !== count($categoryIds) || count(array_unique($photoIds)) !== count($photoIds)
            || $seasonKeys !== ['high', 'low', 'middle'] || $months !== range(1, 12)) {
            throw new InvalidArgumentException('Public snapshot grain/calendar is invalid.');
        }
        foreach ($payload['vehicles'] as &$row) {
            if (! is_int($row['id']) || $row['id'] < 1 || ! in_array($row['type'], ['car', 'scooter'], true)
                || ! is_string($row['name']) || trim($row['name']) === '' || mb_strlen($row['name']) > 255
                || ! is_string($row['external_code']) || strlen($row['external_code']) > 160
                || ($row['category_id'] !== null && ! in_array($row['category_id'], $categoryIds, true))) {
                throw new InvalidArgumentException('Public vehicle fields or category relation are invalid.');
            }
            foreach (['description', 'description_ru', 'description_ua'] as $description) {
                if ($row[$description] !== null && (! is_string($row[$description]) || mb_strlen($row[$description]) > 10000)) {
                    throw new InvalidArgumentException('Public description exceeds bounds.');
                }
            }
            if ($row['calculation_settings'] !== null) {
                if (! is_array($row['calculation_settings'])) {
                    throw new InvalidArgumentException('Public calculator settings are invalid.');
                }
                $row['calculation_settings'] = app(PricingSettings::class)->validate($row['calculation_settings']);
            }
        }
        unset($row);
        $priceKeys = [];
        $priceCounts = array_fill_keys($vehicleIds, 0);
        foreach ($payload['prices'] as $row) {
            $key = $row['vehicle_id'].':'.$row['pricing_season_id'].':'.$row['tier_key'];
            if (isset($priceKeys[$key]) || ! in_array($row['vehicle_id'], $vehicleIds, true) || ! in_array($row['pricing_season_id'], $seasonIds, true)
                || ! in_array($row['tier_key'], ['1d', '7d', '14d', '21d', 'month'], true)
                || ! is_int($row['package_total']) || $row['package_total'] < 0 || $row['package_total'] > 100000000
                || ! is_string($row['currency']) || ! preg_match('/^[A-Z]{3}$/D', $row['currency'])) {
                throw new InvalidArgumentException('Public price grain, amount or relation is invalid.');
            }
            $priceKeys[$key] = true;
            $priceCounts[$row['vehicle_id']]++;
        }
        if (array_filter($priceCounts, fn (int $count): bool => $count !== 15) !== []) {
            throw new InvalidArgumentException('Every public vehicle needs 15 active prices.');
        }
        foreach ($payload['photos'] as $row) {
            if (! in_array($row['vehicle_id'], $vehicleIds, true) || $row['disk'] !== 'public' || ! is_string($row['file_path'])
                || ! preg_match('~^vehicles/[1-9][0-9]*/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)$~iD', $row['file_path'])) {
                throw new InvalidArgumentException('Public photo relation or path is invalid.');
            }
        }
        foreach ($payload['months'] as $row) {
            if (! in_array($row['pricing_season_id'], $seasonIds, true)) {
                throw new InvalidArgumentException('Public month-season relation is invalid.');
            }
        }
        if (! is_array($payload['rental_policy']) || array_diff(array_keys($payload['rental_policy']), ['minimum_days', 'category_minimum_days']) !== []
            || ! is_int($payload['rental_policy']['minimum_days'] ?? null) || $payload['rental_policy']['minimum_days'] < 1
            || ! is_array($payload['rental_policy']['category_minimum_days'] ?? null)) {
            throw new InvalidArgumentException('Public minimum rental policy is invalid.');
        }
        foreach ($payload['rental_policy']['category_minimum_days'] as $id => $minimum) {
            if (! in_array((int) $id, $categoryIds, true) || ! is_int($minimum) || $minimum < 1) {
                throw new InvalidArgumentException('Public category minimum is invalid.');
            }
        }

        return $payload;
    }
}
