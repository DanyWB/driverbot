<?php

declare(strict_types=1);

use App\Domain\Administration\Services\TelegramPasswordRecovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake();
Http::preventStrayRequests();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$wait = max(0, (int) (((float) $argv[1] - microtime(true)) * 1_000_000));
if ($wait > 0) {
    usleep($wait);
}
try {
    echo $app->make(TelegramPasswordRecovery::class)->reset($input);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class);
    exit(1);
}
