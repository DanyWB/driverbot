<?php

namespace App\Console\Commands;

use App\Domain\Website\WebsitePreviewGuard;
use App\Domain\Website\WebsitePreviewImporter;
use Illuminate\Console\Command;
use Throwable;

class CheckWebsitePreview extends Command
{
    protected $signature = 'website:preview-check';

    protected $description = 'Verify isolated website preview configuration, database identity, marker and absence of notification recipients';

    public function handle(WebsitePreviewGuard $guard, WebsitePreviewImporter $importer): int
    {
        try {
            $guard->verifyRuntime();
            $proof = $importer->verifyExisting();
            $this->line(json_encode(['preview' => true, 'environment' => app()->environment(), 'database' => 'drive_phangan_preview', 'snapshot_sha256' => config('website.preview.snapshot_sha256'), 'outbound' => 'blocked', ...$proof], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Preview validation failed. No production operation is allowed.');

            return self::FAILURE;
        }
    }
}
