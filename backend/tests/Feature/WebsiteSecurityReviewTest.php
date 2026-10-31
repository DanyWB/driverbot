<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Website\PublicCatalog;
use App\Domain\Website\WebsiteSettings;
use App\Models\Booking;
use App\Models\PricingSeason;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Independent negative-path acceptance checks; all data and contacts are synthetic. */
class WebsiteSecurityReviewTest extends TestCase
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
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'website.enabled' => true, 'website.url' => 'https://rent.example.invalid',
            'website.domain' => null, 'website.accepting_requests' => true,
            'website.terms_version' => 'SYNTHETIC-REVIEW-1', 'website.demo' => false,
            'website.indexable' => false,
            'website.policies.terms.en' => 'Synthetic test terms',
            'website.policies.privacy.en' => 'Synthetic test privacy',
            'notifications.telegram.bot_token' => null, 'notifications.telegram.admin_chat_id' => null,
        ]);
        $this->seed();
        $this->vehicle = Vehicle::factory()->create([
            'name' => 'Synthetic security vehicle', 'type' => 'car', 'category_id' => null,
            'is_active' => true, 'is_visible_for_booking' => true,
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

    /** @return array<string,string> */
    private function payload(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(), 'name' => 'SYNTHETIC-PRIVATE-NAME',
            'contact_channel' => 'email', 'contact_value' => 'synthetic@example.invalid',
            'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12',
            'terms_accepted' => '1', 'terms_version' => 'SYNTHETIC-REVIEW-1', 'company' => '',
        ];
    }

    public function test_configured_domain_rejects_another_host_and_ignores_untrusted_forwarded_host(): void
    {
        config(['website.domain' => 'localhost']);
        $this->get('http://localhost/en')->assertOk();
        // TrustHosts may reject first (400); WebsiteAccess otherwise rejects with 404.
        $this->assertContains($this->get('http://another.example.invalid/en')->status(), [400, 404]);
        $this->withHeader('X-Forwarded-Host', 'another.example.invalid')->get('http://localhost/en')
            ->assertOk()->assertViewHas('seo', fn ($seo) => $seo['canonical'] === 'https://rent.example.invalid/en');
    }

    public function test_private_status_not_found_still_has_no_store_and_noindex_headers(): void
    {
        $this->app->instance('env', 'production');
        config(['website.indexable' => true]);
        $this->withSession(['website_owner' => 'SYNTHETIC-OWNER'])->get('/en/requests/'.Str::uuid())
            ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_quote_validation_errors_still_have_private_no_store_and_noindex_headers(): void
    {
        $this->app->instance('env', 'production');
        config(['website.indexable' => true]);
        $this->getJson('/en/vehicles/'.$this->vehicle->id.'/quote?starts_on=not-a-date&ends_on=2026-11-12')
            ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_status_ownership_is_enforced_in_every_locale_and_has_no_contact_pii(): void
    {
        $this->withSession(['website_owner' => 'SYNTHETIC-OWNER'])->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(303);
        $booking = Booking::query()->firstOrFail();
        foreach (['en', 'ru', 'uk'] as $locale) {
            $path = '/'.$locale.'/requests/'.$booking->public_id;
            $this->withSession(['website_owner' => 'SYNTHETIC-OWNER'])->get($path)->assertOk()
                ->assertDontSee('SYNTHETIC-PRIVATE-NAME')->assertDontSee('synthetic@example.invalid')
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->withSession(['website_owner' => 'SYNTHETIC-OTHER'])->get($path)->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_csrf_failure_before_controller_still_has_private_security_headers(): void
    {
        $this->app->instance('env', 'production');
        config(['website.indexable' => true]);
        $this->post('/en/book/'.$this->vehicle->id, $this->payload())->assertStatus(419)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('bookings', 0);
        Http::assertNothingSent();
    }

    public function test_replaying_same_request_key_for_another_vehicle_is_rejected_without_duplicate(): void
    {
        $payload = $this->payload();
        $this->withSession(['website_owner' => 'SYNTHETIC-OWNER'])->post('/en/book/'.$this->vehicle->id, $payload)->assertStatus(303);
        $other = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
        $this->post('/en/book/'.$other->id, $payload)->assertSessionHasErrors('idempotency_key');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('website_booking_requests', 1);
    }

    public function test_json_ld_cannot_be_terminated_by_untrusted_vehicle_name(): void
    {
        $attack = '</script><script data-synthetic-xss>alert(1)</script>';
        $this->vehicle->update(['name' => $attack, 'description' => $attack]);
        $response = $this->get('/en/vehicles/'.app(PublicCatalog::class)->slug($this->vehicle))
            ->assertOk()->assertDontSee('<script data-synthetic-xss>', false);
        $html = $response->getContent();
        $this->assertIsString($html);
        preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $json) {
            $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($schema);
            $this->assertArrayNotHasKey('aggregateRating', $schema);
        }
    }

    public function test_indexing_requires_a_clean_https_origin_and_is_never_enabled_for_demo(): void
    {
        $this->app->instance('env', 'production');
        config(['website.indexable' => true]);
        foreach (['http://rent.example.invalid', 'https://localhost', 'https://LOCALHOST', 'https://localhost.', 'https://127.0.0.1',
            'https://user:secret@example.invalid', 'https://example.invalid/?q=1',
            'https://example.invalid/#fragment', 'https://example.invalid/subpath'] as $url) {
            config(['website.url' => $url]);
            $this->assertFalse(WebsiteSettings::indexable(), $url);
        }
        config(['website.url' => 'https://rent.example.invalid']);
        $this->assertTrue(WebsiteSettings::indexable());
        config(['website.demo' => true]);
        $this->assertFalse(WebsiteSettings::indexable());
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<url>', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
