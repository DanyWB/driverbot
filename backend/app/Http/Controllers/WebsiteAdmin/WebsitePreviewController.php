<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Domain\Website\Content\AdminWebsitePresenter;
use App\Domain\Website\Content\PublishedWebsiteDocuments;
use App\Domain\Website\Content\WebsiteDocumentService;
use App\Domain\Website\Content\WebsitePageAssembler;
use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\PublicCatalog;
use App\Domain\Website\WebsitePickupDate;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebsitePreviewController extends Controller
{
    use HandlesAdminContext;

    public function card(Request $request, string $vehicle, string $locale, AdminWebsitePresenter $presenter, WebsiteMediaService $media, PublicCatalog $catalog): Response
    {
        $actor = $this->admin($request);
        $model = Vehicle::query()->with(['photos', 'category', 'pricingPreset'])->findOrFail($vehicle);
        $profile = VehicleWebsiteProfile::query()->where('vehicle_id', $model->id)->first();
        $draft = $presenter->card($actor, $model, $profile)['draft'];
        $presentation = ['vehicle_id' => (int) $model->id, 'publication_revision' => null, 'locale' => $locale,
            'title' => $draft['title'][$locale] ?? '', 'summary' => $draft['summary'][$locale] ?? '',
            'meta_title' => $draft['meta_title'][$locale] ?? '', 'meta_description' => $draft['meta_description'][$locale] ?? '', 'cover' => null];
        if ($draft['cover_asset_id'] !== null) {
            $asset = WebsiteMediaAsset::query()->whereKey($draft['cover_asset_id'])->where('vehicle_id', $model->id)->first();
            if ($asset !== null) {
                $cover = $media->publicationManifest($asset);
                $cover['alt'] = $draft['image_alt'][$locale] ?? ($asset->alt_text[$locale] ?? '');
                foreach ($cover['variants'] as $key => &$variant) {
                    $variant['url'] = route('admin.website.media.preview', ['asset' => $asset->id, 'variant' => $key]);
                }
                unset($variant);
                $presentation['cover'] = $cover;
            }
        }

        return $this->render($request, $locale, ['preview_kind' => 'card', 'vehicle' => [...$catalog->present($model, $locale), 'presentation' => $presentation],
            'presentation' => $presentation, 'cms_page' => null, 'cms_catalogs' => ['all' => [], 'car' => [], 'scooter' => []]]);
    }

    public function page(Request $request, string $key, string $locale, WebsiteDocumentService $service, AdminWebsitePresenter $presenter,
        PublishedWebsiteDocuments $documents, WebsitePageAssembler $assembler): Response
    {
        $actor = $this->admin($request);
        $document = $service->page($actor, $key);
        $draft = $presenter->document($actor, $document);
        $page = $documents->localize($key, $draft['draft'], $locale, $draft['revision']);

        return $this->render($request, $locale, ['preview_kind' => 'page', 'vehicle' => null, 'presentation' => null,
            'cms_page' => $page, 'cms_catalogs' => $assembler->catalogs($page, $locale)]);
    }

    /** @param array<string,mixed> $data */
    private function render(Request $request, string $locale, array $data): Response
    {
        app()->setLocale($locale);
        $navigation = ['home' => route('website.home', ['locale' => $locale])];
        foreach (['cars', 'scooters'] as $catalog) {
            $navigation[$catalog] = route('website.catalog', ['locale' => $locale, 'catalog' => $catalog]);
        }
        foreach (['contact', 'terms', 'privacy'] as $policy) {
            $navigation[$policy] = route('website.policy', ['locale' => $locale, 'policy' => $policy]);
        }
        $languages = [];
        foreach (config('website.locales') as $language) {
            $languages[] = ['locale' => $language, 'url' => route($request->route()->getName(), [...$request->route()->parameters(), 'locale' => $language])];
        }
        $website = ['name' => config('website.name'), 'demo' => (bool) config('website.demo'), 'contacts' => [], 'business' => [],
            'accepting_requests' => false, 'terms_version' => null, 'channels' => [], 'today_date' => WebsitePickupDate::today()->format('Y-m-d'),
            'earliest_pickup_date' => WebsitePickupDate::earliest()->format('Y-m-d')];
        $seo = ['title' => 'Website draft preview', 'description' => '', 'canonical' => null, 'robots' => 'noindex, nofollow', 'hreflang' => [], 'og_image' => null, 'json_ld' => []];

        return response()->view('website.cms-preview', [...$data, ...compact('locale', 'navigation', 'languages', 'website', 'seo')])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
