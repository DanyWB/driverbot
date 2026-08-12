<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PricingSeason;
use App\Models\ServiceApiClient;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class BotApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected ServiceApiClient $serviceClient;

    protected string $serviceToken = 'dph_test_service_token';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-09 11:00:00', 'Asia/Bangkok'));
        $this->seed();
        $this->serviceClient = ServiceApiClient::query()->create([
            'name' => 'Feature test bot',
            'token_hash' => hash('sha256', $this->serviceToken),
            'abilities' => ['bot:read', 'bot:write', 'bot:documents'],
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, string> */
    protected function apiHeaders(?string $telegramId = null, ?string $idempotencyKey = null): array
    {
        return array_filter([
            'Authorization' => "Bearer {$this->serviceToken}",
            'Accept' => 'application/json',
            'X-Telegram-User-ID' => $telegramId,
            'Idempotency-Key' => $idempotencyKey,
            'X-Request-ID' => 'test-request-'.($idempotencyKey ?? 'read'),
        ], fn (?string $value): bool => $value !== null);
    }

    protected function syncCustomer(string $telegramId = '100001'): Customer
    {
        $this->withHeaders($this->apiHeaders(idempotencyKey: "sync-{$telegramId}"))
            ->postJson('/api/v1/bot/customers/sync', [
                'telegram_id' => $telegramId,
                'username' => "user_{$telegramId}",
                'first_name' => 'Telegram',
                'locale' => 'ru',
            ])
            ->assertOk();

        return Customer::query()->whereHas('identities', fn ($query) => $query->where('external_id', $telegramId))->sole();
    }

    protected function bookableVehicle(string $name = 'Honda Click', ?Category $category = null): Vehicle
    {
        $vehicle = Vehicle::factory()->create([
            'name' => $name,
            'category_id' => $category?->id,
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);

        foreach (PricingSeasonKey::cases() as $seasonKey) {
            $season = PricingSeason::query()->where('key', $seasonKey->value)->sole();

            foreach (PricingTier::cases() as $tier) {
                VehiclePriceTier::query()->create([
                    'vehicle_id' => $vehicle->id,
                    'pricing_season_id' => $season->id,
                    'tier_key' => $tier,
                    'min_days' => $tier->minimumDays(),
                    'max_days' => $tier->maximumDays(),
                    'anchor_days' => $tier->anchorDays(),
                    'package_total' => $tier->anchorDays() * 100,
                    'daily_rate' => '100.000000',
                    'currency' => 'THB',
                    'is_active' => true,
                ]);
            }
        }

        return $vehicle;
    }

    /** @return array<string, mixed> */
    protected function bookingPayload(Vehicle ...$vehicles): array
    {
        return [
            'terms_accepted' => true,
            'terms_version' => (string) config('business.terms_version'),
            'items' => array_map(fn (Vehicle $vehicle): array => [
                'client_reference' => "vehicle-{$vehicle->id}",
                'vehicle_id' => $vehicle->id,
                'starts_on' => '2026-08-10',
                'ends_on' => '2026-08-16',
                'pickup_time' => '10:00',
                'return_time' => '09:00',
                'helmets_quantity' => 2,
                'delivery_required' => true,
                'delivery_address' => 'Thong Sala Pier',
                'client_comment' => 'Call before delivery',
            ], $vehicles),
        ];
    }
}
