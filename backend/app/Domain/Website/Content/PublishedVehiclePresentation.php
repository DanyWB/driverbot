<?php

namespace App\Domain\Website\Content;

use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\PublicCatalog;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/** Batch metadata; original/variant bytes are checked only on publish/delivery. */
class PublishedVehiclePresentation
{
    private const ASSET_FIELDS = ['id', 'vehicle_id', 'kind', 'disk', 'original_path', 'mime', 'width', 'height', 'original_bytes', 'original_sha256', 'variants', 'alt_text', 'deleted_at'];

    public function __construct(private readonly WebsiteContentSchema $schema, private readonly WebsiteMediaService $media) {}

    /** @return array<string,mixed>|null */
    public function forVehicle(Vehicle $vehicle, string $locale): ?array
    {
        return $this->forVehicles([(int) $vehicle->id], $locale)[(int) $vehicle->id] ?? null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int,array<string,mixed>>
     */
    public function forVehicles(array $ids, string $locale): array
    {
        if (config('website.cms.enabled') !== true || ! in_array($locale, ['en', 'ru', 'uk'], true) || $ids === []) {
            return [];
        }
        if (count($ids) > 100 || array_filter($ids, fn (int $id): bool => $id < 1) !== []) {
            throw new RuntimeException('Website presentation batch exceeds its bound.');
        }
        $result = [];
        foreach ($this->rows($ids) as $profile) {
            try {
                $payload = $this->present($profile, $locale);
                if ($payload !== null) {
                    $result[(int) $profile->vehicle_id] = $payload;
                }
            } catch (ValidationException|RuntimeException) { /* deny corrupt metadata */
            }
        }

        return $result;
    }

    /** @return array{asset:WebsiteMediaAsset,manifest:array<string,mixed>}|null */
    public function publishedAsset(string $id): ?array
    {
        if (config('website.cms.enabled') !== true) {
            return null;
        }
        foreach ($this->rows(null, $id) as $profile) {
            try {
                $rawContent = $this->content($profile);
                if ($rawContent === null) {
                    continue;
                }
                $content = $this->schema->normalize($rawContent);
                if ($content['title'] === []) {
                    continue;
                }
                $asset = $this->asset($profile);
                if ($asset !== null && $this->media->publicationManifest($asset) === $profile->published_cover_manifest) {
                    return ['asset' => $asset, 'manifest' => $profile->published_cover_manifest];
                }
            } catch (ValidationException|RuntimeException) { /* deny corrupt metadata */
            }
        }

        return null;
    }

    /**
     * @param  list<int>|null  $ids
     * @return Collection<int,VehicleWebsiteProfile>
     */
    private function rows(?array $ids, ?string $assetId = null): Collection
    {
        $query = VehicleWebsiteProfile::query()->select('vehicle_website_profiles.*')
            ->leftJoin('website_media_assets as cover', function ($join): void {
                $join->on('cover.id', '=', 'vehicle_website_profiles.published_cover_asset_id')
                    ->on('cover.vehicle_id', '=', 'vehicle_website_profiles.vehicle_id')->whereNull('cover.deleted_at');
            })->whereNotNull('vehicle_website_profiles.published_at')
            ->whereIn('vehicle_website_profiles.vehicle_id', app(PublicCatalog::class)->eligible(Vehicle::query())->select('id'));
        foreach (self::ASSET_FIELDS as $field) {
            $query->addSelect('cover.'.$field.' as media_'.$field);
        }
        if ($ids !== null) {
            $query->whereIn('vehicle_website_profiles.vehicle_id', $ids);
        }
        if ($assetId !== null) {
            $query->where('vehicle_website_profiles.published_cover_asset_id', $assetId)->limit(1);
        }

        return $query->get();
    }

    private function asset(VehicleWebsiteProfile $profile): ?WebsiteMediaAsset
    {
        if ($profile->getAttribute('media_id') === null) {
            return null;
        }
        $attributes = [];
        foreach (self::ASSET_FIELDS as $field) {
            $attributes[$field] = $profile->getAttribute('media_'.$field);
        }

        return (new WebsiteMediaAsset)->newFromBuilder($attributes);
    }

    /** @return array<string,mixed>|null */
    private function present(VehicleWebsiteProfile $profile, string $locale): ?array
    {
        $rawContent = $this->content($profile);
        if ($rawContent === null) {
            return null;
        }
        $content = $this->schema->normalize($rawContent);
        $title = $content['title'][$locale] ?? null;
        if (! is_string($title) || $title === '') {
            return null;
        }
        $cover = null;
        if ($profile->published_cover_asset_id !== null) {
            $asset = $this->asset($profile);
            if ($asset === null || empty($content['image_alt'][$locale])) {
                return null;
            }
            $manifest = $this->media->publicationManifest($asset);
            if ($manifest !== $profile->published_cover_manifest) {
                return null;
            }
            $cover = [...$manifest, 'alt' => $content['image_alt'][$locale]];
            foreach ($cover['variants'] as $key => &$variant) {
                $variant['url'] = route('website.media', ['asset' => $asset->id, 'variant' => $key, 'sha256' => $variant['sha256']]);
            }
            unset($variant);
        }

        return ['vehicle_id' => (int) $profile->vehicle_id, 'publication_revision' => $profile->published_revision,
            'locale' => $locale, 'title' => $title, 'summary' => $content['summary'][$locale] ?? '',
            'meta_title' => $content['meta_title'][$locale] ?? $title, 'meta_description' => $content['meta_description'][$locale] ?? '', 'cover' => $cover];
    }

    /** @return array<string,mixed>|null */
    private function content(VehicleWebsiteProfile $profile): ?array
    {
        // Eloquent's array cast may decode valid JSON scalars as scalar values.
        // Validate raw storage before passing it to the typed content schema.
        $raw = $profile->getRawOriginal('published_content');
        if (! is_string($raw) || strlen($raw) > 262144) {
            return null;
        }
        try {
            $content = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($content) ? $content : null;
    }
}
