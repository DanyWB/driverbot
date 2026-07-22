<?php

namespace Tests\Feature;

use App\Domain\Availability\Enums\OccupancyType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class BotApiAuthCustomerCatalogTest extends BotApiTestCase
{
    public function test_api_requires_active_token_and_route_ability_with_stable_errors(): void
    {
        $this->getJson('/api/v1/bot/vehicles')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details', 'request_id']]);

        $this->serviceClient->update(['abilities' => ['bot:write']]);
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/vehicles')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ABILITY_FORBIDDEN');

        $this->serviceClient->update(['abilities' => ['bot:read']]);
        $this->withHeaders($this->apiHeaders(idempotencyKey: 'cannot-write'))
            ->postJson('/api/v1/bot/customers/sync', ['telegram_id' => '100001'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ABILITY_FORBIDDEN');
    }

    public function test_rate_limit_is_scoped_to_authenticated_service_client(): void
    {
        config()->set('bot_api.service_rate_limit_per_minute', 1);
        RateLimiter::clear("service:{$this->serviceClient->id}");

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/configuration')
            ->assertOk();
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/configuration')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_invalid_service_tokens_are_rate_limited_by_ip(): void
    {
        config()->set('bot_api.auth_rate_limit_per_minute', 1);
        RateLimiter::clear('bot-api-auth:127.0.0.1');

        $this->withToken('invalid-token-one')
            ->getJson('/api/v1/bot/configuration')
            ->assertUnauthorized();
        $this->withToken('a-different-invalid-token')
            ->getJson('/api/v1/bot/configuration')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_rate_limit_isolated_per_telegram_customer(): void
    {
        $this->syncCustomer('100010');
        $this->syncCustomer('100011');
        config()->set('bot_api.service_rate_limit_per_minute', 100);
        config()->set('bot_api.user_rate_limit_per_minute', 1);
        RateLimiter::clear("service:{$this->serviceClient->id}:telegram:100010");
        RateLimiter::clear("service:{$this->serviceClient->id}:telegram:100011");

        $this->withHeaders($this->apiHeaders('100010'))
            ->getJson('/api/v1/bot/customers/me')
            ->assertOk();
        $this->withHeaders($this->apiHeaders('100010'))
            ->getJson('/api/v1/bot/customers/me')
            ->assertTooManyRequests();
        $this->withHeaders($this->apiHeaders('100011'))
            ->getJson('/api/v1/bot/customers/me')
            ->assertOk();
    }

    public function test_customer_sync_profile_update_and_idempotency_are_consistent(): void
    {
        $payload = [
            'telegram_id' => '100002',
            'username' => 'alex_test',
            'first_name' => 'Alex',
            'locale' => 'ru',
        ];
        $headers = $this->apiHeaders(idempotencyKey: 'sync-100002');

        $first = $this->withHeaders($headers)->postJson('/api/v1/bot/customers/sync', $payload);
        $first->assertOk()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.telegram.id', '100002')
            ->assertJsonPath('meta.idempotency_replayed', false);

        $this->withHeaders($headers)->postJson('/api/v1/bot/customers/sync', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotency_replayed', true);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_identities', 1);

        $this->withHeaders($headers)->postJson('/api/v1/bot/customers/sync', [
            ...$payload,
            'username' => 'changed_payload',
        ])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->withHeaders($this->apiHeaders('100002', 'profile-100002'))
            ->patchJson('/api/v1/bot/customers/me', [
                'name' => 'Alex Morgan',
                'locale' => 'en',
                'phone' => '+66 81 234 5678',
                'passport_number' => 'AB1234567',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Alex Morgan')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.phone', '+66 81 234 5678')
            ->assertJsonPath('data.profile_complete.passport', true);

        $this->withHeaders($this->apiHeaders('100002', 'empty-profile-100002'))
            ->patchJson('/api/v1/bot/customers/me', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['profile'], 'error.fields');

        $customer = Customer::query()->sole();
        $this->assertSame('AB1234567', $customer->privateData()['passport_number']);
        $this->assertStringNotContainsString('AB1234567', (string) DB::table('customers')->value('private_data'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.telegram_profile_updated',
            'actor_service_client_id' => $this->serviceClient->id,
        ]);
    }

    public function test_customer_sync_normalizes_telegram_language_codes(): void
    {
        $this->withHeaders($this->apiHeaders(idempotencyKey: 'sync-uk-locale'))
            ->postJson('/api/v1/bot/customers/sync', [
                'telegram_id' => '100003',
                'locale' => 'uk-UA',
            ])
            ->assertOk()
            ->assertJsonPath('data.locale', null);

        $this->assertSame('ua', Customer::query()->sole()->locale);
    }

    public function test_catalog_hides_unpublished_vehicles_and_availability_and_quote_share_core_rules(): void
    {
        $category = Category::factory()->create(['name' => 'Scooters', 'is_active' => true]);
        $available = $this->bookableVehicle('Available Click', $category);
        $busy = $this->bookableVehicle('Busy NMAX', $category);
        Vehicle::factory()->create([
            'name' => 'Hidden ADV',
            'category_id' => $category->id,
            'is_active' => true,
            'is_visible_for_booking' => false,
        ]);
        VehicleOccupancy::query()->create([
            'vehicle_id' => $busy->id,
            'type' => OccupancyType::Maintenance,
            'starts_on' => '2026-08-12',
            'ends_on' => '2026-08-13',
            'blocks_availability' => true,
            'label' => 'Service',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/configuration')
            ->assertOk()
            ->assertJsonPath('data.terms_version', (string) config('business.terms_version'))
            ->assertJsonPath('data.timezone', 'Asia/Bangkok');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicles_count', 2);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/vehicles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category.vehicles_count', 2);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/v1/bot/vehicles/available?start_date=2026-08-10&end_date=2026-08-16')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id);

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/v1/bot/vehicles/{$busy->id}/availability?start_date=2026-08-01&end_date=2026-08-31")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.unavailable_dates', ['2026-08-12', '2026-08-13']);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/bot/quotes', [
                'vehicle_id' => $busy->id,
                'starts_on' => '2026-08-10',
                'ends_on' => '2026-08-16',
            ])
            ->assertOk()
            ->assertJsonPath('data.final_total', 700)
            ->assertJsonPath('data.available', false);
    }
}
