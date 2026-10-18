<?php

namespace App\Domain\Vehicles\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelegramPhotoOptimizer
{
    public const MAX_BYTES = 4_000_000;

    public function path(string $original): string
    {
        return dirname($original).'/telegram-v1/'.pathinfo($original, PATHINFO_FILENAME).'.jpg';
    }

    public function prepare(string $disk, string $original): string
    {
        $locale = request()->cookie('admin_locale') === 'ru' ? 'ru' : app()->getLocale();
        $storage = Storage::disk($disk);
        $target = $this->path($original);
        if ($storage->exists($target)) {
            return $target;
        }

        $contents = $storage->get($original);
        $size = is_string($contents) ? @getimagesizefromstring($contents) : false;
        // Do not silently resize or crop images to satisfy Telegram limits.
        if ($size === false || $size[0] + $size[1] > 10000 || max($size[0], $size[1]) / max(1, min($size[0], $size[1])) > 20) {
            throw ValidationException::withMessages(['photo' => __('photos.telegram_dimensions', [], $locale)]);
        }
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw ValidationException::withMessages(['photo' => __('photos.telegram_decode', [], $locale)]);
        }

        try {
            // Bake JPEG orientation into the pixels before removing EXIF metadata.
            $orientation = 1;
            if ($size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($storage->path($original));
                $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            }
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180,
                5, 8 => 90,
                6, 7 => -90,
                default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                if ($rotated === false) {
                    throw new RuntimeException('Photo orientation could not be normalized.');
                }
                imagedestroy($source);
                $source = $rotated;
            }
            // Flatten transparency to white, preserving every pixel and the full frame.
            $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
            if ($canvas === false) {
                throw new RuntimeException('Photo canvas could not be allocated.');
            }
            try {
                imagefill($canvas, 0, 0, 0xFFFFFF);
                imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
                imageinterlace($canvas, true);
                foreach ([88, 80, 72, 64, 56, 48, 40] as $quality) {
                    ob_start();
                    try {
                        $encoded = imagejpeg($canvas, null, $quality);
                        $jpeg = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    if ($encoded && strlen($jpeg) <= self::MAX_BYTES) {
                        if (! $storage->put($target, $jpeg)) {
                            throw new RuntimeException('Optimized vehicle photo could not be stored.');
                        }

                        return $target;
                    }
                }
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }

        throw ValidationException::withMessages(['photo' => __('photos.telegram_size', [], $locale)]);
    }
}
