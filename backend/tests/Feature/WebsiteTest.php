<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Vehicles\Services\VehicleCatalogService;
use App\Domain\Website\PublicCatalog;
use App\Domain\Website\WebsitePickupDate;
use App\Domain\Website\WebsitePreviewGuard;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\NotificationOutbox;
use App\Models\PricingSeason;
use App\Models\RentalPolicy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Bangkok'));
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('w', 32)),
            'website.enabled' => true, 'website.url' => 'https://rent.example.invalid', 'website.domain' => null,
            'website.accepting_requests' => true, 'website.terms_version' => 'TEST-1',
            'website.demo' => false, 'website.indexable' => false,
            'website.policies.terms.en' => 'Test terms', 'website.policies.privacy.en' => 'Test privacy',
            'notifications.telegram.bot_token' => null, 'notifications.telegram.admin_chat_id' => null,
        ]);
        $this->seed();
        $this->vehicle = Vehicle::factory()->create([
            'name' => 'Public test car', 'type' => 'car', 'category_id' => null,
            'brand' => 'Test', 'model' => 'Seven', 'seats' => 7, 'abs' => false,
            'is_active' => true, 'is_visible_for_booking' => true,
            'inventory_code' => 'PRIVATE-INVENTORY', 'characteristics_text' => 'INTERNAL-CHARACTERISTICS',
        ]);
        foreach (PricingSeason::all() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $this->vehicle->priceTiers()->create([
                    'pricing_season_id' => $season->id, 'tier_key' => $tier->value,
                    'min_days' => $tier->minimumDays(), 'max_days' => $tier->maximumDays(),
                    'anchor_days' => $tier->anchorDays(), 'package_total' => 1000 * $tier->anchorDays(),
                    'daily_rate' => '1000.000000', 'currency' => 'THB', 'is_active' => true,
                ]);
            }
        }
    }

    public function test_website_is_an_explicit_opt_in_and_admin_root_is_unchanged(): void
    {
        config(['website.enabled' => false]);
        $this->get('/en')->assertNotFound();
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_public_catalog_is_ssr_whitelisted_and_filters_structured_seven_seats(): void
    {
        Vehicle::factory()->create(['type' => 'car', 'name' => 'HIDDEN-VEHICLE', 'is_visible_for_booking' => false]);
        Vehicle::factory()->create(['type' => 'car', 'name' => 'INACTIVE-VEHICLE', 'is_active' => false, 'is_visible_for_booking' => true]);
        $this->get('/en/cars?seats=7&abs=0')->assertOk()->assertSee('Public test car')
            ->assertDontSee('HIDDEN-VEHICLE')->assertDontSee('INACTIVE-VEHICLE')
            ->assertDontSee('PRIVATE-INVENTORY')->assertDontSee('INTERNAL-CHARACTERISTICS')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertViewHas('vehicles', fn ($vehicles) => $vehicles->total() === 1 && is_array($vehicles->items()[0]));
        $this->get('/en/cars?seats=5')->assertOk()->assertViewHas('vehicles', fn ($vehicles) => $vehicles->total() === 0);
    }

    public function test_all_locales_have_real_ssr_pages_and_canonical_has_no_query_or_host_input(): void
    {
        foreach (['en', 'ru', 'uk'] as $locale) {
            $this->get('/'.$locale)->assertOk()->assertSee('lang="'.$locale.'"', false)
                ->assertViewHas('seo', fn ($seo) => $seo['canonical'] === 'https://rent.example.invalid/'.$locale && count($seo['hreflang']) === 3);
        }
        $this->get('/en/cars?brand=Test')->assertOk()->assertViewHas('seo', fn ($seo) => $seo['canonical'] === 'https://rent.example.invalid/en/cars' && $seo['robots'] === 'noindex, nofollow');
        $this->get('/en/cars?brand=Test&contact_value=PRIVATE-CONTACT')->assertOk()
            ->assertViewHas('languages', fn ($languages) => str_contains($languages[1]['url'], '/ru/cars?brand=Test') && ! str_contains($languages[1]['url'], 'PRIVATE-CONTACT'));
        $this->get('/de')->assertNotFound();
    }

    public function test_detail_slug_is_canonical_and_hidden_vehicle_endpoints_are_404(): void
    {
        $this->get('/en/vehicles/'.$this->vehicle->id.'-old')->assertStatus(301)
            ->assertRedirect(route('website.vehicle', ['locale' => 'en', 'slug' => app(PublicCatalog::class)->slug($this->vehicle)]));
        $this->vehicle->update(['is_visible_for_booking' => false]);
        $this->get('/en/vehicles/'.$this->vehicle->id.'-public-test-car')->assertNotFound();
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-10&ends_on=2026-11-12')->assertNotFound();
        $this->get('/en/book/'.$this->vehicle->id)->assertNotFound();
    }

    public function test_public_quote_uses_shared_pricing_and_rejects_invalid_periods(): void
    {
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-10&ends_on=2026-11-12')
            ->assertOk()->assertJsonPath('data.quote.final_total', 2000)->assertJsonPath('data.quote.total_days', 2)
            ->assertJsonPath('data.quote.payments.deposit.amount', null)->assertJsonPath('data.quote.payments.deposit.status', 'pending_confirmation')
            ->assertJsonPath('data.pickup_time', '12:00')->assertJsonPath('data.timezone', 'Asia/Bangkok');
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-10&ends_on=2026-11-09')->assertUnprocessable();
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-10')->assertUnprocessable();
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-10-10&ends_on=2026-11-10')->assertUnprocessable();
    }

    public function test_availability_is_inclusive_and_never_exposes_labels_or_ids(): void
    {
        VehicleOccupancy::query()->create([
            'vehicle_id' => $this->vehicle->id, 'type' => 'maintenance', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12',
            'blocks_availability' => true, 'label' => 'SECRET-CUSTOMER-LABEL', 'metadata' => ['private' => 'SECRET-METADATA'],
        ]);
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/availability?from=2026-11-11&to=2026-11-20')
            ->assertOk()->assertExactJson(['data' => ['from' => '2026-11-11', 'to' => '2026-11-20',
                'busy' => [['starts_on' => '2026-11-11', 'ends_on' => '2026-11-12']], 'inclusive' => true, 'timezone' => 'Asia/Bangkok']]);
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-12&ends_on=2026-11-14')
            ->assertStatus(409)->assertJsonPath('error.code', 'vehicle_unavailable');
        $this->get('/en/cars?starts_on=2026-11-12&ends_on=2026-11-14')->assertOk()->assertViewHas('vehicles', fn ($vehicles) => $vehicles->total() === 0);
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/availability?from=2026-11-01&to=2028-11-01')->assertUnprocessable();
    }

    public function test_guest_request_ignores_client_price_is_idempotent_and_does_not_bind_unverified_identity(): void
    {
        $existing = Customer::factory()->create();
        $existing->contacts()->create(['type' => 'telegram_username', 'value' => '@sampleuser', 'normalized_value' => '@sampleuser', 'is_primary' => true]);
        CustomerIdentity::query()->create(['customer_id' => $existing->id, 'provider' => 'telegram', 'external_id' => '123456789']);
        $payload = [...$this->payload(), 'final_total' => 1, 'customer_id' => $existing->id, 'telegram_id' => '123456789'];
        $response = $this->withSession(['website_owner' => 'guest-one'])->post('/en/book/'.$this->vehicle->id, $payload)->assertStatus(303);
        $booking = Booking::query()->firstOrFail();
        $this->assertNotSame($existing->id, $booking->customer_id);
        $this->assertSame('website', $booking->getRawOriginal('source'));
        $this->assertSame('pending', $booking->getRawOriginal('status'));
        $this->assertSame(2000, (int) $booking->latestPriceSnapshot->final_total);
        $this->assertSame('TEST-1', $booking->terms_version);
        $this->assertSame('12:00', substr($booking->pickup_time, 0, 5));
        $this->assertSame('1500.00', $booking->paymentsSummary()['prepayment']['amount']);
        $this->assertNull($booking->customer->contacts->first()->verified_at);
        $this->assertSame(0, $booking->customer->identities()->count());
        $this->assertSame(0, NotificationOutbox::query()->where('channel', 'telegram')->count());
        $this->assertFalse($booking->occupancy->blocks_availability);
        $this->post('/en/book/'.$this->vehicle->id, $payload)->assertStatus(303)->assertRedirect($response->headers->get('Location'));
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(2, Customer::query()->count());
        $this->post('/en/book/'.$this->vehicle->id, [...$payload, 'name' => 'Changed'])->assertSessionHasErrors('idempotency_key');
        Http::assertNothingSent();
    }

    public function test_private_status_requires_owner_session_and_has_no_pii_or_internal_notes(): void
    {
        $this->withSession(['website_owner' => 'guest-one'])->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(303);
        $booking = Booking::query()->firstOrFail();
        $booking->update(['admin_note' => 'PRIVATE-ADMIN-NOTE']);
        $path = '/en/requests/'.$booking->public_id;
        $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('PERSONAL-NAME')->assertDontSee('@sampleuser')->assertDontSee('PRIVATE-ADMIN-NOTE')
            ->assertViewHas('booking', fn ($data) => ! isset($data['customer_id']) && $data['contact_delivery'] === 'manual');
        $this->withSession(['website_owner' => 'guest-two'])->get($path)->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_public_preview_guest_requests_still_require_owner_session_and_do_not_create_verified_delivery(): void
    {
        $this->publicPreview();
        $this->withSession(['website_owner' => 'public-guest-one'])->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(303);
        $booking = Booking::query()->firstOrFail();
        foreach (['en', 'ru', 'uk'] as $locale) {
            $path = '/'.$locale.'/requests/'.$booking->public_id;
            $this->withSession(['website_owner' => 'public-guest-one'])->get($path)->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private')
                ->assertDontSee('PERSONAL-NAME')->assertDontSee('@sampleuser');
            $this->withSession(['website_owner' => 'public-guest-two'])->get($path)->assertNotFound()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertDatabaseCount('website_booking_requests', 1);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('customer_identities', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertNull($booking->customer->contacts->first()->verified_at);
        Http::assertNothingSent();
    }

    public function test_public_preview_keeps_csrf_and_submit_throttle_before_customer_creation(): void
    {
        $this->publicPreview();
        $this->app->instance('env', 'production');
        $this->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(419);
        $this->app->instance('env', 'testing');
        for ($i = 0; $i < 8; $i++) {
            $this->post('/en/book/'.$this->vehicle->id, [])->assertSessionHasErrors();
        }
        $this->post('/en/book/'.$this->vehicle->id, [])->assertStatus(429);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_terms_honeypot_contact_validation_and_unconfigured_business_fail_closed(): void
    {
        $path = '/en/book/'.$this->vehicle->id;
        $this->post($path, [...$this->payload(), 'terms_accepted' => false])->assertSessionHasErrors('terms_accepted');
        $this->post($path, [...$this->payload(), 'terms_version' => 'STALE'])->assertSessionHasErrors('terms_version');
        $this->post($path, [...$this->payload(), 'company' => 'Spam'])->assertSessionHasErrors('company');
        $this->post($path, [...$this->payload(), 'contact_channel' => 'email', 'contact_value' => 'not-an-email'])->assertSessionHasErrors('contact_value');
        config(['website.policies.privacy.en' => null]);
        $this->post($path, $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_rechecks_occupancy_and_rolls_back_customer_and_idempotency_on_conflict(): void
    {
        VehicleOccupancy::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => 'maintenance', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'blocks_availability' => true]);
        $this->post('/en/book/'.$this->vehicle->id, $this->payload())->assertSessionHasErrors('starts_on');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('website_booking_requests', 0);
    }

    public function test_booking_validation_returns_to_owning_form_after_real_json_gets(): void
    {
        foreach (['ru' => ['starts_on' => '', 'ends_on' => ''], 'uk' => ['terms_version' => 'STALE']] as $locale => $invalid) {
            $path = '/'.$locale.'/book/'.$this->vehicle->id;
            $previous = '/'.$locale.'/vehicles/'.$this->vehicle->id.'/quote?ends_on=2026-11-12&starts_on=2026-11-10';
            $this->getJson($previous)->assertOk();
            $this->assertSame(url($previous), session()->previousUrl());
            $payload = [...$this->payload(), ...$invalid];
            $this->post($path, $payload)->assertRedirect(url($path))
                ->assertSessionHasErrors($locale === 'ru' ? 'starts_on' : 'terms_version')
                ->assertSessionHasInput('name', $payload['name'])
                ->assertSessionHasInput('contact_value', $payload['contact_value'])
                ->assertSessionHasInput('ends_on', $payload['ends_on']);
            $json = $this->postJson($path, $payload)->assertUnprocessable();
            $this->assertFalse($json->headers->has('Location'));
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('website_booking_requests', 0);
    }

    public function test_booking_domain_validation_returns_to_form_without_losing_guest_input(): void
    {
        VehicleOccupancy::query()->create(['vehicle_id' => $this->vehicle->id, 'type' => 'maintenance', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'blocks_availability' => true]);
        $previous = '/en/vehicles/'.$this->vehicle->id.'/availability?from=2026-11-01&to=2026-11-20';
        $this->getJson($previous)->assertOk();
        $this->assertSame(url($previous), session()->previousUrl());
        $path = '/en/book/'.$this->vehicle->id;
        $payload = $this->payload();
        $this->post($path, $payload)->assertRedirect(url($path))->assertSessionHasErrors('starts_on')
            ->assertSessionHasInput('name', $payload['name'])
            ->assertSessionHasInput('contact_value', $payload['contact_value'])
            ->assertSessionHasInput('starts_on', $payload['starts_on'])
            ->assertSessionHasInput('ends_on', $payload['ends_on']);
        $json = $this->postJson($path, $payload)->assertUnprocessable();
        $this->assertFalse($json->headers->has('Location'));
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('website_booking_requests', 0);
        Http::assertNothingSent();
    }

    public function test_booking_repriced_after_quote_returns_pricing_error_to_form_not_json_url(): void
    {
        config(['website.policies.terms.uk' => 'Test terms', 'website.policies.privacy.uk' => 'Test privacy']);
        $this->getJson('/uk/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-10&ends_on=2026-11-12')
            ->assertOk()->assertJsonPath('data.quote.final_total', 2000);
        // The displayed quote is no longer authoritative at submission time.
        $this->vehicle->priceTiers()->delete();
        $path = '/uk/book/'.$this->vehicle->id;
        $payload = $this->payload();
        $this->post($path, $payload)->assertRedirect(url($path))->assertSessionHasErrors('starts_on')
            ->assertSessionHasInput('name', $payload['name'])
            ->assertSessionHasInput('contact_value', $payload['contact_value'])
            ->assertSessionHasInput('starts_on', $payload['starts_on']);
        $json = $this->postJson($path, $payload)->assertUnprocessable();
        $this->assertFalse($json->headers->has('Location'));
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('website_booking_requests', 0);
    }

    public function test_domain_service_requires_terms_for_website_not_just_form(): void
    {
        $customer = Customer::factory()->create();
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Current rental terms must be accepted.');
        app(BookingService::class)->create(new CreateBookingData($customer->id, $this->vehicle->id, '2026-11-10', '2026-11-12', BookingSource::Website), BookingActor::customer($customer->id));
    }

    public function test_competing_guest_requests_keep_existing_policy_and_admin_can_process_them(): void
    {
        $this->withSession(['website_owner' => 'first-guest'])->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(303);
        $first = Booking::query()->firstOrFail();
        $this->withSession(['website_owner' => 'second-guest'])->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(303);
        $second = Booking::query()->latest('id')->firstOrFail();
        $this->assertSame('pending', $first->getRawOriginal('status'));
        $this->assertSame('pending', $second->getRawOriginal('status'));
        $this->assertFalse($first->occupancy->blocks_availability);
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/bookings/'.$first->public_id)->assertOk();
        app(BookingService::class)->approve($first, BookingActor::admin($admin->id));
        $this->assertSame('approved', $first->fresh()->getRawOriginal('status'));
        $this->assertTrue($first->fresh()->occupancy->blocks_availability);
        $this->assertSame('cancelled_unavailable', $second->fresh()->getRawOriginal('status'));
        $this->withSession(['website_owner' => 'second-guest'])->get('/en/requests/'.$second->public_id)
            ->assertOk()->assertViewHas('booking', fn ($booking) => $booking['status'] === 'cancelled_unavailable');
    }

    public function test_public_photos_are_only_from_approved_public_disk_and_xss_is_escaped(): void
    {
        $this->vehicle->photos()->create(['disk' => 'private', 'file_path' => 'passport-secret.jpg', 'alt_text' => 'SECRET-PRIVATE-PHOTO']);
        $this->vehicle->photos()->create(['disk' => 'public', 'file_path' => '../private/secret.jpg']);
        $this->vehicle->photos()->create(['disk' => 'public', 'file_path' => 'vehicles/sample.jpg', 'alt_text' => '</script><script>alert(1)</script>']);
        $this->vehicle->update(['name' => '</script><script>alert(1)</script>']);
        $payload = app(PublicCatalog::class)->present($this->vehicle->fresh(['photos', 'category']), 'en');
        $this->assertCount(1, $payload['photos']);
        $this->assertStringContainsString('/storage/vehicles/sample.jpg', $payload['photos'][0]['url']);
        $this->get('/en')->assertOk()->assertDontSee('SECRET-PRIVATE-PHOTO')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_sitemap_is_empty_on_nonproduction_and_only_published_vehicles_in_production_optin(): void
    {
        config(['website.indexable' => true]);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<url>', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->app->instance('env', 'production');
        $hidden = Vehicle::factory()->create(['name' => 'Hidden', 'is_visible_for_booking' => false]);
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://rent.example.invalid/en/vehicles/'.$this->vehicle->id.'-public-test-car', false)
            ->assertDontSee('/vehicles/'.$hidden->id.'-hidden', false);
        $this->get('/en/cars')->assertOk()->assertViewHas('seo', fn ($seo) => $seo['robots'] === 'index, follow');
        $this->get('/en/cars?seats=7')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        config(['website.url' => null]);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<url>', false);
    }

    public function test_csrf_and_submit_throttle_are_enforced(): void
    {
        $this->app->instance('env', 'production');
        $this->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(419);
        $this->app->instance('env', 'testing');
        for ($i = 0; $i < 8; $i++) {
            $this->post('/en/book/'.$this->vehicle->id, [])->assertSessionHasErrors();
        }
        $this->post('/en/book/'.$this->vehicle->id, [])->assertStatus(429);
    }

    public function test_structured_fleet_fields_are_saved_and_omission_preserves_values(): void
    {
        $data = [...$this->vehicle->only(['external_code', 'type', 'category_id', 'name', 'year', 'is_active', 'is_visible_for_booking', 'sort_order']), 'type' => 'car', 'engine_cc' => 1600, 'transmission' => 'automatic'];
        $updated = app(VehicleCatalogService::class)->updateVehicle($this->vehicle, $data);
        $this->assertSame(1600, $updated->engine_cc);
        $this->assertSame(7, $updated->seats);
        $this->assertFalse($updated->abs);
        $this->actingAs(User::factory()->create())->patch('/vehicles/'.$this->vehicle->id, [...$data, 'seats' => 0])->assertSessionHasErrors('seats');
    }

    public function test_quote_reads_do_not_consume_guest_submit_budget(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-10&ends_on=2026-11-12')->assertOk();
        }
        $this->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(303);
    }

    public function test_card_price_preview_uses_shared_minimum_period_and_actual_calendar_month_dates(): void
    {
        RentalPolicy::query()->updateOrCreate(['id' => 1], ['minimum_days' => 3]);
        foreach ([['2027-01-31', '2027-02-28', 28], ['2027-03-01', '2027-04-01', 31]] as [$start, $end, $days]) {
            $this->travelTo(CarbonImmutable::parse($start.' 10:00:00', 'Asia/Bangkok'));
            $preview = app(PublicCatalog::class)->present($this->vehicle, 'en')['pricePreview'];
            $this->assertSame($start, $preview['starts_on']);
            $this->assertSame(3, $preview['minimum_days']);
            $this->assertSame(3, $preview['day']['total_days']);
            $this->assertSame(3000, $preview['day']['final_total']);
            $this->assertSame(1000.0, $preview['day']['average_daily']);
            $this->assertSame($end, $preview['month']['end_date']);
            $this->assertSame($days, $preview['month']['total_days']);
            $this->assertSame($days * 1000, $preview['month']['final_total']);
            $this->assertSame('THB', $preview['month']['currency']);
        }
        $this->vehicle->priceTiers()->delete();
        $preview = app(PublicCatalog::class)->present($this->vehicle, 'en')['pricePreview'];
        $this->assertNull($preview['day']);
        $this->assertNull($preview['month']);
    }

    public function test_fixed_noon_cutoff_is_shared_by_quote_form_and_illustrative_prices(): void
    {
        foreach ([['11:59:00', '2026-11-01', true], ['12:00:00', '2026-11-02', false], ['12:01:00', '2026-11-02', false]] as [$time, $earliest, $allowed]) {
            $this->travelTo(CarbonImmutable::parse('2026-11-01 '.$time, 'Asia/Bangkok'));
            $this->assertSame($earliest, WebsitePickupDate::earliest()->format('Y-m-d'));
            $this->assertSame($earliest, app(PublicCatalog::class)->present($this->vehicle, 'en')['pricePreview']['starts_on']);
            $this->get('/en/cars')->assertOk()->assertViewHas('website', fn ($website) => $website['today_date'] === '2026-11-01' && $website['earliest_pickup_date'] === $earliest);
            $response = $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=2026-11-01&ends_on=2026-11-02');
            if ($allowed) {
                $response->assertOk();
            } else {
                $response->assertUnprocessable();
                $this->post('/en/book/'.$this->vehicle->id, [...$this->payload(), 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02'])->assertSessionHasErrors('starts_on');
                $this->assertDatabaseCount('customers', 0);
                $this->assertDatabaseCount('bookings', 0);
            }
        }
    }

    public function test_pickup_clock_uses_bangkok_across_utc_noon_and_date_boundaries(): void
    {
        foreach ([['2026-10-31 04:59:00', '2026-10-31', '2026-10-31'], ['2026-10-31 05:00:00', '2026-10-31', '2026-11-01'], ['2026-10-31 17:00:00', '2026-11-01', '2026-11-01']] as [$utc, $today, $earliest]) {
            $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
            $this->assertSame($today, WebsitePickupDate::today()->format('Y-m-d'));
            $this->assertSame($earliest, WebsitePickupDate::earliest()->format('Y-m-d'));
        }
    }

    public function test_category_filter_only_uses_actual_published_active_type_compatible_categories(): void
    {
        $cars = Category::factory()->create(['name' => 'Public car category', 'vehicle_type' => 'car', 'is_active' => true]);
        $hidden = Category::factory()->create(['name' => 'HIDDEN-CATEGORY', 'vehicle_type' => 'car', 'is_active' => true]);
        $inactive = Category::factory()->create(['name' => 'INACTIVE-CATEGORY', 'vehicle_type' => 'car', 'is_active' => false]);
        $wrongType = Category::factory()->create(['name' => 'SCOOTER-CATEGORY', 'vehicle_type' => 'scooter', 'is_active' => true]);
        $this->vehicle->update(['category_id' => $cars->id]);
        Vehicle::factory()->create(['name' => 'HIDDEN-CATEGORY-VEHICLE', 'type' => 'car', 'category_id' => $hidden->id, 'is_visible_for_booking' => false]);
        Vehicle::factory()->create(['name' => 'INACTIVE-CATEGORY-VEHICLE', 'type' => 'car', 'category_id' => $inactive->id, 'is_visible_for_booking' => true]);
        Vehicle::factory()->create(['name' => 'WRONG-TYPE-VEHICLE', 'type' => 'car', 'category_id' => $wrongType->id, 'is_visible_for_booking' => true]);
        $this->get('/en/cars?category_id='.$cars->id)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertViewHas('categoryOptions', fn ($options) => count($options) === 1 && $options[0]['id'] === $cars->id)
            ->assertViewHas('vehicles', fn ($vehicles) => $vehicles->total() === 1)
            ->assertViewHas('languages', fn ($languages) => str_contains($languages[1]['url'], 'category_id='.$cars->id))
            ->assertDontSee('HIDDEN-CATEGORY')->assertDontSee('INACTIVE-CATEGORY')->assertDontSee('WRONG-TYPE-VEHICLE');
        foreach ([$hidden->id, $inactive->id, $wrongType->id] as $id) {
            $this->getJson('/en/cars?category_id='.$id)->assertUnprocessable();
        }
        $this->getJson('/en/scooters?category_id='.$cars->id)->assertUnprocessable();
    }

    private function publicPreview(): void
    {
        config(['website.preview.enabled' => true, 'website.preview.public_access' => true,
            'website.preview.host' => 'localhost', 'website.preview.auth_user' => 'preview-reviewer']);
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldReceive('verifyRuntime');
        $this->app->instance(WebsitePreviewGuard::class, $guard);
    }

    private function payload(): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'name' => 'PERSONAL-NAME', 'contact_channel' => 'telegram_username',
            'contact_value' => '@sampleuser', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'terms_accepted' => '1', 'terms_version' => 'TEST-1', 'company' => ''];
    }
}
