<?php

namespace App\Domain\Website\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** All operations are restricted to one generated UUID under the private disk. */
class WebsiteMediaStorage
{
    public const DISK = 'private';

    private ?string $resolvedRoot = null;

    public function claim(string $id): void
    {
        $base = $this->base();
        $directory = $base.'/'.$this->id($id);
        if (file_exists($directory) || is_link($directory) || ! @mkdir($directory, 0700)) {
            throw new RuntimeException('Website media directory already exists or cannot be allocated.');
        }
        $this->canonical($directory);
    }

    public function write(string $id, string $path, string $contents): void
    {
        $absolute = $this->path($id, $path, true);
        $handle = @fopen($absolute, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Website media could not be stored without overwriting an existing file.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Website media write was incomplete.');
            }
        } finally {
            fclose($handle);
        }
        $this->canonical($absolute);
    }

    public function read(string $id, string $path, int $maxBytes): string
    {
        if ($maxBytes < 1 || $maxBytes > 8 * 1024 * 1024) {
            throw new RuntimeException('Website media read bound is invalid.');
        }
        $absolute = $this->path($id, $path);
        if (filesize($absolute) > $maxBytes) {
            throw new RuntimeException('Website media exceeds the bounded read limit.');
        }
        $contents = file_get_contents($absolute, false, null, 0, $maxBytes + 1);
        if (! is_string($contents) || strlen($contents) > $maxBytes) {
            throw new RuntimeException('Website media failed its bounded read.');
        }

        return $contents;
    }

    /** @param list<string> $paths */
    public function delete(string $id, array $paths): void
    {
        $this->assertPaths($id, $paths);
        foreach ($paths as $path) {
            $absolute = $this->path($id, $path, true);
            if (is_file($absolute) && ! unlink($absolute)) {
                throw new RuntimeException('Private website media cleanup failed; retry the confined cleanup.');
            }
        }
        // Nonrecursive: never remove another asset or unexpected files.
        @rmdir($this->base().'/'.$this->id($id));
    }

    /** @param list<string> $paths */
    public function assertPaths(string $id, array $paths): void
    {
        if (count($paths) > 4) {
            throw new RuntimeException('Unexpected website media deletion count.');
        }
        foreach ($paths as $path) {
            $this->path($id, $path, true);
        }
    }

    private function path(string $id, string $path, bool $missingAllowed = false): string
    {
        $id = $this->id($id);
        if (! preg_match('~^website-media/'.preg_quote($id, '~').'/(?:original\.(?:jpg|png|webp)|card\.webp|card_2x\.webp|detail\.webp)$~D', $path)) {
            throw new RuntimeException('Website media path escapes its generated asset.');
        }
        $base = $this->base();
        $directory = $base.'/'.$id;
        if (file_exists($directory) || is_link($directory)) {
            $this->canonical($directory);
        } elseif (! $missingAllowed) {
            throw new RuntimeException('Website media directory is missing.');
        }
        $absolute = dirname($base).'/'.$path;
        if (file_exists($absolute) || is_link($absolute)) {
            if (! is_file($absolute)) {
                throw new RuntimeException('Website media is not a regular file.');
            }
            $this->canonical($absolute);
        } elseif (! $missingAllowed) {
            throw new RuntimeException('Website media file is missing.');
        }

        return $absolute;
    }

    private function base(): string
    {
        // The configured storage root is trusted and may traverse the deployment's
        // shared-storage symlink. Pin its resolved target; links below it are not trusted.
        $configured = $this->disk()->path('');
        $real = realpath($configured);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Private website media root is missing.');
        }
        $root = rtrim(str_replace('\\', '/', $real), '/');
        if ($this->resolvedRoot !== null && $this->resolvedRoot !== $root) {
            throw new RuntimeException('Private website media root changed during this operation.');
        }
        $public = $this->resolveConfiguredPath(Storage::disk('public')->path(''));
        if ($root === '' || $root === $public || str_starts_with($root, $public.'/')) {
            throw new RuntimeException('Website media private disk cannot point inside the public disk.');
        }
        $this->resolvedRoot = $root;
        $base = $root.'/website-media';
        if (! file_exists($base) && ! is_link($base) && ! @mkdir($base, 0700) && ! is_dir($base)) {
            throw new RuntimeException('Private website media base cannot be allocated.');
        }
        $this->canonical($base);

        return $base;
    }

    private function resolveConfiguredPath(string $path): string
    {
        $suffix = '';
        while (($real = realpath($path)) === false) {
            $parent = dirname($path);
            if ($parent === $path) {
                throw new RuntimeException('Configured public disk boundary cannot be resolved.');
            }
            $suffix = '/'.basename($path).$suffix;
            $path = $parent;
        }

        return rtrim(str_replace('\\', '/', $real), '/').$suffix;
    }

    private function canonical(string $path): void
    {
        $real = realpath($path);
        if (is_link($path) || $real === false || str_replace('\\', '/', $real) !== $path) {
            throw new RuntimeException('Website media path is missing or linked.');
        }
    }

    private function id(string $id): string
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
            throw new RuntimeException('Website media requires a generated UUID.');
        }

        return $id;
    }

    private function disk(): FilesystemAdapter
    {
        $disk = Storage::disk(self::DISK);
        if (config('filesystems.disks.private.driver') !== 'local' || config('filesystems.disks.private.serve') !== false) {
            throw new RuntimeException('Website media requires the nonserved local private disk.');
        }

        return $disk;
    }
}
