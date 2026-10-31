<?php

namespace App\Domain\Website\Content;

use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\PublicCatalog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteDocument;
use App\Models\WebsiteMediaAsset;

class AdminWebsitePresenter
{
    public function __construct(private readonly WebsiteContentAuthorizer $authorization, private readonly WebsiteContentSchema $cards,
        private readonly WebsitePageSchema $pages, private readonly WebsiteSettingsSchema $settings, private readonly WebsiteMediaService $media) {}

    /** @return array<string,mixed> */
    public function card(User $actor, Vehicle $vehicle, ?VehicleWebsiteProfile $profile): array
    {
        $this->authorization->authorize($actor);
        $draft = [...$this->cards->normalize($profile === null ? [] : $profile->draft_content), 'cover_asset_id' => $profile?->draft_cover_asset_id];
        $published = $profile?->published_content;
        if ($published !== null) {
            $published = [...$this->cards->normalize($published), 'cover_asset_id' => $profile->published_cover_asset_id];
        }

        return ['revision' => $profile === null ? 0 : $profile->revision, 'published_revision' => $profile?->published_revision,
            'published_at' => $profile?->published_at?->format(DATE_ATOM), 'has_unpublished_changes' => $draft !== $published,
            'draft' => $draft, 'published' => $published];
    }

    /** @return array<string,mixed> */
    public function vehicle(User $actor, Vehicle $vehicle): array
    {
        $this->authorization->authorize($actor);

        return ['id' => (int) $vehicle->id, ...$vehicle->only(['name', 'inventory_code', 'year']),
            'type' => $vehicle->getRawOriginal('type'), 'fleet_eligible' => app(PublicCatalog::class)->eligible(Vehicle::query())->whereKey($vehicle->id)->exists(),
            'fleet_url' => route('vehicles.edit', ['vehicle' => $vehicle->id])];
    }

    /** @return array<string,mixed> */
    public function asset(User $actor, WebsiteMediaAsset $asset, ?VehicleWebsiteProfile $profile): array
    {
        $this->authorization->authorize($actor);
        $manifest = $this->media->publicationManifest($asset);
        $variants = [];
        foreach ($manifest['variants'] as $key => $variant) {
            $variants[$key] = ['url' => route('admin.website.media.preview', ['asset' => $asset->id, 'variant' => $key]),
                'width' => $variant['width'], 'height' => $variant['height']];
        }

        return ['id' => (string) $asset->id, 'kind' => $asset->kind, 'width' => $asset->width, 'height' => $asset->height,
            'alt_text' => $asset->alt_text, 'referenced_by_draft' => $profile?->draft_cover_asset_id === $asset->id,
            'referenced_by_published' => $profile?->published_cover_asset_id === $asset->id, 'variants' => $variants];
    }

    /** @return array<string,mixed> */
    public function document(User $actor, ?WebsiteDocument $document, bool $settings = false): array
    {
        $this->authorization->authorize($actor);
        $content = $document === null ? [] : $document->draft_content;
        $draft = $settings ? $this->settings->normalize($content) : $this->pages->normalize($content);

        return ['revision' => $document === null ? 0 : $document->revision, 'published_revision' => $document?->published_revision,
            'published_at' => $document?->published_at?->format(DATE_ATOM), 'has_unpublished_changes' => $draft !== $document?->published_content,
            'draft' => $draft, 'published' => $document?->published_content];
    }
}
