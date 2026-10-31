<?php

namespace Tests\Feature;

use App\Domain\Website\Content\VehicleWebsiteProfileService;
use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\PublicCatalog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Models\WebsiteMediaAsset;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteCmsGalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $vehicle;

    private WebsiteMediaAsset $artwork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('private');
        Storage::fake('public');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32)), 'app.url' => 'http://localhost',
            'website.enabled' => true, 'website.domain' => null, 'website.url' => 'https://rent.example.invalid',
            'website.demo' => true, 'website.preview.enabled' => false, 'website.cms.enabled' => true,
            'website.cms.admin_enabled' => true, 'website.accepting_requests' => false]);
        $this->admin = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['name' => 'Distinct gallery fixture', 'type' => 'car', 'category_id' => null,
            'is_active' => true, 'is_visible_for_booking' => true]);
        foreach (['real-fleet-primary.jpg', 'real-fleet-side.jpg'] as $index => $path) {
            $photoBytes = UploadedFile::fake()->image($path, 80, 60)->get();
            $this->assertIsString($photoBytes);
            Storage::disk('public')->put($path, $photoBytes);
            VehiclePhoto::query()->create(['vehicle_id' => $this->vehicle->id, 'disk' => 'public', 'file_path' => $path,
                'alt_text' => 'Real Fleet photo '.$index, 'sort_order' => $index, 'is_primary' => $index === 0]);
        }
        $this->artwork = app(WebsiteMediaService::class)->store($this->admin, $this->vehicle,
            UploadedFile::fake()->image('distinct-catalog-artwork.png', 32, 16), 'artwork', ['en' => 'Catalog artwork only']);
        $service = app(VehicleWebsiteProfileService::class);
        $service->saveDraft($this->admin, $this->vehicle, ['title' => ['en' => 'Catalog artwork title']], $this->artwork->id, 0);
        $service->publish($this->admin, $this->vehicle, 1);
    }

    public function test_published_catalog_cover_does_not_replace_real_detail_photos(): void
    {
        $this->get('/en/cars')->assertOk()->assertSee($this->coverUrl(), false);
        $detail = $this->get($this->detailUrl())->assertOk();
        $html = $detail->getContent();
        $this->assertIsString($html);
        $this->assertSame($this->realGallerySources(), $this->gallerySources($html));
        $this->assertStringContainsString('/website-media/'.$this->artwork->id.'/card_2x/', $detail->viewData('seo')['og_image']);
    }

    public function test_private_cms_preview_keeps_artwork_in_card_and_real_photos_in_gallery(): void
    {
        $preview = $this->actingAs($this->admin)->get('/website/cards/'.$this->vehicle->id.'/preview/en')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('/website/media/'.$this->artwork->id.'/card', false);
        $html = $preview->getContent();
        $this->assertIsString($html);
        $this->assertSame($this->realGallerySources(), $this->gallerySources($html));
        $preview->assertDontSee('<form', false);
    }

    public function test_missing_real_photos_are_not_replaced_by_catalog_artwork_in_either_gallery(): void
    {
        VehiclePhoto::query()->where('vehicle_id', $this->vehicle->id)->delete();
        $this->get('/en/cars')->assertOk()->assertSee($this->coverUrl(), false);
        foreach ([$this->get($this->detailUrl()), $this->actingAs($this->admin)->get('/website/cards/'.$this->vehicle->id.'/preview/en')] as $response) {
            $response->assertOk()->assertSee('photo-pending', false);
            $html = $response->getContent();
            $this->assertIsString($html);
            $this->assertSame([], $this->gallerySources($html));
        }
    }

    private function detailUrl(): string
    {
        return app(PublicCatalog::class)->present($this->vehicle->fresh(), 'en')['url'];
    }

    private function coverUrl(): string
    {
        return route('website.media', ['asset' => $this->artwork->id, 'variant' => 'card', 'sha256' => $this->artwork->variants['card']['sha256']]);
    }

    /** @return list<string> */
    private function realGallerySources(): array
    {
        $primary = Storage::disk('public')->url('real-fleet-primary.jpg');

        return [$primary, $primary, Storage::disk('public')->url('real-fleet-side.jpg')];
    }

    /** @return list<string> */
    private function gallerySources(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $images = (new DOMXPath($document))->query('//div[contains(concat(" ",normalize-space(@class)," ")," vehicle-detail-visual ") or contains(concat(" ",normalize-space(@class)," ")," vehicle-gallery ")]//img');
        $this->assertNotFalse($images);
        $sources = [];
        foreach ($images as $image) {
            $this->assertInstanceOf(DOMElement::class, $image);
            $sources[] = $image->getAttribute('src');
        }

        return $sources;
    }
}
