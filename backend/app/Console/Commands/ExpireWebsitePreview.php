<?php

namespace App\Console\Commands;

use App\Domain\Website\WebsitePreviewGuard;
use Illuminate\Console\Command;
use Throwable;

class ExpireWebsitePreview extends Command
{
    protected $signature = 'website:preview-expire';

    protected $description = 'Expire due test requests only after validating the isolated preview database and outbound guards';

    public function handle(WebsitePreviewGuard $guard): int
    {
        try {
            $guard->verifyRuntime();

            return $this->call('bookings:expire-pending', ['--limit' => 100]);
        } catch (Throwable) {
            $this->components->error('Preview expiry refused; no production operation is allowed.');

            return self::FAILURE;
        }
    }
}
