<?php

namespace App\Jobs;

use App\Domain\Administration\Services\TelegramPasswordRecovery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramPasswordRecovery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $email, public int $requestedAt)
    {
        $this->onQueue('notifications');
    }

    public function handle(TelegramPasswordRecovery $recovery): void
    {
        $recovery->deliver($this->email, $this->requestedAt);
    }
}
