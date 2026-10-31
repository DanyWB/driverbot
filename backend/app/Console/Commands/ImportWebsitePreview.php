<?php

namespace App\Console\Commands;

use App\Domain\Website\WebsitePreviewImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportWebsitePreview extends Command
{
    protected $signature = 'website:preview-import';

    protected $description = 'Initialize only the explicitly isolated, hash-bound PostgreSQL website preview';

    public function handle(WebsitePreviewImporter $importer): int
    {
        try {
            $this->line(json_encode($importer->import(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Preview import refused; inspect the reviewed input/configuration guards. No production operation is allowed.');

            return self::FAILURE;
        }
    }
}
