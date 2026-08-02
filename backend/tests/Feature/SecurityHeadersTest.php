<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_baseline_security_headers_are_added_to_every_response(): void
    {
        $this->get('/health/live')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    }

    public function test_csp_nonce_matches_inline_application_markup(): void
    {
        config()->set('security.csp.enabled', true);

        $response = $this->get('/login')->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+'/", $policy);
        preg_match("/'nonce-([^']+)'/", $policy, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertStringContainsString('nonce="'.$matches[1].'"', $response->getContent());
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
    }

    public function test_hsts_is_only_added_to_secure_requests_when_enabled(): void
    {
        config()->set('security.hsts.enabled', true);

        $this->get('/health/live')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/health/live')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
