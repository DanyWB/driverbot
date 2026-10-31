<?php

namespace Tests\Feature;

use App\Domain\Website\PreviewFilesystemBoundary;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Tests\TestCase;

class PreviewFilesystemBoundaryTest extends TestCase
{
    public function test_directory_tree_accepts_only_exact_existing_unlinked_children(): void
    {
        $base = realpath(storage_path('framework/testing'));
        $this->assertIsString($base);
        $root = $base.DIRECTORY_SEPARATOR.'preview-boundary-'.bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root.'/sessions');
        mkdir($root.'/cache');
        try {
            app(PreviewFilesystemBoundary::class)->verify($root, ['sessions', 'cache']);
            $this->addToAssertionCount(1);
            foreach (['missing', 'sessions/../cache'] as $child) {
                try {
                    app(PreviewFilesystemBoundary::class)->verify($root, [$child]);
                    $this->fail('Invalid preview child was accepted.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            if (is_dir($root) && realpath($root) === $root && str_starts_with($root, $base.DIRECTORY_SEPARATOR)) {
                (new Filesystem)->deleteDirectory($root);
            }
        }
    }
}
