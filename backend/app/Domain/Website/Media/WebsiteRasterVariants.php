<?php

namespace App\Domain\Website\Media;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class WebsiteRasterVariants
{
    public const WIDTHS = ['card' => 480, 'card_2x' => 960, 'detail' => 1600];

    /** @return array{original:string,mime:string,extension:string,width:int,height:int,variants:array<string,array{contents:string,width:int,height:int}>} */
    public function build(UploadedFile $file): array
    {
        $maxBytes = min(8 * 1024 * 1024, max(0, (int) config('website.cms.media.max_bytes')));
        if (! $file->isValid() || $file->getSize() > $maxBytes) {
            $this->invalid('Image upload exceeds the website media byte limit.');
        }
        $contents = file_get_contents($file->getRealPath(), false, null, 0, $maxBytes + 1);
        if (! is_string($contents) || $contents === '' || strlen($contents) > $maxBytes) {
            $this->invalid('Image contents exceed the website media byte limit.');
        }
        $size = @getimagesizefromstring($contents);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ($size === false || ! is_string($mime) || ! isset($extensions[$mime]) || $size['mime'] !== $mime) {
            $this->invalid('Only decoded JPEG, PNG and still WebP website images are allowed.');
        }
        $width = $size[0];
        $height = $size[1];
        $maxDimension = min(4096, max(1, (int) config('website.cms.media.max_dimension')));
        $maxPixels = min(8_000_000, max(1, (int) config('website.cms.media.max_pixels')));
        if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || $width * $height > $maxPixels) {
            $this->invalid('Image dimensions exceed the website media decode limit.');
        }
        $this->assertStill($contents, $mime);
        $budget = min(128 * 1024 * 1024, max(0, (int) config('website.cms.media.memory_budget_bytes')), $this->phpMemoryLimit());
        // Conservative bound includes decoded/orientation/resampling buffers and encoded copies.
        $estimate = memory_get_usage(true) + $width * $height * 20 + strlen($contents) * 2 + 16 * 1024 * 1024;
        if ($estimate > $budget) {
            $this->invalid('Image processing would exceed the configured memory budget.');
        }
        $source = @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            $this->invalid('Website image could not be decoded.');
        }
        try {
            if (imagesx($source) !== $width || imagesy($source) !== $height) {
                $this->invalid('Decoded image dimensions differ from its header.');
            }
            if (! imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }
            imagealphablending($source, false);
            imagesavealpha($source, true);
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file->getRealPath());
                $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($source, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3, 4 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
                };
                if ($angle !== 0) {
                    $rotated = imagerotate($source, $angle, 0);
                    if (! $rotated instanceof GdImage) {
                        $this->invalid('Website image orientation could not be normalized.');
                    }
                    imagedestroy($source);
                    $source = $rotated;
                }
            }
            $width = imagesx($source);
            $height = imagesy($source);
            $variants = [];
            foreach (self::WIDTHS as $key => $limit) {
                $targetWidth = min($limit, $width);
                $targetHeight = max(1, (int) round($height * $targetWidth / $width));
                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                if (! $canvas instanceof GdImage) {
                    $this->invalid('Website image variant could not be allocated.');
                }
                try {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    if ($transparent === false) {
                        $this->invalid('Website image transparency could not be allocated.');
                    }
                    imagefill($canvas, 0, 0, $transparent);
                    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                    ob_start();
                    try {
                        $encoded = imagewebp($canvas, null, 82);
                        $bytes = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    if (! $encoded) {
                        $this->invalid('Website image variant encoding failed.');
                    }
                    $finalSize = @getimagesizefromstring($bytes);
                    if ($bytes === '' || strlen($bytes) > min(2 * 1024 * 1024, (int) config('website.cms.media.max_variant_bytes'))
                        || $finalSize === false || $finalSize['mime'] !== 'image/webp'
                        || $finalSize[0] !== $targetWidth || $finalSize[1] !== $targetHeight
                        || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/webp') {
                        $this->invalid('Website image variant failed final MIME, size or dimension validation.');
                    }
                    $variants[$key] = ['contents' => $bytes, 'width' => $targetWidth, 'height' => $targetHeight];
                } finally {
                    imagedestroy($canvas);
                }
            }

            return ['original' => $contents, 'mime' => $mime, 'extension' => $extensions[$mime],
                'width' => $width, 'height' => $height, 'variants' => $variants];
        } finally {
            imagedestroy($source);
        }
    }

    private function phpMemoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '-1') {
            return PHP_INT_MAX;
        }
        $factor = match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1
        };

        return (int) $value * $factor;
    }

    private function assertStill(string $bytes, string $mime): void
    {
        if (! in_array($mime, ['image/png', 'image/webp'], true)) {
            return;
        }
        $offset = $mime === 'image/png' ? 8 : 12;
        $chunks = 0;
        while ($offset + ($mime === 'image/png' ? 12 : 8) <= strlen($bytes)) {
            if (++$chunks > 4096) {
                $this->invalid('Website image contains too many chunks.');
            }
            $lengthData = unpack($mime === 'image/png' ? 'Nlength' : 'Vlength', substr($bytes, $offset + ($mime === 'image/png' ? 0 : 4), 4));
            $length = is_array($lengthData) ? $lengthData['length'] : -1;
            $type = substr($bytes, $offset + ($mime === 'image/png' ? 4 : 0), 4);
            $overhead = $mime === 'image/png' ? 12 : 8;
            if (! is_int($length) || $length < 0 || $length > strlen($bytes) - $offset - $overhead) {
                $this->invalid('Website image chunk is malformed.');
            }
            if (in_array($type, ['acTL', 'ANIM', 'ANMF'], true)
                || ($type === 'VP8X' && $length > 0 && (ord($bytes[$offset + 8]) & 2) !== 0)) {
                $this->invalid('Animated website images are not supported.');
            }
            $offset += $length + $overhead + ($mime === 'image/webp' ? $length % 2 : 0);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['media' => $message]);
    }
}
