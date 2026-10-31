<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Domain\Website\Content\PublishedVehiclePresentation;
use App\Domain\Website\Content\WebsiteContentAuthorizer;
use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\WebsiteSettings;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\WebsiteMediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use RuntimeException;

class WebsiteMediaController extends Controller
{
    use HandlesAdminContext;

    public function store(Request $request, string $vehicle, WebsiteMediaService $service): RedirectResponse
    {
        $data = $request->validate(['media' => ['required', 'file', 'max:6144'], 'kind' => ['required', 'in:photo,artwork'], 'alt_text' => ['sometimes', 'array']]);
        $file = $request->file('media');
        abort_unless($file instanceof UploadedFile, 422);
        $asset = $service->store($this->admin($request), Vehicle::query()->findOrFail($vehicle), $file, $data['kind'], $data['alt_text'] ?? []);
        Inertia::flash('uploaded_asset_id', (string) $asset->id);

        return to_route('admin.website.cards.edit', ['vehicle' => $vehicle], 303);
    }

    public function destroy(Request $request, string $asset, WebsiteMediaService $service): RedirectResponse
    {
        $data = $request->validate(['vehicle_id' => ['nullable', 'integer', 'min:1']]);
        $model = WebsiteMediaAsset::withTrashed()->findOrFail($asset);
        $vehicle = $model->vehicle_id ?? ($data['vehicle_id'] ?? null);
        $service->delete($this->admin($request), $model);

        return $vehicle === null ? to_route('admin.website.cards.index', [], 303) : to_route('admin.website.cards.edit', ['vehicle' => $vehicle], 303);
    }

    public function preview(Request $request, string $asset, string $variant, WebsiteMediaService $service): Response
    {
        app(WebsiteContentAuthorizer::class)->authorize($this->admin($request));
        $model = WebsiteMediaAsset::query()->findOrFail($asset);
        try {
            $bytes = $service->variantBytes($model, $variant);
        } catch (RuntimeException) {
            abort(404);
        }

        return $this->image($bytes);
    }

    public function published(string $asset, string $variant, string $sha256, PublishedVehiclePresentation $published, WebsiteMediaService $service): Response
    {
        $reference = $published->publishedAsset($asset);
        abort_if($reference === null || ($reference['manifest']['variants'][$variant]['sha256'] ?? null) !== $sha256, 404);
        try {
            $bytes = $service->variantBytes($reference['asset'], $variant);
        } catch (RuntimeException) {
            abort(404);
        }

        return $this->image($bytes, true);
    }

    private function image(string $bytes, bool $public = false): Response
    {
        $indexable = $public && WebsiteSettings::indexable() && ! config('website.preview.enabled');

        return response($bytes, 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => $indexable ? 'index, follow' : 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff']);
    }
}
