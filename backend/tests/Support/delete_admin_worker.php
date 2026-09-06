<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AdminAccountService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $userId, $barrier] = $argv;
$waitMicroseconds = max(0, (int) (((float) $barrier - microtime(true)) * 1_000_000));

if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

try {
    $user = User::query()->findOrFail((int) $userId);
    $app->make(AdminAccountService::class)->delete($user);

    echo json_encode(['result' => 'deleted'], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['result' => 'blocked'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    echo json_encode(['result' => 'error'], JSON_THROW_ON_ERROR);
}
