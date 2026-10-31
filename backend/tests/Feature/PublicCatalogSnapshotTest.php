<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Website\PublicCatalogSnapshot;
use InvalidArgumentException;
use Tests\TestCase;

class PublicCatalogSnapshotTest extends TestCase
{
    public function test_exact_public_fixture_is_accepted_without_database_access(): void
    {
        $fixture = $this->fixture();
        $this->assertSame($fixture, app(PublicCatalogSnapshot::class)->validate($fixture));
    }

    public function test_private_row_fields_or_orphan_photo_are_rejected(): void
    {
        foreach (['private_field', 'orphan_photo', 'incomplete_matrix', 'duplicate_key'] as $variant) {
            $fixture = $this->fixture();
            if ($variant === 'private_field') {
                $fixture['vehicles'][0]['admin_note'] = 'SYNTHETIC-PRIVATE';
            }
            if ($variant === 'orphan_photo') {
                $fixture['photos'][0]['vehicle_id'] = 999;
            }
            if ($variant === 'incomplete_matrix') {
                array_pop($fixture['prices']);
            }
            if ($variant === 'duplicate_key') {
                $fixture['vehicles'][] = $fixture['vehicles'][0];
            }
            try {
                app(PublicCatalogSnapshot::class)->validate($fixture);
                $this->fail('Unsafe snapshot accepted: '.$variant);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_photo_traversal_and_unknown_season_are_rejected(): void
    {
        foreach (['vehicles/1/../private.jpg', 'https://example.invalid/image.jpg', 'vehicles/1/%2e%2e.jpg'] as $path) {
            $fixture = $this->fixture();
            $fixture['photos'][0]['file_path'] = $path;
            try {
                app(PublicCatalogSnapshot::class)->validate($fixture);
                $this->fail('Unsafe public path accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $vehicles = [['id' => 1, 'external_code' => 'synthetic-public-vehicle', 'inventory_code' => 'SYNTHETIC-ONLY', 'type' => 'car', 'category_id' => null,
            'name' => 'Synthetic public vehicle', 'year' => 2026, 'description' => null, 'description_ru' => null, 'description_ua' => null,
            'brand' => null, 'model' => null, 'engine_cc' => null, 'color' => null, 'abs' => null, 'seats' => null, 'transmission' => null,
            'sort_order' => 1, 'calculation_settings' => null]];
        $prices = [];
        foreach ([1, 2, 3] as $season) {
            foreach (PricingTier::cases() as $tier) {
                $prices[] = ['vehicle_id' => 1, 'pricing_season_id' => $season, 'tier_key' => $tier->value, 'min_days' => $tier->minimumDays(),
                    'max_days' => $tier->maximumDays(), 'anchor_days' => $tier->anchorDays(), 'package_total' => 100 * $tier->anchorDays(), 'daily_rate' => '100.000000', 'currency' => 'THB'];
            }
        }

        return ['format' => 'PUBLIC-CATALOG-PREVIEW-1', 'exported_at' => '2026-10-31T00:00:00+00:00', 'source_origin' => 'https://admin.drivephangan.com', 'transaction_read_only' => true,
            'vehicles' => $vehicles, 'categories' => [], 'photos' => [['id' => 1, 'vehicle_id' => 1, 'disk' => 'public', 'file_path' => 'vehicles/1/synthetic.jpg', 'alt_text' => null, 'sort_order' => 0, 'is_primary' => true]],
            'seasons' => [['id' => 1, 'key' => 'high', 'name' => 'High', 'sort_order' => 1], ['id' => 2, 'key' => 'middle', 'name' => 'Middle', 'sort_order' => 2], ['id' => 3, 'key' => 'low', 'name' => 'Low', 'sort_order' => 3]],
            'months' => array_map(fn (int $month): array => ['pricing_season_id' => $month <= 3 || $month === 12 ? 1 : ($month >= 6 && $month <= 9 ? 3 : 2), 'month' => $month], range(1, 12)),
            'prices' => $prices, 'rental_policy' => ['minimum_days' => 1, 'category_minimum_days' => []]];
    }
}
