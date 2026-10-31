<?php

namespace App\Domain\Website;

use RuntimeException;

class PreviewFilesystemBoundary
{
    /** @param list<string> $children */
    public function verify(string $root, array $children): void
    {
        $root = str_replace('\\', '/', $root);
        $resolvedRoot = realpath($root);
        if (! is_dir($root) || is_link($root) || $resolvedRoot === false || str_replace('\\', '/', $resolvedRoot) !== $root) {
            throw new RuntimeException('Preview filesystem root is missing or linked.');
        }
        foreach ($children as $child) {
            $path = $root.'/'.$child;
            $resolved = realpath($path);
            if (! is_dir($path) || is_link($path) || $resolved === false || str_replace('\\', '/', $resolved) !== $path || ! str_starts_with(str_replace('\\', '/', $resolved), $root.'/')) {
                throw new RuntimeException('Preview filesystem child escapes the isolated root.');
            }
        }
    }
}
