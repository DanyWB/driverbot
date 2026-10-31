<?php

namespace Tests\Feature;

use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Website\Content\AdminWebsitePresenter;
use App\Domain\Website\Content\PublishedVehiclePresentation;
use App\Domain\Website\Content\PublishedWebsiteDocuments;
use App\Domain\Website\Content\VehicleWebsiteProfileService;
use App\Domain\Website\Content\WebsiteDocumentService;
use App\Domain\Website\Content\WebsitePageAssembler;
use App\Domain\Website\Content\WebsitePageSchema;
use App\Domain\Website\Content\WebsiteSettingsSchema;
use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\Media\WebsiteMediaStorage;
use App\Domain\Website\WebsitePreviewGuard;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use App\Models\WebsitePage;
use App\Models\WebsitePageRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Tests\TestCase;

class WebsiteCmsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('private');
        Storage::fake('public');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'app.url' => 'http://localhost',
            'website.enabled' => true, 'website.domain' => null, 'website.url' => 'https://rent.example.invalid',
            'website.demo' => true, 'website.preview.enabled' => false, 'website.cms.enabled' => true,
            'website.cms.admin_enabled' => true, 'website.cms.policy_publishing_enabled' => false,
            'website.accepting_requests' => false, 'filesystems.disks.private.serve' => false]);
        $this->admin = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['type' => 'car', 'category_id' => null, 'name' => 'Physical CMS vehicle', 'is_active' => true, 'is_visible_for_booking' => true]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_disabled_flags_reject_before_binding_even_without_any_cms_tables(): void
    {
        foreach (['website_page_revisions', 'website_pages', 'website_site_settings', 'vehicle_website_profiles', 'website_media_assets'] as $table) {
            Schema::drop($table);
        }
        config(['website.cms.admin_enabled' => false, 'website.cms.enabled' => false]);
        $this->actingAs($this->admin);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['/website/cards', '/website/cards/'.$this->vehicle->id, '/website/media/'.Str::uuid().'/card', '/website/pages/home', '/website/settings',
            '/website-media/'.Str::uuid().'/card/'.str_repeat('a', 64).'.webp'] as $path) {
            $this->get($path)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $cmsQueries = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/(?:website_pages|website_site_settings|website_media_assets|vehicle_website_profiles)/', $query['query']));
        $this->assertSame([], $cmsQueries);
        DB::disableQueryLog();
    }

    public function test_admin_http_reads_and_writes_enforce_active_verified_full_admin(): void
    {
        $this->get('/website/cards')->assertRedirect('/login');
        foreach ([['role' => 'manager'], ['is_active' => false], ['email_verified_at' => null]] as $attributes) {
            $actor = User::factory()->create($attributes);
            $this->actingAs($actor)->get('/website/cards')->assertForbidden();
            $this->postJson('/website/pages/home/draft', ['expected_revision' => 0, 'content' => $this->page()])->assertForbidden();
            $this->get('/website/media/'.Str::uuid().'/card')->assertForbidden();
        }
        $this->assertDatabaseCount('website_pages', 0);
        $this->actingAs($this->admin)->get('/website/cards')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('website-admin/cards/Index', false)->where('websiteCms.can_manage', true));
        $this->get('/website/cards/'.$this->vehicle->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('website-admin/cards/Edit', false)->where('profile.revision', 0)->where('profile.published', null));
    }

    public function test_authoring_is_never_exposed_on_customer_preview_or_wrong_host(): void
    {
        $this->actingAs($this->admin);
        $response = $this->get('http://untrusted.invalid/website/cards');
        $this->assertContains($response->status(), [400, 404]);
        config(['website.preview.enabled' => true, 'website.preview.host' => 'localhost', 'website.preview.public_access' => true]);
        $this->get('/website/cards')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/website/pages/home/draft', ['expected_revision' => 0, 'content' => $this->page()])->assertNotFound();
        $this->assertDatabaseCount('website_pages', 0);
    }

    public function test_csrf_is_really_enabled_for_rejection_before_cms_mutation(): void
    {
        $this->actingAs($this->admin);
        $this->app->instance('env', 'production');
        $this->post('/website/pages/home/draft', ['expected_revision' => 0, 'content' => $this->page()])->assertStatus(419);
        $this->assertDatabaseCount('website_pages', 0);
    }

    public function test_page_workflow_retains_published_snapshot_and_restore_only_changes_draft(): void
    {
        $service = app(WebsiteDocumentService::class);
        $reader = app(PublishedWebsiteDocuments::class);
        $this->assertSame(1, $service->savePageDraft($this->admin, 'home', $this->page(), 0)->revision);
        $this->assertNull($reader->read('home', 'en')['page']);
        $this->assertSame(2, $service->publishPage($this->admin, 'home', 1)->published_revision);
        $this->assertSame('CMS page', $reader->read('home', 'en')['page']['title']);
        $this->assertNull($reader->read('home', 'uk')['page']);
        $service->savePageDraft($this->admin, 'home', $this->page('NEW DRAFT'), 2);
        $this->assertSame('CMS page', $reader->read('home', 'en')['page']['title']);
        $service->publishPage($this->admin, 'home', 3);
        $first = WebsitePageRevision::query()->where('revision', 2)->firstOrFail()->getAttributes();
        $service->restorePage($this->admin, 'home', 2, 4);
        $this->assertSame('NEW DRAFT', $reader->read('home', 'en')['page']['title']);
        $this->assertSame('CMS page', WebsitePage::query()->firstOrFail()->draft_content['title']['en']);
        $this->assertSame($first, WebsitePageRevision::query()->where('revision', 2)->firstOrFail()->getAttributes());
        $service->publishPage($this->admin, 'home', 5);
        $this->assertSame('CMS page', $reader->read('home', 'en')['page']['title']);
        $service->unpublishPage($this->admin, 'home', 6);
        $this->assertNull($reader->read('home', 'en')['page']);
        $this->assertDatabaseCount('website_page_revisions', 3);
    }

    public function test_page_and_settings_stale_revisions_never_overwrite(): void
    {
        $service = app(WebsiteDocumentService::class);
        $service->savePageDraft($this->admin, 'home', $this->page(), 0);
        foreach ([fn () => $service->savePageDraft($this->admin, 'home', $this->page('STALE'), 0), fn () => $service->publishPage($this->admin, 'home', 0),
            fn () => $service->unpublishPage($this->admin, 'home', 0), fn () => $service->restorePage($this->admin, 'home', 999, 0)] as $action) {
            $this->invalid($action);
        }
        $service->saveSettingsDraft($this->admin, [], 0);
        $this->invalid(fn () => $service->saveSettingsDraft($this->admin, ['business' => ['name' => 'STALE']], 0));
        $this->assertSame('CMS page', WebsitePage::query()->firstOrFail()->draft_content['title']['en']);
        $this->assertSame(1, WebsitePage::query()->firstOrFail()->revision);
    }

    public function test_page_and_settings_service_authorization_does_not_depend_on_controller(): void
    {
        $actor = User::factory()->create(['role' => 'manager']);
        $service = app(WebsiteDocumentService::class);
        foreach ([fn () => $service->page($actor, 'home'), fn () => $service->settings($actor), fn () => $service->savePageDraft($actor, 'home', [], 0),
            fn () => $service->publishPage($actor, 'home', 0), fn () => $service->restorePage($actor, 'home', 1, 0), fn () => $service->saveSettingsDraft($actor, [], 0),
            fn () => $service->publishSettings($actor, 0)] as $action) {
            try {
                $action();
                $this->fail('Unprivileged service call succeeded.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_schemas_reject_executable_unknown_url_duplicate_and_oversized_documents(): void
    {
        $schema = app(WebsitePageSchema::class);
        $page = $this->page();
        $badImage = $page;
        $badImage['blocks'][0]['props']['image_ref'] = 'https://evil.invalid/image.webp';
        $badHtml = $page;
        $badHtml['blocks'][0]['props']['heading']['en'] = '<script>bad()</script>';
        $duplicate = $page;
        $duplicate['blocks'][] = $duplicate['blocks'][0];
        foreach ([['html' => '<script>bad()</script>'], ['canonical' => 'https://evil.invalid'], ['schema_version' => null],
            $badImage, $badHtml, $duplicate, ['blocks' => array_fill(0, 31, $page['blocks'][0])], ['title' => ['de' => 'Not supported']]] as $content) {
            $this->invalid(fn () => $schema->normalize($content));
        }
        $this->invalid(fn () => $schema->normalize($this->page() + ['unknown' => true]));
        $settings = app(WebsiteSettingsSchema::class);
        foreach ([['url' => 'https://evil.invalid'], ['indexable' => true], ['accepting_requests' => true], ['telegram_token' => 'SECRET'],
            ['navigation' => [['bad']]], ['navigation' => ['home', 'home']], ['contacts' => ['telegram' => 'https://t.me/']],
            ['contacts' => ['email' => 'mailto:test@example.invalid?bcc=other@example.invalid']], ['business' => ['name' => '<b>BAD</b>']]] as $content) {
            $this->invalid(fn () => $settings->normalize($content));
        }
        $this->assertSame(['telegram' => null, 'whatsapp' => null, 'email' => null, 'maps' => null], $settings->normalize([])['contacts']);
    }

    public function test_publishing_needs_complete_locale_blocks_and_policy_gate(): void
    {
        $service = app(WebsiteDocumentService::class);
        $page = $this->page();
        $page['title']['uk'] = 'Українська';
        $service->savePageDraft($this->admin, 'home', $page, 0);
        $this->invalid(fn () => $service->publishPage($this->admin, 'home', 1));
        $service->savePageDraft($this->admin, 'terms', $this->page(), 0);
        $this->invalid(fn () => $service->publishPage($this->admin, 'terms', 1));
        $this->assertNull(WebsitePage::query()->where('key', 'terms')->firstOrFail()->published_at);
    }

    public function test_title_and_seo_only_draft_cannot_replace_current_page_with_empty_body(): void
    {
        $this->actingAs($this->admin)->post('/website/pages/home/draft', ['expected_revision' => 0,
            'content' => ['title' => ['en' => 'SEO only'], 'meta_title' => ['en' => 'Search title'], 'blocks' => []]])->assertStatus(303);
        $this->postJson('/website/pages/home/publish', ['expected_revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('blocks');
        $this->assertNull(WebsitePage::query()->where('key', 'home')->firstOrFail()->published_at);
        $this->assertDatabaseCount('website_page_revisions', 0);
    }

    public function test_settings_only_publish_whitelisted_public_values_and_unpublish_cleanly(): void
    {
        $service = app(WebsiteDocumentService::class);
        $service->saveSettingsDraft($this->admin, ['business' => ['name' => 'SYNTHETIC CMS BRAND'], 'contacts' => [], 'navigation' => ['home', 'cars']], 0);
        $this->assertNull(app(PublishedWebsiteDocuments::class)->read('home', 'en')['settings']);
        $service->publishSettings($this->admin, 1);
        $settings = app(PublishedWebsiteDocuments::class)->read('home', 'en')['settings'];
        $this->assertSame('SYNTHETIC CMS BRAND', $settings['business']['name']);
        $this->assertNull($settings['contacts']['telegram']);
        $this->assertArrayNotHasKey('published_by_admin_id', $settings);
        $service->unpublishSettings($this->admin, 2);
        $this->assertNull(app(PublishedWebsiteDocuments::class)->read('home', 'en')['settings']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_all_six_typed_blocks_localize_without_arbitrary_markup_or_asset_urls(): void
    {
        $content = $this->page();
        foreach ([
            ['type' => 'catalog', 'props' => ['heading' => ['en' => 'Catalog'], 'vehicle_type' => 'car', 'limit' => 3]],
            ['type' => 'text_image', 'props' => ['heading' => ['en' => 'Text'], 'body' => ['en' => 'Plain body'], 'image_ref' => 'island']],
            ['type' => 'steps', 'props' => ['heading' => ['en' => 'Steps'], 'items' => [['id' => (string) Str::uuid(), 'title' => ['en' => 'Step one'], 'body' => ['en' => 'Step body']]]]],
            ['type' => 'faq', 'props' => ['heading' => ['en' => 'FAQ'], 'items' => [['id' => (string) Str::uuid(), 'question' => ['en' => 'Question'], 'answer' => ['en' => 'Answer']]]]],
            ['type' => 'contact_cta', 'props' => ['heading' => ['en' => 'Contact'], 'body' => ['en' => 'Contact body'], 'channel' => 'telegram', 'action_label' => ['en' => 'Contact us']]],
        ] as $block) {
            $content['blocks'][] = ['id' => (string) Str::uuid(), ...$block];
        }
        $service = app(WebsiteDocumentService::class);
        $service->savePageDraft($this->admin, 'home', $content, 0);
        $service->publishPage($this->admin, 'home', 1);
        $page = app(PublishedWebsiteDocuments::class)->read('home', 'en')['page'];
        $this->assertCount(6, $page['blocks']);
        $this->assertSame('Step one', $page['blocks'][3]['props']['items'][0]['title']);
        $this->assertSame('/website/images/island.webp', $page['blocks'][2]['props']['image']['url']);
        $this->assertSame('Answer', $page['blocks'][4]['props']['items'][0]['answer']);
        $this->assertArrayNotHasKey('created_by_admin_id', $page);
        $this->assertArrayNotHasKey('contacts', $page['blocks'][5]['props']);
    }

    public function test_public_ssr_uses_only_published_locale_metadata_and_preserves_canonical_origin(): void
    {
        $service = app(WebsiteDocumentService::class);
        $service->savePageDraft($this->admin, 'home', $this->page('CMS SSR TITLE'), 0);
        $service->publishPage($this->admin, 'home', 1);
        $service->savePageDraft($this->admin, 'home', $this->page('UNPUBLISHED SECRET'), 2);
        $this->get('/en')->assertOk()->assertSee('Synthetic heading')->assertDontSee('UNPUBLISHED SECRET')
            ->assertViewHas('cms_page', fn ($page) => $page['title'] === 'CMS SSR TITLE')
            ->assertViewHas('seo', fn ($seo) => $seo['title'] === 'CMS metadata' && $seo['canonical'] === 'https://rent.example.invalid/en');
        $this->get('/uk')->assertOk()->assertViewHas('cms_page', null)->assertDontSee('UNPUBLISHED SECRET');
        $this->actingAs($this->admin)->get('/website/pages/home/preview/en')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertViewHas('website', fn ($website) => $website['accepting_requests'] === false);
    }

    public function test_document_audit_failure_rolls_back_snapshot_and_history(): void
    {
        $service = app(WebsiteDocumentService::class);
        $service->savePageDraft($this->admin, 'home', $this->page(), 0);
        $audit = Mockery::mock(AdminAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('SYNTHETIC AUDIT FAILURE'));
        $this->app->instance(AdminAuditService::class, $audit);
        try {
            app(WebsiteDocumentService::class)->publishPage($this->admin, 'home', 1);
            $this->fail('Audit failure ignored.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull(WebsitePage::query()->firstOrFail()->published_at);
        $this->assertSame(1, WebsitePage::query()->firstOrFail()->revision);
        $this->assertDatabaseCount('website_page_revisions', 0);
    }

    public function test_media_is_private_until_published_and_corrupt_selected_derivative_is_404(): void
    {
        $asset = $this->asset();
        $url = $this->mediaUrl($asset);
        $this->get($url)->assertNotFound();
        $this->get('/website/media/'.$asset->id.'/card')->assertRedirect('/login')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->admin)->get('/website/media/'.$asset->id.'/card')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->publishCard($asset);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
        Storage::disk('private')->put($asset->variants['card']['path'], 'CORRUPT');
        $this->get($url)->assertNotFound();
    }

    public function test_media_revocation_checks_precede_conditionals_head_and_range(): void
    {
        $asset = $this->asset();
        $this->publishCard($asset);
        $url = $this->mediaUrl($asset);
        $headers = ['If-None-Match' => '"'.$asset->variants['card']['sha256'].'"', 'If-Modified-Since' => 'Wed, 31 Dec 2099 12:00:00 GMT', 'Range' => 'bytes=0-10'];
        $this->get($url, $headers)->assertOk();
        app(VehicleWebsiteProfileService::class)->unpublish($this->admin, $this->vehicle, 2);
        $this->get($url, $headers)->assertNotFound();
        $this->head($url, $headers)->assertNotFound();
        app(VehicleWebsiteProfileService::class)->publish($this->admin, $this->vehicle, 3);
        $this->vehicle->update(['is_visible_for_booking' => false]);
        $this->get($url, $headers)->assertNotFound();
        $this->head($url, $headers)->assertNotFound();
    }

    public function test_published_images_follow_trusted_indexability_but_private_and_demo_images_do_not(): void
    {
        $asset = $this->asset();
        $this->publishCard($asset);
        $url = $this->mediaUrl($asset);
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->app->instance('env', 'production');
        config(['website.demo' => false, 'website.indexable' => true, 'website.url' => 'https://rent.example.invalid']);
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'index, follow')->assertHeader('Cache-Control', 'no-store, private');
        $guard = Mockery::mock(WebsitePreviewGuard::class);
        $guard->shouldReceive('verifyRuntime')->once();
        $this->app->instance(WebsitePreviewGuard::class, $guard);
        config(['website.preview.enabled' => true, 'website.preview.host' => 'localhost', 'website.preview.public_access' => true]);
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        config(['website.preview.enabled' => false]);
        $this->actingAs($this->admin)->get('/website/media/'.$asset->id.'/card')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        config(['website.indexable' => false]);
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        config(['website.indexable' => true, 'website.demo' => true]);
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        config(['website.demo' => false]);
        app(VehicleWebsiteProfileService::class)->unpublish($this->admin, $this->vehicle, 2);
        $this->get($url)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_corrupt_scalar_or_string_published_content_falls_back_and_media_is_404(): void
    {
        $asset = $this->asset();
        $this->publishCard($asset);
        $url = $this->mediaUrl($asset);
        foreach ([123, 'not a content object', null] as $corrupt) {
            DB::table('vehicle_website_profiles')->where('vehicle_id', $this->vehicle->id)->update(['published_content' => json_encode($corrupt, JSON_THROW_ON_ERROR)]);
            $this->assertSame([], app(PublishedVehiclePresentation::class)->forVehicles([(int) $this->vehicle->id], 'en'));
            $this->get($url)->assertNotFound();
            $this->get('/en/cars')->assertOk()->assertDontSee('CMS card');
        }
    }

    public function test_successful_publication_normalizes_alt_fallback_without_phantom_draft_changes(): void
    {
        $asset = $this->asset();
        $this->publishCard($asset);
        $profile = VehicleWebsiteProfile::query()->where('vehicle_id', $this->vehicle->id)->firstOrFail();
        $dto = app(AdminWebsitePresenter::class)->card($this->admin, $this->vehicle, $profile);
        $this->assertFalse($dto['has_unpublished_changes']);
        $this->assertSame('Synthetic test artwork', $dto['draft']['image_alt']['en']);
        $this->assertSame($dto['draft'], $dto['published']);
        app(VehicleWebsiteProfileService::class)->saveDraft($this->admin, $this->vehicle, ['title' => ['en' => 'Changed draft']], $asset->id, 2);
        $dto = app(AdminWebsitePresenter::class)->card($this->admin, $this->vehicle, $profile->fresh());
        $this->assertTrue($dto['has_unpublished_changes']);
        $this->assertSame('CMS card', $dto['published']['title']['en']);
    }

    public function test_card_http_conflicts_ownership_and_upload_never_implicitly_publish(): void
    {
        $this->actingAs($this->admin)->post('/website/cards/'.$this->vehicle->id.'/media', ['media' => UploadedFile::fake()->image('synthetic.png', 32, 16), 'kind' => 'artwork', 'alt_text' => ['en' => 'SYNTHETIC']])->assertStatus(303);
        $asset = WebsiteMediaAsset::query()->sole();
        $this->assertDatabaseCount('vehicle_website_profiles', 0);
        $body = ['expected_revision' => 0, 'content' => ['title' => ['en' => 'Draft only']], 'cover_asset_id' => $asset->id];
        $this->post('/website/cards/'.$this->vehicle->id.'/draft', $body)->assertStatus(303);
        $this->postJson('/website/cards/'.$this->vehicle->id.'/draft', $body)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $other = Vehicle::factory()->create();
        $this->postJson('/website/cards/'.$other->id.'/draft', $body)->assertUnprocessable()->assertJsonValidationErrors('cover_asset_id');
        $this->get($this->mediaUrl($asset))->assertNotFound();
        $this->assertNull(VehicleWebsiteProfile::query()->sole()->published_at);
    }

    public function test_web_validation_returns_to_own_editor_after_private_image_overwrites_previous_url(): void
    {
        $asset = $this->asset();
        $this->publishCard($asset);
        $editor = route('admin.website.cards.edit', ['vehicle' => $this->vehicle->id]);
        $image = route('admin.website.media.preview', ['asset' => $asset->id, 'variant' => 'card']);
        $this->actingAs($this->admin)->get($editor)->assertOk();
        $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame($image, session()->previousUrl());
        $headers = ['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'];
        $this->post('/website/cards/'.$this->vehicle->id.'/draft', ['expected_revision' => 0,
            'content' => ['title' => ['en' => 'KEEP UNSAVED EDIT']], 'cover_asset_id' => null], $headers)
            ->assertRedirect($editor)->assertSessionHasErrors('revision')->assertSessionHasInput('content.title.en', 'KEEP UNSAVED EDIT');
        $this->assertSame(2, VehicleWebsiteProfile::query()->where('vehicle_id', $this->vehicle->id)->firstOrFail()->revision);
        $this->get($image)->assertOk();
        $this->post('/website/pages/home/draft', ['expected_revision' => 0, 'content' => ['html' => 'forbidden']], $headers)
            ->assertRedirect(route('admin.website.pages.edit', ['key' => 'home']))->assertSessionHasErrors('content');
        $this->get($image)->assertOk();
        $this->post('/website/settings/draft', ['expected_revision' => 0, 'content' => ['indexable' => true]], $headers)
            ->assertRedirect(route('admin.website.settings.edit'))->assertSessionHasErrors('content');
        $this->get($image)->assertOk();
        $this->post('/website/cards/'.$this->vehicle->id.'/media', ['kind' => 'artwork'], $headers)
            ->assertRedirect($editor)->assertSessionHasErrors('media');
    }

    public function test_batched_overlay_all_cms_queries_constant_and_no_file_reads(): void
    {
        $asset = $this->asset();
        $this->publishCard($asset);
        $ids = [(int) $this->vehicle->id];
        for ($i = 0; $i < 49; $i++) {
            $vehicle = Vehicle::factory()->create(['type' => 'car', 'category_id' => null, 'is_visible_for_booking' => true]);
            $service = app(VehicleWebsiteProfileService::class);
            $service->saveDraft($this->admin, $vehicle, ['title' => ['en' => 'Public '.$i]], null, 0);
            $service->publish($this->admin, $vehicle, 1);
            $ids[] = (int) $vehicle->id;
        }
        $documents = app(WebsiteDocumentService::class);
        $documents->savePageDraft($this->admin, 'cars', $this->page(), 0);
        $documents->publishPage($this->admin, 'cars', 1);
        $documents->saveSettingsDraft($this->admin, [], 0);
        $documents->publishSettings($this->admin, 1);
        $storage = Mockery::mock(WebsiteMediaStorage::class)->makePartial();
        $storage->shouldNotReceive('read');
        $this->app->instance(WebsiteMediaStorage::class, $storage);
        foreach ([array_slice($ids, 0, 1), $ids] as $batch) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $data = app(WebsitePageAssembler::class)->assemble('cars', 'en', ['vehicles' => array_map(fn ($id) => ['id' => $id], $batch)]);
            $this->assertCount(2, DB::getQueryLog());
            $this->assertCount(count($batch), $data['vehicles']);
            $this->assertNotNull($data['vehicles'][0]['presentation']['cover']);
            DB::disableQueryLog();
        }
    }

    private function asset(): WebsiteMediaAsset
    {
        return app(WebsiteMediaService::class)->store($this->admin, $this->vehicle, UploadedFile::fake()->image('synthetic.png', 32, 16), 'artwork', ['en' => 'Synthetic test artwork']);
    }

    private function publishCard(WebsiteMediaAsset $asset): void
    {
        $service = app(VehicleWebsiteProfileService::class);
        $service->saveDraft($this->admin, $this->vehicle, ['title' => ['en' => 'CMS card']], $asset->id, 0);
        $service->publish($this->admin, $this->vehicle, 1);
    }

    private function mediaUrl(WebsiteMediaAsset $asset): string
    {
        return route('website.media', ['asset' => $asset->id, 'variant' => 'card', 'sha256' => $asset->variants['card']['sha256']]);
    }

    private function page(string $title = 'CMS page'): array
    {
        return ['schema_version' => 1, 'title' => ['en' => $title], 'meta_title' => ['en' => 'CMS metadata'], 'blocks' => [[
            'id' => (string) Str::uuid(), 'type' => 'hero', 'props' => ['heading' => ['en' => 'Synthetic heading'], 'body' => ['en' => 'Synthetic body'],
                'image_ref' => 'home_hero', 'action' => 'cars', 'action_label' => ['en' => 'Browse cars']]]]];
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Unsupported CMS mutation accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
