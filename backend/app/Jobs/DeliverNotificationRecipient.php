<?php

namespace App\Jobs;

use App\Domain\Notifications\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverNotificationRecipient implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $channel,
        public readonly string $recipient,
    ) {
        $this->onQueue((string) config('notifications.outbox.queue', 'notifications'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', "{$this->channel}:{$this->recipient}");
    }

    public function handle(NotificationDeliveryService $delivery): void
    {
        $delivery->deliverRecipient($this->channel, $this->recipient);
    }
}
