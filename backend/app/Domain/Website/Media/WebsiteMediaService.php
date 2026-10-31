<?php

namespace App\Domain\Website\Media;

use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Website\Content\WebsiteContentAuthorizer;
use App\Domain\Website\Content\WebsiteContentSchema;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleWebsiteProfile;
use App\Models\WebsiteMediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WebsiteMediaService
{
    public function __construct(private readonly WebsiteRasterVariants $raster, private readonly WebsiteMediaStorage $storage,
        private readonly WebsiteContentAuthorizer $authorization, private readonly WebsiteContentSchema $schema,
        private readonly AdminAuditService $audit) {}

    /** @param array<string,mixed> $altText */
    public function store(User $actor, Vehicle $vehicle, UploadedFile $file, string $kind, array $altText = []): WebsiteMediaAsset
    {
        $this->authorization->authorize($actor);
        if (! in_array($kind, ['photo', 'artwork'], true)) {
            throw ValidationException::withMessages(['kind' => 'Unsupported website image kind.']);
        }
        $alt = $this->schema->localized($altText, 'alt_text');
        $image = $this->raster->build($file);
        $id = strtolower((string) Str::uuid());
        $original = 'website-media/'.$id.'/original.'.$image['extension'];
        $paths = [$original, ...array_map(fn (string $key): string => 'website-media/'.$id.'/'.$key.'.webp', array_keys(WebsiteRasterVariants::WIDTHS))];

        return DB::transaction(function () use ($actor, $vehicle, $kind, $alt, $image, $id, $original, $paths): WebsiteMediaAsset {
            $admin = $this->authorization->authorize($actor, true);
            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            if (WebsiteMediaAsset::withTrashed()->whereKey($id)->exists()) {
                throw new RuntimeException('Generated website media ID already exists.');
            }
            $this->storage->claim($id);
            DB::connection()->afterRollBack(fn () => $this->storage->delete($id, $paths));
            $this->storage->write($id, $original, $image['original']);
            $variants = [];
            foreach ($image['variants'] as $key => $variant) {
                $path = 'website-media/'.$id.'/'.$key.'.webp';
                $this->storage->write($id, $path, $variant['contents']);
                $variants[$key] = ['path' => $path, 'width' => $variant['width'], 'height' => $variant['height'],
                    'mime' => 'image/webp', 'bytes' => strlen($variant['contents']), 'sha256' => hash('sha256', $variant['contents'])];
            }
            $asset = new WebsiteMediaAsset;
            $asset->forceFill(['id' => $id, 'vehicle_id' => $locked->id, 'kind' => $kind, 'disk' => WebsiteMediaStorage::DISK,
                'original_path' => $original, 'mime' => $image['mime'], 'width' => $image['width'], 'height' => $image['height'],
                'original_bytes' => strlen($image['original']), 'original_sha256' => hash('sha256', $image['original']),
                'variants' => $variants, 'alt_text' => $alt, 'created_by_admin_id' => $admin->id])->save();
            $this->audit->record($admin, $asset, 'website.media_uploaded', null,
                ['asset_id' => $id, 'vehicle_id' => $locked->id, 'kind' => $kind, 'source_sha256' => $asset->original_sha256]);

            return $asset;
        });
    }

    public function delete(User $actor, WebsiteMediaAsset $asset): void
    {
        DB::transaction(function () use ($actor, $asset): void {
            $admin = $this->authorization->authorize($actor, true);
            $identity = WebsiteMediaAsset::withTrashed()->whereKey($asset->id)->firstOrFail();
            if ($identity->vehicle_id !== null) {
                Vehicle::query()->whereKey($identity->vehicle_id)->lockForUpdate()->firstOrFail();
                VehicleWebsiteProfile::query()->where('vehicle_id', $identity->vehicle_id)->lockForUpdate()->get();
            }
            $locked = WebsiteMediaAsset::withTrashed()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if (VehicleWebsiteProfile::query()->where(fn ($q) => $q->where('draft_cover_asset_id', $locked->id)->orWhere('published_cover_asset_id', $locked->id))->exists()) {
                throw ValidationException::withMessages(['media' => 'Referenced website media must be unlinked before deletion.']);
            }
            $paths = $this->paths($locked);
            $this->storage->assertPaths((string) $locked->id, $paths);
            if (! $locked->trashed()) {
                $locked->delete();
                $this->audit->record($admin, $locked, 'website.media_deleted', ['asset_id' => $locked->id], null);
            }
            DB::connection()->afterCommit(fn () => $this->storage->delete((string) $locked->id, $paths));
        });
    }

    /** @return array<string,array<string,int|string>> */
    public function assertUsable(WebsiteMediaAsset $asset, int $vehicleId): array
    {
        if ($asset->trashed() || (int) $asset->vehicle_id !== $vehicleId || ! in_array($asset->kind, ['photo', 'artwork'], true)) {
            throw ValidationException::withMessages(['cover_asset_id' => 'Website cover must belong to this physical vehicle and be ready.']);
        }
        $this->paths($asset);
        $original = $this->storage->read((string) $asset->id, (string) $asset->original_path, 8 * 1024 * 1024);
        if (strlen($original) !== (int) $asset->original_bytes || ! hash_equals((string) $asset->original_sha256, hash('sha256', $original))) {
            throw new RuntimeException('Private website media original no longer matches its manifest.');
        }
        $variants = $this->manifest($asset);
        foreach ($variants as $variant) {
            $bytes = $this->storage->read((string) $asset->id, $variant['path'], 2 * 1024 * 1024);
            $size = @getimagesizefromstring($bytes);
            if (strlen($bytes) !== $variant['bytes'] || ! hash_equals($variant['sha256'], hash('sha256', $bytes))
                || $size === false || $size['mime'] !== 'image/webp' || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/webp'
                || $size[0] !== $variant['width'] || $size[1] !== $variant['height']) {
                throw new RuntimeException('Private website media variant failed manifest or final MIME validation.');
            }
        }

        return $variants;
    }

    /**
     * Pure metadata validation; never reads a file.
     *
     * @return array<string,mixed>
     */
    public function publicationManifest(WebsiteMediaAsset $asset): array
    {
        if ($asset->trashed() || ! in_array($asset->kind, ['photo', 'artwork'], true)) {
            throw new RuntimeException('Website asset is not publishable.');
        }
        $this->paths($asset);
        $variants = [];
        foreach ($this->manifest($asset) as $key => $variant) {
            $variants[$key] = array_intersect_key($variant, array_flip(['width', 'height', 'mime', 'bytes', 'sha256']));
        }

        return ['asset_id' => (string) $asset->id, 'kind' => $asset->kind,
            'width' => (int) $asset->width, 'height' => (int) $asset->height, 'variants' => $variants];
    }

    public function variantBytes(WebsiteMediaAsset $asset, string $key): string
    {
        $this->paths($asset);
        $variants = $this->manifest($asset);
        if (! isset($variants[$key])) {
            throw new RuntimeException('Unsupported website derivative.');
        }
        $variant = $variants[$key];
        $bytes = $this->storage->read((string) $asset->id, $variant['path'], 2 * 1024 * 1024);
        $size = @getimagesizefromstring($bytes);
        if (strlen($bytes) !== $variant['bytes'] || ! hash_equals($variant['sha256'], hash('sha256', $bytes))
            || $size === false || $size['mime'] !== 'image/webp' || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/webp'
            || $size[0] !== $variant['width'] || $size[1] !== $variant['height']) {
            throw new RuntimeException('Website derivative failed integrity validation.');
        }

        return $bytes;
    }

    /** @return list<string> */
    private function paths(WebsiteMediaAsset $asset): array
    {
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $id = (string) $asset->id;
        if ($asset->disk !== WebsiteMediaStorage::DISK || ! isset($extensions[$asset->mime])
            || $asset->original_path !== 'website-media/'.$id.'/original.'.$extensions[$asset->mime]
            || ! preg_match('/^[a-f0-9]{64}$/D', $asset->original_sha256)) {
            throw new RuntimeException('Website media paths or manifest are invalid.');
        }
        $paths = [$asset->original_path];
        foreach ($this->manifest($asset) as $variant) {
            $paths[] = $variant['path'];
        }

        return $paths;
    }

    /** @return array<string,array{path:string,width:int,height:int,mime:string,bytes:int,sha256:string}> */
    private function manifest(WebsiteMediaAsset $asset): array
    {
        $raw = $asset->getRawOriginal('variants');
        if (! is_string($raw)) {
            throw new RuntimeException('Website media variant JSON is missing.');
        }
        try {
            $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Website media variant JSON is invalid.', previous: $exception);
        }
        if (! is_array($manifest)) {
            throw new RuntimeException('Website media manifest must be an object.');
        }
        $keys = array_keys($manifest);
        $expectedKeys = array_keys(WebsiteRasterVariants::WIDTHS);
        sort($keys);
        sort($expectedKeys);
        if ($keys !== $expectedKeys) {
            throw new RuntimeException('Website media variant keys differ.');
        }
        $validated = [];
        $id = (string) $asset->id;
        foreach ($manifest as $key => $variant) {
            if (! is_array($variant) || ($variant['path'] ?? null) !== 'website-media/'.$id.'/'.$key.'.webp'
                || ($variant['mime'] ?? null) !== 'image/webp' || ! is_int($variant['width'] ?? null) || $variant['width'] < 1 || $variant['width'] > min(WebsiteRasterVariants::WIDTHS[$key], (int) $asset->width)
                || ! is_int($variant['height'] ?? null) || $variant['height'] < 1 || $variant['height'] > (int) $asset->height
                || ! is_int($variant['bytes'] ?? null) || $variant['bytes'] < 1 || $variant['bytes'] > 2 * 1024 * 1024
                || ! is_string($variant['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $variant['sha256'])
                || count($variant) !== 6 || array_diff(array_keys($variant), ['path', 'width', 'height', 'mime', 'bytes', 'sha256']) !== []) {
                throw new RuntimeException('Website media variant path or bounds are invalid.');
            }
            $validated[$key] = ['path' => $variant['path'], 'width' => $variant['width'], 'height' => $variant['height'],
                'mime' => 'image/webp', 'bytes' => $variant['bytes'], 'sha256' => $variant['sha256']];
        }

        return $validated;
    }
}
