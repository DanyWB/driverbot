<?php

namespace App\Domain\Vehicles\Services;

use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class VehiclePhotoService
{
    public function store(Vehicle $vehicle, UploadedFile $file, ?string $altText = null): VehiclePhoto
    {
        $disk = 'public';
        $uuid = Str::uuid()->toString();
        $extension = strtolower($file->extension() ?: 'jpg');
        $directory = "vehicles/{$vehicle->id}";
        $path = "{$directory}/{$uuid}.{$extension}";
        $thumbnailPath = "{$directory}/thumbnails/{$uuid}.webp";

        $stored = Storage::disk($disk)->putFileAs($directory, $file, "{$uuid}.{$extension}");

        if ($stored === false) {
            throw new RuntimeException('Vehicle photo could not be stored.');
        }

        try {
            $thumbnail = $this->thumbnail($file);
            if (! Storage::disk($disk)->put($thumbnailPath, $thumbnail)) {
                throw new RuntimeException('Vehicle thumbnail could not be stored.');
            }

            return DB::transaction(function () use ($vehicle, $disk, $path, $altText): VehiclePhoto {
                $lockedVehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
                $hasPhotos = $lockedVehicle->photos()->exists();
                $sortOrder = (int) ($lockedVehicle->photos()->max('sort_order') ?? -1) + 1;

                return $lockedVehicle->photos()->create([
                    'disk' => $disk,
                    'file_path' => $path,
                    'alt_text' => $this->nullableString($altText),
                    'sort_order' => $sortOrder,
                    'is_primary' => ! $hasPhotos,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete([$path, $thumbnailPath]);

            throw $exception;
        }
    }

    /** @param list<int> $orderedIds */
    public function arrange(Vehicle $vehicle, array $orderedIds, int $primaryId): void
    {
        DB::transaction(function () use ($vehicle, $orderedIds, $primaryId): void {
            $lockedVehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $photos = $lockedVehicle->photos()->lockForUpdate()->get()->keyBy('id');
            $actualIds = $photos->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $submittedIds = collect($orderedIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();

            if ($actualIds !== $submittedIds || ! in_array($primaryId, $actualIds, true)) {
                throw new RuntimeException('Photo order is stale. Refresh and try again.');
            }

            foreach ($orderedIds as $index => $id) {
                $photos->get($id)?->update([
                    'sort_order' => $index,
                    'is_primary' => $id === $primaryId,
                ]);
            }
        });
    }

    public function delete(Vehicle $vehicle, VehiclePhoto $photo): void
    {
        if ((int) $photo->vehicle_id !== (int) $vehicle->id) {
            abort(404);
        }

        $disk = (string) $photo->disk;
        $path = (string) $photo->file_path;

        DB::transaction(function () use ($vehicle, $photo): void {
            $lockedVehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $wasPrimary = (bool) $photo->is_primary;
            $photo->delete();

            if ($wasPrimary) {
                $lockedVehicle->photos()->orderBy('sort_order')->orderBy('id')->first()?->update(['is_primary' => true]);
            }
        });

        $thumbnailPath = $this->thumbnailPath($path);
        DB::afterCommit(fn () => Storage::disk($disk)->delete([$path, $thumbnailPath]));
    }

    public function url(VehiclePhoto $photo): string
    {
        return Storage::disk((string) $photo->disk)->url((string) $photo->file_path);
    }

    public function thumbnailUrl(VehiclePhoto $photo): string
    {
        return Storage::disk((string) $photo->disk)->url($this->thumbnailPath((string) $photo->file_path));
    }

    public function thumbnailPath(string $originalPath): string
    {
        $directory = trim((string) pathinfo($originalPath, PATHINFO_DIRNAME), '.');
        $filename = (string) pathinfo($originalPath, PATHINFO_FILENAME);

        return "{$directory}/thumbnails/{$filename}.webp";
    }

    public function discardFiles(VehiclePhoto $photo): void
    {
        $path = (string) $photo->file_path;
        Storage::disk((string) $photo->disk)->delete([$path, $this->thumbnailPath($path)]);
    }

    private function thumbnail(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        $source = is_string($contents) ? imagecreatefromstring($contents) : false;

        if ($source === false) {
            throw new RuntimeException('Vehicle photo could not be decoded.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = 480;
        $targetHeight = 320;
        $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $cropWidth = (int) round($targetWidth / $scale);
        $cropHeight = (int) round($targetHeight / $scale);
        $sourceX = max(0, (int) floor(($sourceWidth - $cropWidth) / 2));
        $sourceY = max(0, (int) floor(($sourceHeight - $cropHeight) / 2));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight,
        );

        ob_start();
        $written = imagewebp($target, null, 82);
        $thumbnail = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        if (! $written) {
            throw new RuntimeException('Vehicle thumbnail could not be generated.');
        }

        return $thumbnail;
    }

    private function nullableString(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
