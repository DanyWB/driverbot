<?php

declare(strict_types=1);

use App\Domain\Administration\Exceptions\AdminTelegramBindingException;
use App\Domain\Administration\Services\AdminTelegramBindingService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $code, $telegramId, $barrier] = $argv;
$waitMicroseconds = max(0, (int) (((float) $barrier - microtime(true)) * 1_000_000));

if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

try {
    $mutation = $app->make(AdminTelegramBindingService::class)->connect([
        'code' => $code,
        'telegram_id' => $telegramId,
        'chat_id' => $telegramId,
        'chat_type' => 'private',
        'username' => 'concurrency_admin',
        'first_name' => 'Concurrency',
        'last_name' => 'Admin',
        'locale' => 'en',
    ]);

    echo json_encode([
        'status' => 200,
        'binding_id' => (int) $mutation->binding->id,
        'generation' => (int) $mutation->binding->generation,
    ], JSON_THROW_ON_ERROR);
} catch (AdminTelegramBindingException $exception) {
    echo json_encode([
        'status' => $exception->httpStatus,
        'error_code' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    echo json_encode(['status' => 500], JSON_THROW_ON_ERROR);
}
