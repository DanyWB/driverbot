<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Website\Content\PublishedVehiclePresentation;
use App\Domain\Website\Content\VehicleWebsiteProfileService;
use App\Domain\Website\Content\WebsiteContentSchema;
use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\Media\WebsiteMediaStorage;
use App\Domain\Website\Media\WebsiteRasterVariants;
use App\Domain\Website\PublicCatalog;
use App\Models\Category;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

class WebsitePresentationFoundationTest extends TestCase
{
    private User $admin;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        // Real commits are essential for file-compensation tests. The existing
        // financial migrations intentionally reject down(), so use a fresh,
        // disposable in-memory database rather than migrate:rollback teardown.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh')->assertSuccessful();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('private');
        Storage::fake('public');
        config(['website.cms.enabled' => false, 'filesystems.disks.private.driver' => 'local', 'filesystems.disks.private.serve' => false]);
        $this->admin = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['type' => 'car', 'category_id' => null, 'name' => 'Physical car', 'is_active' => true, 'is_visible_for_booking' => true]);
    }

    public function test_default_off_selector_does_not_query_any_presentation_or_catalog_table(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertNull(app(PublishedVehiclePresentation::class)->forVehicle($this->vehicle, 'en'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_private_artwork_preserves_alpha_no_upscale_and_does_not_touch_fleet_or_telegram(): void
    {
        Storage::disk('public')->put('vehicles/original.jpg', 'EXISTING-FLEET');
        $photo = $this->vehicle->photos()->create(['disk' => 'public', 'file_path' => 'vehicles/original.jpg']);
        $photoBefore = $photo->fresh()->getAttributes();
        Storage::disk('private')->put('telegram-photo-cache/existing.jpg', 'EXISTING-TG');
        $asset = $this->asset();
        $this->assertSame('artwork', $asset->kind);
        $this->assertSame('image/png', $asset->mime);
        $this->assertSame(32, $asset->width);
        $this->assertSame(16, $asset->height);
        $this->assertCount(5, Storage::disk('private')->allFiles());
        foreach ($asset->variants as $variant) {
            $bytes = Storage::disk('private')->get($variant['path']);
            $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
            $this->assertSame(hash('sha256', $bytes), $variant['sha256']);
            $image = imagecreatefromstring($bytes);
            $this->assertSame(32, imagesx($image));
            $this->assertSame(16, imagesy($image));
            $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
            imagedestroy($image);
        }
        $this->assertSame('EXISTING-FLEET', Storage::disk('public')->get('vehicles/original.jpg'));
        $this->assertSame($photoBefore, $photo->fresh()->getAttributes());
        $this->assertSame('EXISTING-TG', Storage::disk('private')->get('telegram-photo-cache/existing.jpg'));
        $this->assertSame(1, $this->vehicle->photos()->count());
        $this->assertSame(['vehicles/original.jpg'], Storage::disk('public')->allFiles());
        foreach (['disk', 'original_path', 'original_sha256', 'variants', 'created_by_admin_id'] as $key) {
            $this->assertArrayNotHasKey($key, $asset->toArray());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    // The decoder budget deliberately includes the current heap. Keep this
    // positive decode fixture independent of memory retained by unrelated tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_large_image_is_resized_without_crop_or_upscale(): void
    {
        $image = app(WebsiteRasterVariants::class)->build($this->png(1200, 600));
        foreach (['card' => [480, 240], 'card_2x' => [960, 480], 'detail' => [1200, 600]] as $key => $dimensions) {
            $this->assertSame($dimensions, [$image['variants'][$key]['width'], $image['variants'][$key]['height']]);
        }
    }

    public function test_still_jpeg_and_webp_are_validated_and_jpeg_orientation_is_normalized(): void
    {
        $source = imagecreatefromstring($this->png()->getContent());
        foreach (['jpeg', 'webp'] as $format) {
            ob_start();
            if ($format === 'jpeg') {
                imagejpeg($source);
            } else {
                imagewebp($source);
            }
            $bytes = ob_get_clean();
            if ($format === 'jpeg' && function_exists('exif_read_data')) {
                $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvV', 0x0112, 3, 1).pack('v', 6)."\0\0".pack('V', 0);
                $bytes = substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
            }
            $built = app(WebsiteRasterVariants::class)->build(UploadedFile::fake()->createWithContent('synthetic.'.$format, $bytes));
            $rotated = $format === 'jpeg' && function_exists('exif_read_data');
            $this->assertSame($rotated ? [16, 32] : [32, 16], [$built['width'], $built['height']]);
            $this->assertSame('image/'.$format, $built['mime']);
            foreach ($built['variants'] as $variant) {
                $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($variant['contents']));
                $this->assertStringNotContainsString('Exif', $variant['contents']);
            }
        }
        imagedestroy($source);
    }

    public function test_raster_rejects_untrusted_formats_animation_and_predecode_resource_limits(): void
    {
        $png = $this->png()->getContent();
        $animation = substr($png, 0, 33).pack('N', 8).'acTL'.pack('NN', 2, 0).pack('N', 0).substr($png, 33);
        $cases = [
            [UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), []],
            [UploadedFile::fake()->createWithContent('fake.png', 'not an image'), []],
            [UploadedFile::fake()->createWithContent('animated.png', $animation), []],
            [$this->png(), ['website.cms.media.max_bytes' => 8]],
            [$this->png(), ['website.cms.media.max_pixels' => 10]],
            [$this->png(), ['website.cms.media.max_dimension' => 8]],
            [$this->png(), ['website.cms.media.memory_budget_bytes' => 1]],
            [$this->png(), ['website.cms.media.max_variant_bytes' => 1]],
        ];
        $defaults = config('website.cms.media');
        foreach ($cases as [$upload, $limits]) {
            config(['website.cms.media' => $defaults]);
            config($limits);
            $this->invalid(fn () => app(WebsiteMediaService::class)->store($this->admin, $this->vehicle, $upload, 'artwork'));
            $this->assertDatabaseCount('website_media_assets', 0);
            $this->assertSame([], Storage::disk('private')->allFiles());
        }
    }

    public function test_every_write_enforces_fresh_active_verified_full_admin_in_the_service(): void
    {
        $asset = $this->asset();
        $service = app(VehicleWebsiteProfileService::class);
        $service->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0);
        foreach ([['role' => 'manager'], ['is_active' => false], ['email_verified_at' => null]] as $attributes) {
            $actor = User::factory()->create($attributes);
            foreach ([
                fn () => app(WebsiteMediaService::class)->store($actor, $this->vehicle, $this->png(), 'photo'),
                fn () => app(WebsiteMediaService::class)->delete($actor, $asset),
                fn () => $service->saveDraft($actor, $this->vehicle, $this->content(), null, 1),
                fn () => $service->publish($actor, $this->vehicle, 1),
                fn () => $service->unpublish($actor, $this->vehicle, 1),
            ] as $write) {
                try {
                    $write();
                    $this->fail('Unauthorized service write succeeded.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        User::query()->whereKey($this->admin->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->publish($this->admin, $this->vehicle, 1);
    }

    public function test_drafts_publications_and_revision_conflicts_are_isolated_per_physical_vehicle(): void
    {
        config(['website.cms.enabled' => true]);
        $asset = $this->asset();
        $service = app(VehicleWebsiteProfileService::class);
        $selector = app(PublishedVehiclePresentation::class);
        $this->assertSame(1, $service->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0)->revision);
        $this->assertNull($selector->forVehicle($this->vehicle, 'en'));
        $this->assertSame(2, $service->publish($this->admin, $this->vehicle, 1)->published_revision);
        $published = $selector->forVehicle($this->vehicle, 'en');
        $this->assertSame('Published title', $published['title']);
        $this->assertNull($selector->forVehicle($this->vehicle, 'uk'));
        $this->assertSame('Illustration of test car', $published['cover']['alt']);
        $json = json_encode($published, JSON_THROW_ON_ERROR);
        foreach (['website-media/', 'original_path', 'disk', 'draft_content', 'created_by_admin_id'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $service->saveDraft($this->admin, $this->vehicle, ['title' => ['en' => 'UNPUBLISHED SECRET']], null, 2);
        $this->assertSame($published, $selector->forVehicle($this->vehicle, 'en'));
        foreach ([fn () => $service->saveDraft($this->admin, $this->vehicle, [], null, 2), fn () => $service->publish($this->admin, $this->vehicle, 2), fn () => $service->unpublish($this->admin, $this->vehicle, 2)] as $write) {
            $this->invalid($write);
            $this->assertSame(3, VehicleWebsiteProfile::query()->firstOrFail()->revision);
        }
        $second = Vehicle::factory()->create(['type' => 'car', 'category_id' => null, 'name' => $this->vehicle->name, 'is_visible_for_booking' => true]);
        $this->assertNull($selector->forVehicle($second, 'en'));
        $service->saveDraft($this->admin, $second, $this->content(), null, 0);
        $this->assertDatabaseCount('vehicle_website_profiles', 2);
        $service->unpublish($this->admin, $this->vehicle, 3);
        $this->assertNull($selector->forVehicle($this->vehicle, 'en'));
        $this->assertSame('UNPUBLISHED SECRET', VehicleWebsiteProfile::query()->where('vehicle_id', $this->vehicle->id)->firstOrFail()->draft_content['title']['en']);
    }

    public function test_schema_rejects_unknown_executable_seo_and_locale_fields(): void
    {
        foreach ([['canonical' => 'https://evil.invalid'], ['noindex' => false], ['html' => '<script>bad</script>'], ['title' => ['en' => '<img src=x>']], ['title' => ['de' => 'No']], ['title' => ['en' => str_repeat('x', 161)]], ['schema_version' => '1'], ['title' => ['en' => "bad\0text"]], ['title' => ['en' => ['nested']]]] as $content) {
            $this->invalid(fn () => app(VehicleWebsiteProfileService::class)->saveDraft($this->admin, $this->vehicle, $content, null, 0));
        }
        $normalized = app(WebsiteContentSchema::class)->normalize(['title' => ['en' => '  Plain "quoted" title  '], 'summary' => ['ru' => "Строка 1\r\nСтрока 2"]]);
        $this->assertSame('Plain "quoted" title', $normalized['title']['en']);
        $this->assertSame("Строка 1\nСтрока 2", $normalized['summary']['ru']);
        $this->assertDatabaseCount('vehicle_website_profiles', 0);
    }

    public function test_cross_vehicle_missing_alt_and_corrupt_media_cannot_publish(): void
    {
        $asset = $this->asset();
        $other = Vehicle::factory()->create();
        $service = app(VehicleWebsiteProfileService::class);
        $this->invalid(fn () => $service->saveDraft($this->admin, $other, $this->content(), $asset->id, 0));
        $service->saveDraft($this->admin, $this->vehicle, ['title' => ['uk' => 'Тест']], $asset->id, 0);
        $this->invalid(fn () => $service->publish($this->admin, $this->vehicle, 1));
        $service->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 1);
        Storage::disk('private')->put($asset->variants['card']['path'], 'CORRUPTED');
        try {
            $service->publish($this->admin, $this->vehicle, 2);
            $this->fail('Corrupt image published.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull(VehicleWebsiteProfile::query()->firstOrFail()->published_at);
        $this->assertSame(2, VehicleWebsiteProfile::query()->firstOrFail()->revision);
    }

    public function test_publication_and_selection_recheck_current_fleet_eligibility(): void
    {
        config(['website.cms.enabled' => true]);
        $service = app(VehicleWebsiteProfileService::class);
        $service->saveDraft($this->admin, $this->vehicle, $this->content(), null, 0);
        foreach ([['is_active' => false], ['is_visible_for_booking' => false], ['archived_at' => now()]] as $change) {
            $this->vehicle->forceFill($change)->save();
            $this->invalid(fn () => $service->publish($this->admin, $this->vehicle, 1));
            $this->vehicle->forceFill(['is_active' => true, 'is_visible_for_booking' => true, 'archived_at' => null])->save();
        }
        $category = Category::factory()->create(['vehicle_type' => 'scooter', 'is_active' => true]);
        $this->vehicle->update(['category_id' => $category->id]);
        $this->invalid(fn () => $service->publish($this->admin, $this->vehicle, 1));
        $category->update(['vehicle_type' => 'car', 'is_active' => false]);
        $this->invalid(fn () => $service->publish($this->admin, $this->vehicle, 1));
        $category->update(['is_active' => true]);
        $service->publish($this->admin, $this->vehicle, 1);
        $this->assertNotNull(app(PublishedVehiclePresentation::class)->forVehicle($this->vehicle, 'en'));
        Vehicle::query()->whereKey($this->vehicle->id)->update(['is_active' => false]);
        $this->assertNull(app(PublishedVehiclePresentation::class)->forVehicle($this->vehicle, 'en'));
    }

    public function test_storage_compensates_audit_failure_and_outer_transaction_rollback(): void
    {
        $audit = Mockery::mock(AdminAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('AUDIT FAILED'));
        $this->app->instance(AdminAuditService::class, $audit);
        try {
            $this->asset();
            $this->fail('Audit failure ignored.');
        } catch (RuntimeException $error) {
            $this->assertSame('AUDIT FAILED', $error->getMessage());
        }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('website_media_assets', 0);
        $this->app->forgetInstance(AdminAuditService::class);
        DB::beginTransaction();
        $this->asset();
        $this->assertCount(4, Storage::disk('private')->allFiles());
        DB::rollBack();
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('website_media_assets', 0);
    }

    public function test_delete_refuses_references_then_commits_only_its_own_files(): void
    {
        $asset = $this->asset();
        $other = $this->asset();
        $profiles = app(VehicleWebsiteProfileService::class);
        $profiles->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0);
        $profiles->publish($this->admin, $this->vehicle, 1);
        $profiles->saveDraft($this->admin, $this->vehicle, $this->content(), null, 2);
        $this->invalid(fn () => app(WebsiteMediaService::class)->delete($this->admin, $asset));
        $profiles->unpublish($this->admin, $this->vehicle, 3);
        DB::beginTransaction();
        app(WebsiteMediaService::class)->delete($this->admin, $asset);
        $this->assertCount(8, Storage::disk('private')->allFiles());
        DB::rollBack();
        $this->assertFalse($asset->fresh()->trashed());
        app(WebsiteMediaService::class)->delete($this->admin, $asset);
        $this->assertTrue(WebsiteMediaAsset::withTrashed()->findOrFail($asset->id)->trashed());
        $this->assertCount(4, Storage::disk('private')->allFiles());
        $this->assertCount(3, app(WebsiteMediaService::class)->assertUsable($other, $this->vehicle->id));
        app(WebsiteMediaService::class)->delete($this->admin, $asset);
        $this->assertCount(4, Storage::disk('private')->allFiles());
    }

    public function test_forged_paths_are_rejected_before_delete_and_existing_fleet_files_survive(): void
    {
        $asset = $this->asset();
        Storage::disk('public')->put('vehicles/keep.jpg', 'KEEP');
        $asset->forceFill(['original_path' => '../public/vehicles/keep.jpg'])->save();
        try {
            app(WebsiteMediaService::class)->delete($this->admin, $asset);
            $this->fail('Escaping path accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertFalse($asset->fresh()->trashed());
        $this->assertSame('KEEP', Storage::disk('public')->get('vehicles/keep.jpg'));
        $this->assertCount(4, Storage::disk('private')->allFiles());
    }

    public function test_existing_directory_cannot_be_overwritten_on_generated_id_collision(): void
    {
        $id = (string) Str::uuid();
        Storage::disk('private')->put('website-media/'.$id.'/original.png', 'PREEXISTING');
        Str::createUuidsUsing(fn () => Uuid::fromString($id));
        try {
            try {
                $this->asset();
                $this->fail('Existing directory overwritten.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            Str::createUuidsNormally();
        }
        $this->assertSame('PREEXISTING', Storage::disk('private')->get('website-media/'.$id.'/original.png'));
        $this->assertDatabaseCount('website_media_assets', 0);
    }

    public function test_trusted_shared_storage_symlink_is_allowed_but_child_symlink_is_rejected(): void
    {
        $root = Storage::disk('private')->path('');
        $alias = rtrim($root, '/\\').'-trusted-link';
        if (! @symlink($root, $alias)) {
            $this->markTestSkipped('OS does not permit synthetic directory symlinks. Linux must run this boundary test.');
        }
        $child = null;
        try {
            config(['filesystems.disks.private.root' => $alias]);
            Storage::forgetDisk('private');
            $storage = new WebsiteMediaStorage;
            $id = (string) Str::uuid();
            $storage->claim($id);
            $path = 'website-media/'.$id.'/card.webp';
            $storage->write($id, $path, 'SAFE');
            $this->assertSame('SAFE', $storage->read($id, $path, 100));
            $storage->delete($id, [$path]);
            $bad = (string) Str::uuid();
            $child = rtrim($root, '/\\').'/website-media/'.$bad;
            $this->assertTrue(symlink(Storage::disk('public')->path(''), $child));
            $this->expectException(RuntimeException::class);
            $storage->write($bad, 'website-media/'.$bad.'/card.webp', 'BAD');
        } finally {
            if ($child !== null && is_link($child)) {
                unlink($child);
            }
            if (is_link($alias)) {
                unlink($alias);
            }
            config(['filesystems.disks.private.root' => $root]);
            Storage::forgetDisk('private');
        }
    }

    public function test_configured_private_disk_must_not_resolve_into_public_storage(): void
    {
        config(['filesystems.disks.private.root' => Storage::disk('public')->path('')]);
        Storage::forgetDisk('private');
        $this->expectException(RuntimeException::class);
        (new WebsiteMediaStorage)->claim((string) Str::uuid());
    }

    public function test_failed_partial_file_write_compensates_only_the_new_asset(): void
    {
        $existing = $this->asset();
        $storage = Mockery::mock(WebsiteMediaStorage::class)->makePartial();
        $storage->shouldReceive('write')->once()->withArgs(fn ($id, $path) => str_ends_with($path, '/original.png'))->passthru();
        $storage->shouldReceive('write')->once()->withArgs(fn ($id, $path) => str_ends_with($path, '/card.webp'))->andThrow(new RuntimeException('DISK WRITE FAILED'));
        $this->app->instance(WebsiteMediaStorage::class, $storage);
        try {
            $this->asset();
            $this->fail('Partial disk failure ignored.');
        } catch (RuntimeException $error) {
            $this->assertSame('DISK WRITE FAILED', $error->getMessage());
        }
        $this->assertCount(4, Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('website_media_assets', 1);
        $this->assertCount(3, app(WebsiteMediaService::class)->assertUsable($existing, $this->vehicle->id));
    }

    public function test_published_listing_uses_verified_snapshot_metadata_not_original_file_io(): void
    {
        config(['website.cms.enabled' => true]);
        $asset = $this->asset();
        $profiles = app(VehicleWebsiteProfileService::class);
        $profiles->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0);
        $profiles->publish($this->admin, $this->vehicle, 1);
        $selector = app(PublishedVehiclePresentation::class);
        $this->assertNotNull($selector->forVehicle($this->vehicle, 'en'));
        $original = Storage::disk('private')->get($asset->original_path);
        Storage::disk('private')->put($asset->original_path, 'CORRUPT ORIGINAL');
        // B/C/D deliberately removes original+3-variant reads from listing.
        // The heavy checker still rejects corruption on publish/diagnostics;
        // public derivative delivery validates its selected bytes separately.
        $this->assertNotNull($selector->forVehicle($this->vehicle, 'en'));
        try {
            app(WebsiteMediaService::class)->assertUsable($asset, $this->vehicle->id);
            $this->fail('Heavy publication verification accepted a corrupt original.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        Storage::disk('private')->put($asset->original_path, $original);
        $manifest = $asset->variants;
        $manifest['card']['path'] = 'website-media/'.Str::uuid().'/card.webp';
        $asset->forceFill(['variants' => $manifest])->save();
        $this->assertNull($selector->forVehicle($this->vehicle, 'en'));
        $asset->forceFill(['variants' => ['card' => 'not a typed manifest']])->save();
        $this->assertNull($selector->forVehicle($this->vehicle, 'en'));
        $this->assertCount(4, Storage::disk('private')->allFiles());
    }

    public function test_publish_and_unpublish_audit_failure_rolls_back_snapshot_and_revision(): void
    {
        config(['website.cms.enabled' => true]);
        $profiles = app(VehicleWebsiteProfileService::class);
        $profiles->saveDraft($this->admin, $this->vehicle, $this->content(), null, 0);
        $audit = Mockery::mock(AdminAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('AUDIT FAILED'));
        $this->app->instance(AdminAuditService::class, $audit);
        try {
            app(VehicleWebsiteProfileService::class)->publish($this->admin, $this->vehicle, 1);
            $this->fail('Publish ignored audit failure.');
        } catch (RuntimeException) {
            $this->assertNull(VehicleWebsiteProfile::query()->firstOrFail()->published_at);
            $this->assertSame(1, VehicleWebsiteProfile::query()->firstOrFail()->revision);
        }
        $this->app->forgetInstance(AdminAuditService::class);
        app(VehicleWebsiteProfileService::class)->publish($this->admin, $this->vehicle, 1);
        $audit = Mockery::mock(AdminAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('AUDIT FAILED'));
        $this->app->instance(AdminAuditService::class, $audit);
        try {
            app(VehicleWebsiteProfileService::class)->unpublish($this->admin, $this->vehicle, 2);
            $this->fail('Unpublish ignored audit failure.');
        } catch (RuntimeException) {
            $this->assertNotNull(VehicleWebsiteProfile::query()->firstOrFail()->published_at);
            $this->assertSame(2, VehicleWebsiteProfile::query()->firstOrFail()->revision);
            $this->assertSame('Published title', app(PublishedVehiclePresentation::class)->forVehicle($this->vehicle, 'en')['title']);
        }
    }

    public function test_delete_audit_failure_and_draft_reference_preserve_asset_and_files(): void
    {
        $asset = $this->asset();
        $profiles = app(VehicleWebsiteProfileService::class);
        $profiles->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0);
        $this->invalid(fn () => app(WebsiteMediaService::class)->delete($this->admin, $asset));
        $profiles->saveDraft($this->admin, $this->vehicle, $this->content(), null, 1);
        $audit = Mockery::mock(AdminAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('AUDIT FAILED'));
        $this->app->instance(AdminAuditService::class, $audit);
        try {
            app(WebsiteMediaService::class)->delete($this->admin, $asset);
            $this->fail('Delete ignored audit failure.');
        } catch (RuntimeException) {
            $this->assertFalse($asset->fresh()->trashed());
            $this->assertCount(4, Storage::disk('private')->allFiles());
        }
    }

    public function test_vehicle_deletion_keeps_unassigned_private_library_files_and_drops_only_profile(): void
    {
        $asset = $this->asset();
        app(VehicleWebsiteProfileService::class)->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0);
        $this->vehicle->delete();
        $this->assertNull($asset->fresh()->vehicle_id);
        $this->assertDatabaseCount('vehicle_website_profiles', 0);
        $this->assertCount(4, Storage::disk('private')->allFiles());
    }

    public function test_published_foundation_does_not_change_existing_public_catalog_prices_or_fleet_data(): void
    {
        $this->seed();
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
        $this->vehicle->photos()->create(['disk' => 'public', 'file_path' => 'vehicles/reference.jpg']);
        $before = app(PublicCatalog::class)->present($this->vehicle->fresh(), 'en');
        $quoteBefore = app(PublicCatalog::class)->quote($this->vehicle, '2026-11-10', '2026-11-12');
        $this->assertSame(2000, $quoteBefore['final_total']);
        $vehicleBefore = $this->vehicle->fresh()->getAttributes();
        $pricesBefore = DB::table('vehicle_price_tiers')->get()->toJson();
        $asset = $this->asset();
        $service = app(VehicleWebsiteProfileService::class);
        $service->saveDraft($this->admin, $this->vehicle, $this->content(), $asset->id, 0);
        $service->publish($this->admin, $this->vehicle, 1);
        config(['website.cms.enabled' => true]);
        $this->assertNotNull(app(PublishedVehiclePresentation::class)->forVehicle($this->vehicle, 'en'));
        $this->assertSame($before, app(PublicCatalog::class)->present($this->vehicle->fresh(), 'en'));
        $this->assertSame($vehicleBefore, $this->vehicle->fresh()->getAttributes());
        $this->assertSame($pricesBefore, DB::table('vehicle_price_tiers')->get()->toJson());
        $this->assertSame($quoteBefore, app(PublicCatalog::class)->quote($this->vehicle->fresh(), '2026-11-10', '2026-11-12'));
        $this->assertDatabaseCount('vehicle_photos', 1);
        $this->assertDatabaseCount('bookings', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function asset(): WebsiteMediaAsset
    {
        return app(WebsiteMediaService::class)->store($this->admin, $this->vehicle, $this->png(), 'artwork', ['en' => 'Illustration of test car']);
    }

    private function png(int $width = 32, int $height = 16): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 2, 2, min(10, $width - 1), min(10, $height - 1), imagecolorallocatealpha($image, 230, 10, 10, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('synthetic.png', $bytes);
    }

    private function content(): array
    {
        return ['schema_version' => 1, 'title' => ['en' => 'Published title'], 'summary' => ['en' => 'Synthetic test summary'], 'meta_title' => ['en' => 'Test metadata']];
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid foundation write was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
