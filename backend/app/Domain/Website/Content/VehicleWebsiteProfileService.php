<?php

namespace App\Domain\Website\Content;

use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Website\Media\WebsiteMediaService;
use App\Domain\Website\PublicCatalog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleWebsiteProfileService
{
    public function __construct(private readonly WebsiteContentAuthorizer $authorization, private readonly WebsiteContentSchema $schema,
        private readonly WebsiteMediaService $media, private readonly AdminAuditService $audit) {}

    /** @param array<string,mixed> $content */
    public function saveDraft(User $actor, Vehicle $vehicle, array $content, ?string $coverAssetId, int $expectedRevision): VehicleWebsiteProfile
    {
        $this->authorization->authorize($actor);
        $normalized = $this->schema->normalize($content);

        return DB::transaction(function () use ($actor, $vehicle, $normalized, $coverAssetId, $expectedRevision): VehicleWebsiteProfile {
            $admin = $this->authorization->authorize($actor, true);
            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $profile = VehicleWebsiteProfile::query()->where('vehicle_id', $locked->id)->lockForUpdate()->first();
            $this->expected($profile, $expectedRevision);
            $this->cover($coverAssetId, (int) $locked->id);
            $old = $profile?->only(['revision', 'draft_content', 'draft_cover_asset_id']);
            $profile ??= new VehicleWebsiteProfile;
            $profile->forceFill(['vehicle_id' => $locked->id, 'draft_content' => $normalized, 'draft_cover_asset_id' => $coverAssetId,
                'revision' => $expectedRevision + 1, 'updated_by_admin_id' => $admin->id])->save();
            $this->audit->record($admin, $profile, 'website.vehicle_draft_saved', $old,
                $profile->only(['revision', 'draft_content', 'draft_cover_asset_id']));

            return $profile;
        });
    }

    public function publish(User $actor, Vehicle $vehicle, int $expectedRevision): VehicleWebsiteProfile
    {
        return DB::transaction(function () use ($actor, $vehicle, $expectedRevision): VehicleWebsiteProfile {
            $admin = $this->authorization->authorize($actor, true);
            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            if (! app(PublicCatalog::class)->query()->whereKey($locked->id)->exists()) {
                throw ValidationException::withMessages(['vehicle' => 'Only an active published physical vehicle can publish website content.']);
            }
            $profile = VehicleWebsiteProfile::query()->where('vehicle_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->expected($profile, $expectedRevision);
            $content = $this->schema->normalize($profile->draft_content);
            if ($content['title'] === []) {
                throw ValidationException::withMessages(['title' => 'At least one locale needs a title before publication.']);
            }
            $cover = $this->cover($profile->draft_cover_asset_id, (int) $locked->id);
            if ($cover !== null) {
                foreach (array_keys($content['title']) as $locale) {
                    $content['image_alt'][$locale] ??= $cover->alt_text[$locale] ?? '';
                    if ($content['image_alt'][$locale] === '') {
                        throw ValidationException::withMessages(['image_alt' => 'Each published cover locale needs plain-text alt text.']);
                    }
                }
                $content = $this->schema->normalize($content);
            }
            $old = $profile->only(['published_revision', 'published_content', 'published_cover_asset_id', 'published_at']);
            $profile->forceFill(['draft_content' => $content, 'published_content' => $content, 'published_cover_asset_id' => $cover?->id,
                'published_cover_manifest' => $cover === null ? null : $this->media->publicationManifest($cover),
                'revision' => $expectedRevision + 1, 'published_revision' => $expectedRevision + 1,
                'published_at' => now(), 'published_by_admin_id' => $admin->id, 'updated_by_admin_id' => $admin->id])->save();
            $this->audit->record($admin, $profile, 'website.vehicle_published', $old,
                $profile->only(['published_revision', 'published_content', 'published_cover_asset_id', 'published_at']));

            return $profile;
        });
    }

    public function unpublish(User $actor, Vehicle $vehicle, int $expectedRevision): VehicleWebsiteProfile
    {
        return DB::transaction(function () use ($actor, $vehicle, $expectedRevision): VehicleWebsiteProfile {
            $admin = $this->authorization->authorize($actor, true);
            Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $profile = VehicleWebsiteProfile::query()->where('vehicle_id', $vehicle->id)->lockForUpdate()->firstOrFail();
            $this->expected($profile, $expectedRevision);
            $old = $profile->only(['published_revision', 'published_cover_asset_id', 'published_at']);
            $profile->forceFill(['published_content' => null, 'published_cover_asset_id' => null, 'published_cover_manifest' => null, 'published_revision' => null,
                'published_at' => null, 'published_by_admin_id' => null, 'revision' => $expectedRevision + 1, 'updated_by_admin_id' => $admin->id])->save();
            $this->audit->record($admin, $profile, 'website.vehicle_unpublished', $old, ['revision' => $profile->revision]);

            return $profile;
        });
    }

    private function expected(?VehicleWebsiteProfile $profile, int $expected): void
    {
        $actual = $profile === null ? 0 : $profile->revision;
        if ($expected < 0 || $actual !== $expected) {
            throw ValidationException::withMessages(['revision' => 'Website content changed. Refresh before saving or publishing.']);
        }
    }

    private function cover(?string $id, int $vehicleId): ?WebsiteMediaAsset
    {
        if ($id === null) {
            return null;
        }
        $asset = WebsiteMediaAsset::query()->whereKey($id)->where('vehicle_id', $vehicleId)->lockForUpdate()->first();
        if ($asset === null) {
            throw ValidationException::withMessages(['cover_asset_id' => 'Website cover must belong to this physical vehicle.']);
        }
        $this->media->assertUsable($asset, $vehicleId);

        return $asset;
    }
}
