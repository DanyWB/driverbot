<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Domain\Website\Content\AdminWebsitePresenter;
use App\Domain\Website\Content\VehicleWebsiteProfileService;
use App\Domain\Website\Content\WebsiteContentAuthorizer;
use App\Domain\Website\PublicCatalog;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Website\SaveWebsiteDraftRequest;
use App\Http\Requests\Website\WebsiteRevisionRequest;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class WebsiteCardController extends Controller
{
    use HandlesAdminContext;

    public function index(Request $request): Response
    {
        $actor = $this->admin($request);
        app(WebsiteContentAuthorizer::class)->authorize($actor);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'in:car,scooter'], 'status' => ['nullable', 'in:draft,published,none']]);
        $query = Vehicle::query()->orderBy('sort_order')->orderBy('id');
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->whereLike('name', '%'.$filters['search'].'%')->orWhereLike('inventory_code', '%'.$filters['search'].'%'));
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (($filters['status'] ?? '') === 'none') {
            $query->whereNotIn('id', VehicleWebsiteProfile::query()->select('vehicle_id'));
        }
        if (in_array($filters['status'] ?? '', ['draft', 'published'], true)) {
            $subquery = VehicleWebsiteProfile::query()->select('vehicle_id');
            ($filters['status'] === 'draft' ? $subquery->whereNull('published_at') : $subquery->whereNotNull('published_at'));
            $query->whereIn('id', $subquery);
        }
        $vehicles = $query->paginate(24)->withQueryString();
        $ids = $vehicles->getCollection()->pluck('id')->all();
        $profiles = VehicleWebsiteProfile::query()->whereIn('vehicle_id', $ids)->get()->keyBy('vehicle_id');
        $eligible = app(PublicCatalog::class)->eligible(Vehicle::query())->whereKey($ids)->pluck('id')->all();
        $vehicles->through(function (Vehicle $vehicle) use ($profiles, $eligible): array {
            $profile = $profiles->get($vehicle->id);

            return ['id' => (int) $vehicle->id, ...$vehicle->only(['name', 'inventory_code', 'year']), 'type' => $vehicle->getRawOriginal('type'),
                'fleet_eligible' => in_array($vehicle->id, $eligible, true),
                'profile_status' => $profile === null ? 'none' : ($profile->published_at === null ? 'draft' : 'published'),
                'revision' => $profile === null ? 0 : $profile->revision, 'published_revision' => $profile?->published_revision,
                'cover_preview_url' => $profile?->draft_cover_asset_id === null ? null : route('admin.website.media.preview', ['asset' => $profile->draft_cover_asset_id, 'variant' => 'card']),
                'edit_url' => route('admin.website.cards.edit', ['vehicle' => $vehicle->id])];
        });

        return Inertia::render('website-admin/cards/Index', ['vehicles' => $vehicles, 'filters' => $filters, 'locales' => config('website.locales'), 'limits' => config('website.cms.media')]);
    }

    public function edit(Request $request, string $vehicle, AdminWebsitePresenter $presenter): Response
    {
        $actor = $this->admin($request);
        $model = Vehicle::query()->findOrFail($vehicle);
        $profile = VehicleWebsiteProfile::query()->where('vehicle_id', $model->id)->first();
        $media = [];
        foreach (WebsiteMediaAsset::query()->where('vehicle_id', $model->id)->latest()->limit(100)->get() as $asset) {
            try {
                $media[] = $presenter->asset($actor, $asset, $profile);
            } catch (RuntimeException) { /* invalid metadata cannot become a selectable image */
            }
        }
        $actions = [];
        foreach (['draft', 'publish', 'unpublish', 'upload'] as $action) {
            $actions[$action] = route($action === 'upload' ? 'admin.website.media.store' : 'admin.website.cards.'.$action, ['vehicle' => $model->id]);
        }
        $actions['preview'] = [];
        foreach (config('website.locales') as $locale) {
            $actions['preview'][$locale] = route('admin.website.cards.preview', ['vehicle' => $model->id, 'locale' => $locale]);
        }

        return Inertia::render('website-admin/cards/Edit', ['vehicle' => $presenter->vehicle($actor, $model),
            'profile' => $presenter->card($actor, $model, $profile), 'media' => $media, 'locales' => config('website.locales'),
            'limits' => [...config('website.cms.media'), 'content' => ['title' => 160, 'summary' => 4000, 'meta_title' => 160, 'meta_description' => 500, 'image_alt' => 255]],
            'capabilities' => ['edit' => true, 'publish' => true], 'actions' => $actions]);
    }

    public function draft(SaveWebsiteDraftRequest $request, string $vehicle, VehicleWebsiteProfileService $service): RedirectResponse
    {
        $service->saveDraft($this->admin($request), Vehicle::query()->findOrFail($vehicle), $request->validated('content'), $request->validated('cover_asset_id'), (int) $request->validated('expected_revision'));

        return $this->saved($vehicle);
    }

    public function publish(WebsiteRevisionRequest $request, string $vehicle, VehicleWebsiteProfileService $service): RedirectResponse
    {
        $service->publish($this->admin($request), Vehicle::query()->findOrFail($vehicle), (int) $request->validated('expected_revision'));

        return $this->saved($vehicle);
    }

    public function unpublish(WebsiteRevisionRequest $request, string $vehicle, VehicleWebsiteProfileService $service): RedirectResponse
    {
        $service->unpublish($this->admin($request), Vehicle::query()->findOrFail($vehicle), (int) $request->validated('expected_revision'));

        return $this->saved($vehicle);
    }

    private function saved(string $vehicle): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Website card saved.']);

        return to_route('admin.website.cards.edit', ['vehicle' => $vehicle], 303);
    }
}
