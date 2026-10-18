<?php

namespace Tests\Feature;

use App\Domain\Integrations\Bot\Presenters\BotApiPresenter;
use App\Domain\Vehicles\Services\TelegramPhotoOptimizer;
use App\Domain\Vehicles\Services\VehiclePhotoService;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TelegramPhotoOptimizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_preserves_original_and_full_dimensions_and_exposes_optimized_copy(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('portrait.png', 3024, 4032);
        $hash = hash_file('sha256', $file->getRealPath());
        $vehicle = Vehicle::factory()->create();
        $photo = app(VehiclePhotoService::class)->store($vehicle, $file);
        $disk = Storage::disk('public');
        $target = app(TelegramPhotoOptimizer::class)->path($photo->file_path);
        $optimized = $disk->get($target);
        $this->assertSame($hash, hash('sha256', $disk->get($photo->file_path)));
        $this->assertSame([3024, 4032], array_slice(getimagesizefromstring($optimized), 0, 2));
        $this->assertLessThanOrEqual(TelegramPhotoOptimizer::MAX_BYTES, strlen($optimized));
        $this->assertSame('image/jpeg', getimagesizefromstring($optimized)['mime']);
        $presented = app(BotApiPresenter::class)->vehicle($vehicle->fresh());
        $this->assertSame($disk->url($target), $presented['primary_photo']['url']);
        $this->assertSame($disk->url($photo->file_path), $presented['primary_photo']['original_url']);
        app(VehiclePhotoService::class)->discardFiles($photo);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_existing_large_original_is_optimized_idempotently_without_overwriting_it(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('large.jpg', 600, 800);
        // Oversized metadata/trailing bytes must not be carried into Telegram copies.
        $original = file_get_contents($file->getRealPath()).str_repeat('x', 6_000_000);
        $disk = Storage::disk('public');
        $disk->put('vehicles/1/large.jpg', $original);
        $optimizer = app(TelegramPhotoOptimizer::class);
        $target = $optimizer->prepare('public', 'vehicles/1/large.jpg');
        $this->assertLessThanOrEqual(4_000_000, $disk->size($target));
        $this->assertSame([600, 800], array_slice(getimagesizefromstring($disk->get($target)), 0, 2));
        $hash = hash('sha256', $disk->get($target));
        $this->assertSame($target, $optimizer->prepare('public', 'vehicles/1/large.jpg'));
        $this->assertSame($hash, hash('sha256', $disk->get($target)));
        $this->assertSame($original, $disk->get('vehicles/1/large.jpg'));
    }

    public function test_unsupported_dimensions_are_rejected_without_resizing(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('panorama.png', 1000, 20);
        $disk = Storage::disk('public');
        $disk->put('vehicles/1/panorama.png', file_get_contents($file->getRealPath()));
        try {
            app(TelegramPhotoOptimizer::class)->prepare('public', 'vehicles/1/panorama.png');
            $this->fail('Unsupported aspect ratio was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('photo', $error->errors());
        }
        $this->assertSame(['vehicles/1/panorama.png'], $disk->allFiles());
    }

    public function test_jpeg_orientation_is_preserved_when_metadata_is_removed(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('EXIF extension is required for orientation normalization.');
        }
        Storage::fake('public');
        $file = UploadedFile::fake()->image('rotated.jpg', 80, 40);
        $jpeg = file_get_contents($file->getRealPath());
        $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', 6)."\0\0".pack('V', 0);
        $exif = "Exif\0\0".$tiff;
        $original = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $disk = Storage::disk('public');
        $disk->put('vehicles/1/rotated.jpg', $original);
        $target = app(TelegramPhotoOptimizer::class)->prepare('public', 'vehicles/1/rotated.jpg');
        $this->assertSame([40, 80], array_slice(getimagesizefromstring($disk->get($target)), 0, 2));
        $this->assertSame($original, $disk->get('vehicles/1/rotated.jpg'));
    }
}
