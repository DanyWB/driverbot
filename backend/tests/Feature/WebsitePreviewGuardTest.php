<?php

namespace Tests\Feature;

use App\Domain\Website\PublicCatalogSnapshot;
use App\Domain\Website\WebsitePreviewGuard;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebsitePreviewGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)), 'website.enabled' => true,
            'website.preview.enabled' => true, 'website.preview.public_access' => false,
            'website.preview.host' => 'localhost', 'website.preview.auth_user' => 'preview-reviewer']);
    }

    public function test_preview_blocks_admin_api_recovery_and_unsafe_methods_before_database_access(): void
    {
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldNotReceive('verifyRuntime');
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        foreach (['/login', '/dashboard', '/vehicles', '/api/v1/bot/catalog', '/forgot-password', '/reset-password/fake', '/health/ready', '/en/../login'] as $path) {
            $this->withServerVariables(['REMOTE_USER' => 'preview-reviewer'])->get($path)->assertNotFound()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->withServerVariables(['REMOTE_USER' => 'preview-reviewer'])->delete('/en')->assertNotFound();
    }

    public function test_preview_requires_trusted_server_auth_user_not_client_header(): void
    {
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldNotReceive('verifyRuntime');
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        $this->withHeader('REMOTE_USER', 'preview-reviewer')->get('/en')->assertNotFound();
        $this->withHeader('X-Remote-User', 'preview-reviewer')->get('/en')->assertNotFound();
        $this->withServerVariables(['REMOTE_USER' => 'another-reviewer'])->get('/en')->assertNotFound();
    }

    public function test_valid_preview_renders_only_website_and_forces_noindex_with_guard_verified(): void
    {
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldReceive('verifyRuntime')->times(2);
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        $this->withServerVariables(['REMOTE_USER' => 'preview-reviewer'])->get('/en/policies/contact')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->withServerVariables(['REMOTE_USER' => 'preview-reviewer'])->get('/')->assertRedirect('/en');
    }

    public function test_public_preview_is_explicit_and_renders_without_server_auth_identity(): void
    {
        config(['website.preview.public_access' => true]);
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldReceive('verifyRuntime')->times(2);
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        $this->withServerVariables(['REMOTE_USER' => null])->get('/en/policies/contact')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private');
        $this->withServerVariables(['REMOTE_USER' => null])->get('/')->assertRedirect('/en');
    }

    public function test_public_preview_keeps_host_route_and_method_boundaries_without_database_access(): void
    {
        config(['website.preview.public_access' => true]);
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldNotReceive('verifyRuntime');
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        foreach (['/login', '/dashboard', '/api/v1/bot/catalog', '/forgot-password', '/health/live', '/en/login'] as $path) {
            $this->get($path)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        $this->delete('/en')->assertNotFound();
        $this->post('/en/policies/contact')->assertNotFound();
        // TrustHosts may reject before the preview middleware; neither secure
        // denial layer may reach runtime verification or expose a website page.
        $this->assertContains($this->get('http://wrong.example.invalid/en')->getStatusCode(), [400, 404]);
    }

    public function test_public_access_cannot_be_requested_by_client_header(): void
    {
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldNotReceive('verifyRuntime');
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        $this->withHeaders(['X-Website-Preview-Public-Access' => 'true', 'X-Remote-User' => 'preview-reviewer'])->get('/en')->assertNotFound();
    }

    public function test_invalid_runtime_returns_unavailable_without_leaking_exception_details(): void
    {
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldReceive('verifyRuntime')->once()->andThrow(new RuntimeException('PRIVATE-DB-CONNECTION-DETAIL'));
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        $this->withServerVariables(['REMOTE_USER' => 'preview-reviewer'])->get('/en')->assertStatus(503)->assertDontSee('PRIVATE-DB-CONNECTION-DETAIL')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_disabling_preview_preserves_existing_admin_login_route(): void
    {
        config(['website.preview.enabled' => false, 'website.preview.public_access' => true]);
        $this->get('/login')->assertOk();
    }

    public function test_configuration_rejects_production_database_before_any_connection(): void
    {
        $this->validConfiguration();
        app(WebsitePreviewGuard::class)->assertConfiguration();
        config(['database.connections.preview_test.database' => 'drive_phangan']);
        DB::shouldReceive('selectOne')->never();
        $this->expectException(RuntimeException::class);
        app(WebsitePreviewGuard::class)->assertDatabaseIdentity();
    }

    public function test_configuration_rejects_local_environment_public_indexing_and_real_tokens(): void
    {
        foreach ([['app.debug', true], ['website.indexable', true], ['notifications.telegram.bot_token', 'SYNTHETIC-FORBIDDEN'],
            ['session.domain', '.drivephangan.com'], ['queue.default', 'redis'], ['mail.default', 'smtp'],
            ['website.preview.host', 'admin.drivephangan.com'], ['security.trusted_proxies', ['*']],
            ['website.preview.public_access', 'true'], ['website.preview.public_access', 1]] as [$key, $value]) {
            $this->validConfiguration();
            config([$key => $value]);
            try {
                app(WebsitePreviewGuard::class)->assertConfiguration();
                $this->fail('Unsafe preview configuration accepted: '.$key);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->validConfiguration();
        $this->app->instance('env', 'local');
        $this->expectException(RuntimeException::class);
        app(WebsitePreviewGuard::class)->assertConfiguration();
    }

    public function test_explicit_public_mode_does_not_relax_any_runtime_safety_configuration(): void
    {
        $this->validConfiguration();
        config(['website.preview.public_access' => true]);
        app(WebsitePreviewGuard::class)->assertConfiguration();
        config(['notifications.telegram.bot_token' => 'SYNTHETIC-FORBIDDEN']);
        $this->expectException(RuntimeException::class);
        app(WebsitePreviewGuard::class)->assertConfiguration();
    }

    public function test_preview_http_client_is_fail_closed_before_network(): void
    {
        config(['website.preview.enabled' => true]);
        (new AppServiceProvider($this->app))->boot();
        $this->expectException(StrayRequestException::class);
        Http::get('http://127.0.0.1:9/synthetic-no-network');
    }

    public function test_public_snapshot_validator_rejects_private_top_level_data_and_malformed_provenance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(PublicCatalogSnapshot::class)->validate(['format' => 'PUBLIC-CATALOG-PREVIEW-1', 'customers' => [['name' => 'SYNTHETIC-PRIVATE']]]);
    }

    private function validConfiguration(): void
    {
        $this->app->instance('env', 'production');
        $this->app->useStoragePath('/srv/drive-phangan-preview/shared/storage');
        $host = 'drive-preview.2-26-60-62.sslip.io';
        config(['app.debug' => false, 'app.url' => 'https://'.$host, 'website.enabled' => true, 'website.demo' => true,
            'website.indexable' => false, 'website.url' => 'https://'.$host, 'website.domain' => $host,
            'website.preview.enabled' => true, 'website.preview.public_access' => false,
            'website.preview.host' => $host, 'website.preview.auth_user' => 'preview-reviewer',
            'website.preview.snapshot_sha256' => str_repeat('a', 64), 'website.preview.pricing_source_sha256' => str_repeat('b', 64),
            'website.preview.mapping_sha256' => str_repeat('c', 64), 'website.preview.media_manifest_sha256' => str_repeat('d', 64),
            'website.preview.instance_id' => str_repeat('e', 32),
            'database.default' => 'preview_test', 'database.connections.preview_test' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'url' => null, 'database' => 'drive_phangan_preview', 'username' => 'drive_phangan_preview'],
            'session.driver' => 'file', 'session.cookie' => 'drive_phangan_preview_session', 'session.domain' => null,
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'cache.default' => 'file',
            'queue.default' => null, 'mail.default' => 'array', 'notifications.telegram.bot_token' => null,
            'notifications.telegram.admin_chat_id' => null, 'notifications.telegram.api_base_url' => 'http://127.0.0.1:9',
            'website.contacts' => [], 'security.trusted_proxies' => [], 'security.csp.enabled' => true,
            'health.redis' => false, 'health.scheduler' => false]);
    }
}
