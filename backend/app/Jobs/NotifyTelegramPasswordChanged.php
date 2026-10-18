<?php

namespace App\Jobs;

use App\Domain\Administration\Services\TelegramPasswordRecovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyTelegramPasswordChanged implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $adminId, public int $bindingId, public int $generation)
    {
        $this->onQueue('notifications');
    }

    public function handle(TelegramPasswordRecovery $recovery): void
    {
        $recovery->notifyChanged($this->adminId, $this->bindingId, $this->generation);
    }
}
